<?php
require 'config.php';
require_once __DIR__ . '/lib_money.php';
$me = require_login();

// ── Business profit helper (Revenue − all expenses, net of discount) ──
function business_profit(mysqli $conn): array {
    // Revenue is orders PLUS money that arrived as account credits from
    // channels with no order book -- a Meesho payout is a sale too.
    // revenue_sources() is the single definition of that, shared with the
    // app's API so the two cannot drift; it is careful not to
    // double-count credits that are order money being moved about.
    $src = revenue_sources($conn);
    $rev = (float)$src['total'];
    $exp = (float)($conn->query("SELECT COALESCE(SUM(amount - discount),0) v FROM expenses")->fetch_assoc()['v'] ?? 0);
    $distributed = (float)($conn->query(
        "SELECT COALESCE(SUM(amount),0) v FROM account_movements WHERE kind = 'profit'"
    )->fetch_assoc()['v'] ?? 0);
    $profit = round($rev - $exp, 2);
    return [
        'revenue'     => $rev,
        'revenue_orders' => $src['orders'],
        'revenue_other'  => $src['other'],
        'sources'        => $src,
        'expenses'    => $exp,
        'profit'      => $profit,
        'distributed' => round($distributed, 2),
        'remaining'   => round($profit - $distributed, 2),
    ];
}

// ── Distribute undistributed profit equally to investing partners ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'distribute_profit') {
    csrf_check();
    try {
        $bp = business_profit($conn);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        if ($amount <= 0) throw new Exception('Enter an amount to distribute.');
        if ($amount - $bp['remaining'] > 0.01) {
            throw new Exception('That is more than the undistributed profit (' . money($bp['remaining']) . ').');
        }

        // All active partners share profit equally (4-way equity), whether or
        // not they have invested yet.
        $elig = $conn->query(
            "SELECT id, name FROM partners WHERE is_active = 1 ORDER BY name"
        )->fetch_all(MYSQLI_ASSOC);

        $n = count($elig);
        if ($n === 0) throw new Exception('No active partners to distribute to.');

        // Equal split; distribute the rounding remainder to the last partner
        // so the credited total exactly equals the amount.
        $each = floor($amount / $n * 100) / 100;
        $credited = 0.0;
        $today = date('Y-m-d');

        $conn->begin_transaction();
        $s = $conn->prepare(
            "INSERT INTO account_movements (mov_date, partner_id, direction, kind, amount, source, note)
             VALUES (?,?,'credit','profit',?,?,?)"
        );
        foreach ($elig as $i => $p) {
            $share = ($i === $n - 1) ? round($amount - $credited, 2) : $each;
            $credited += $share;
            $src = 'Profit distribution';
            $note = 'Equal ' . $n . '-way split';
            $pid = (int)$p['id'];
            $s->bind_param('sidss', $today, $pid, $share, $src, $note);
            $s->execute();
        }
        $s->close();
        $conn->commit();
        flash('Distributed ' . money($amount) . ' equally to ' . $n . ' partner(s).');
    } catch (Exception $ex) {
        @$conn->rollback();
        flash($ex->getMessage(), 'error');
    }
    header('Location: investment.php');
    exit;
}

// ── Gather the three sums per partner ──────────────────────────────
// Paid     = pocket expenses by partner
// Credited = credit movements to partner
// Debited  = debit movements from partner
// Net invested   = Paid - Credited + Debited
// Account balance = Credited - Debited
$partners = $conn->query(
    'SELECT p.id, p.name, p.is_active,
       (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE partner_id = p.id) AS paid,
       (SELECT COALESCE(SUM(amount),0) FROM account_movements
          WHERE partner_id = p.id AND direction = "credit") AS credited,
       (SELECT COALESCE(SUM(amount),0) FROM account_movements
          WHERE partner_id = p.id AND direction = "debit")  AS debited,
       (SELECT COALESCE(SUM(invest_adjust),0) FROM account_movements
          WHERE partner_id = p.id) AS invest_adj
     FROM partners p ORDER BY p.name'
)->fetch_all(MYSQLI_ASSOC);

$rows = [];
$T = ['paid'=>0.0,'credited'=>0.0,'debited'=>0.0,'net'=>0.0,'bal'=>0.0,'adj'=>0.0,'rem'=>0.0];
foreach ($partners as $p) {
    $paid = (float)$p['paid']; $cr = (float)$p['credited']; $db = (float)$p['debited'];
    $adj  = (float)$p['invest_adj'];
    // Net invested = what the partner has actually put in and not had back:
    //   paid - credited + adj
    // Debited is deliberately NOT added back. A debit is money leaving an
    // account that was already credited; adding it would cancel the credit and
    // count the same money twice, making net invested equal paid for anyone who
    // has withdrawn their credits. Settlement adjustments ARE included, since a
    // settle-up genuinely changes what a partner has net contributed.
    // Account balance does NOT include adj (those rows carry amount=0, so
    // credited/debited are unaffected).
    $net = $paid - $cr + $adj;
    $bal = $cr - $db;
    // Skip partners with no activity to keep the table clean, unless active.
    if ($paid==0 && $cr==0 && $db==0 && $adj==0 && !$p['is_active']) continue;
    // Remaining = money the partner has put in that has not come back to them
    // yet, ignoring settlements. Shown in the per-partner table as the plain
    // "paid minus credited" figure behind the fair-share maths.
    $rem = round($paid - $cr, 2);
    $rows[] = ['name'=>$p['name'],'paid'=>$paid,'credited'=>$cr,'debited'=>$db,
               'adj'=>$adj,'net'=>$net,'bal'=>$bal,'rem'=>$rem];
    $T['paid']+=$paid; $T['credited']+=$cr; $T['debited']+=$db; $T['net']+=$net;
    $T['bal']+=$bal; $T['adj']+=$adj; $T['rem']+=$rem;
}

// ── Equal-share fairness ────────────────────────────────────────────
// Split equally among ALL partners shown (e.g. 4-way, 25% each), including
// partners who have invested nothing yet — they simply show as owing their
// full share. Fair share = total ÷ number of partners.
//
// Basis = Contribution = paid - credited + adj.
//   paid     = money the partner put in from their own pocket
//   credited = business/sale money that has come back to the partner
//   adj      = investment settle-up transfers between partners (invest_adjust)
// A credit reduces the partner's outstanding contribution as soon as it is
// credited, whether or not they have spent it yet - otherwise a partner who
// has spent their credits is penalised versus one holding the same amount
// unspent. Debits are ignored here: a debit is money going out of an account
// that was already credited, so subtracting it too would double-count.
// adj is included so that settling up actually closes the gap: when a partner
// hands cash to another to even out, the payer's contribution rises and the
// receiver's falls. Without it the gaps could never reach zero and the
// settle-up plan would repeat the same advice forever.
// Settlement rows are equal and opposite, so SUM(adj) across partners is 0
// and the total contribution is unchanged by settling.
//
// The single "Contribution gap" is shown broken into its two components so
// partners can see where it comes from:
//   inv_gap    = paid     - (total paid     / n)   money-in position
//   cred_gap   = (total credited / n) - credited   credit position
//   adj_gap    = adj                               settle-up transfers
//   gap        = inv_gap + cred_gap + adj_gap      = contrib - fairShare
// cred_gap is deliberately (fair - credited), NOT (credited - fair): a partner
// who has drawn LESS than their quarter of the credits is OWED that money, so
// it counts in their favour. The two components always sum to the net gap.
$nPart = count($rows);
$T['contrib'] = 0.0;
foreach ($rows as &$r) {
    $r['contrib'] = round($r['paid'] - $r['credited'] + $r['adj'], 2);
    $T['contrib'] += $r['contrib'];
}
unset($r);
$fairShare = $nPart > 0 ? round($T['contrib'] / $nPart, 2) : 0.0;
// Component fair shares: paid split n ways, credited split n ways.
$fairPaid = $nPart > 0 ? round($T['paid'] / $nPart, 2) : 0.0;
$fairCred = $nPart > 0 ? round($T['credited'] / $nPart, 2) : 0.0;
// Percentage label reused by both the equal-share and account-balance tables.
$pctLabel = $nPart > 0 ? round(100 / $nPart) . '%' : '';
// Account balance also split equally: each partner's 25% share of the
// business money currently sitting in accounts.
$fairBal   = $nPart > 0 ? round($T['bal'] / $nPart, 2) : 0.0;
foreach ($rows as &$r) {
    $r['fair']     = $fairShare;
    $r['gap']      = round($r['contrib'] - $fairShare, 2);
    // Breakdown components. Computed from the same figures as $gap; any
    // rounding drift is pushed into inv_gap so the two always sum to $gap.
    $r['cred_gap'] = round($fairCred - $r['credited'], 2);
    $r['adj_gap']  = round($r['adj'], 2);
    $r['inv_gap']  = round($r['gap'] - $r['cred_gap'] - $r['adj_gap'], 2);
    $r['fair_bal'] = $fairBal;
    $r['bal_gap']  = round($r['bal'] - $fairBal, 2);
}
unset($r);

// ── Settle-up plans (fewest transfers to even everyone out) ─────────
// Underpaid partners (negative gap) pay overpaid ones (positive gap).
// Greedy matching gives the fewest transfers. Used for both the investment
// gap and the account-balance gap.
function settle_up(array $rows, string $gapKey, bool $flip = false): array {
    // Normally: negative gap = debtor (pays), positive gap = creditor (receives).
    // When $flip: positive gap = payer (holds extra, pays out), negative = receiver.
    $debtors = []; $creditors = [];
    foreach ($rows as $r) {
        $g = $flip ? -$r[$gapKey] : $r[$gapKey];
        if ($g < -0.01)     $debtors[]   = ['name'=>$r['name'], 'amt'=>-$g];
        elseif ($g > 0.01)  $creditors[] = ['name'=>$r['name'], 'amt'=>$g];
    }
    $plan = []; $di = 0; $ci = 0;
    while ($di < count($debtors) && $ci < count($creditors)) {
        $pay = round(min($debtors[$di]['amt'], $creditors[$ci]['amt']), 2);
        if ($pay > 0.01) {
            $plan[] = ['from'=>$debtors[$di]['name'], 'to'=>$creditors[$ci]['name'], 'amt'=>$pay];
        }
        $debtors[$di]['amt']   = round($debtors[$di]['amt']   - $pay, 2);
        $creditors[$ci]['amt'] = round($creditors[$ci]['amt'] - $pay, 2);
        if ($debtors[$di]['amt']   <= 0.01) $di++;
        if ($creditors[$ci]['amt'] <= 0.01) $ci++;
    }
    return $plan;
}
$settlePlan    = settle_up($rows, 'gap');           // investment: underpaid pays overpaid
$balSettlePlan = settle_up($rows, 'bal_gap', true); // balance: holds-extra pays the short


// ── Business profit ─────────────────────────────────────────────────
$bp = business_profit($conn);
// Active partners share profit equally (matches the distribution handler).
$nProfit = (int)($conn->query("SELECT COUNT(*) c FROM partners WHERE is_active = 1")->fetch_assoc()['c'] ?? 0);

// ── Category breakdown (expenses only) ─────────────────────────────
$cats = [];
$res = $conn->query('SELECT category, COALESCE(SUM(amount - discount),0) t, COUNT(*) n
                     FROM expenses GROUP BY category ORDER BY t DESC');
while ($r = $res->fetch_assoc()) $cats[] = $r;
$catTotal = 0.0; foreach ($cats as $c) $catTotal += (float)$c['t'];

// ── CSV export ─────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=investment-summary-' . date('Y-m-d-Hi') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Partner','Paid','Credited','Debited','Net invested','Account balance']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'],
            number_format($r['paid'],2,'.',''), number_format($r['credited'],2,'.',''),
            number_format($r['debited'],2,'.',''), number_format($r['net'],2,'.',''),
            number_format($r['bal'],2,'.','')]);
    }
    fputcsv($out, ['TOTAL',
        number_format($T['paid'],2,'.',''), number_format($T['credited'],2,'.',''),
        number_format($T['debited'],2,'.',''), number_format($T['net'],2,'.',''),
        number_format($T['bal'],2,'.','')]);
    fputcsv($out, []);
    fputcsv($out, ['Category','Count','Total']);
    foreach ($cats as $c) fputcsv($out, [$c['category'], $c['n'], number_format((float)$c['t'],2,'.','')]);
    fclose($out);
    $conn->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Investment Summary · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:4px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px}
  .stat .l{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:23px;font-weight:600;margin-top:4px}.green{color:#1a7f4b}.red{color:#c0392b}.amber{color:#a06a00}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:10px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  .r{text-align:right}.scroll{overflow-x:auto}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .bar{height:8px;border-radius:4px;background:#e7ecf3;overflow:hidden;margin-top:6px}
  .bar>span{display:block;height:100%;background:#1877f2}
  .muted{color:#8a8d91}
</style>
</head>
<body>
<?php
  $PAGE  = 'investment';
  $TITLE = 'Investment summary';
  require 'layout.php';
?>

  <div class="stats">
    <div class="stat"><div class="l">Total net invested</div><div class="v"><?= money($T['net']) ?></div></div>
    <div class="stat"><div class="l">Total paid (pocket)</div><div class="v"><?= money($T['paid']) ?></div></div>
    <div class="stat"><div class="l">Remaining (paid − credited)</div><div class="v"><?= money($T['rem']) ?></div>
      <div class="l" style="margin-top:4px;font-size:11px"><span style="color:green;"><?= money($T['paid']) ?></span> − <span style="color:red;"><?= money($T['credited']) ?></span></div></div>
    <div class="stat"><div class="l">In accounts (balance)</div><div class="v green"><?= money($T['bal']) ?></div></div>
  </div>

  <div class="card">
    <h2>Per-partner breakdown</h2>
    <p class="desc">Remaining = Paid − Credited (money put in that has not come back yet). &nbsp;
       Net invested = Remaining + settle-up adjustments. &nbsp; Account balance = Credited − Debited.</p>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Partner</th><th class="r">Paid</th><th class="r">Credited</th><th class="r">Debited</th>
              <th class="r">Remaining</th><th class="r">Net invested</th><th class="r">Account balance</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="muted" style="padding:18px 8px">No data yet. Add partners, expenses and movements.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="r"><?= money($r['paid']) ?></td>
            <td class="r"><?= money($r['credited']) ?></td>
            <td class="r"><?= money($r['debited']) ?></td>
            <td class="r"><?= money($r['rem']) ?></td>
            <td class="r"><strong><?= money($r['net']) ?></strong></td>
            <td class="r <?= $r['bal']>=0?'green':'red' ?>"><?= money($r['bal']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td>
            <td class="r"><?= money($T['paid']) ?></td>
            <td class="r"><?= money($T['credited']) ?></td>
            <td class="r"><?= money($T['debited']) ?></td>
            <td class="r"><?= money($T['rem']) ?></td>
            <td class="r"><?= money($T['net']) ?></td>
            <td class="r <?= $T['bal']>=0?'green':'red' ?>"><?= money($T['bal']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <a class="btn primary" style="margin-top:14px" href="investment.php?export=csv">Export CSV</a>
  </div>

  <!-- ── EQUAL-SHARE FAIRNESS ─────────────────────────────────── -->
  <div class="card">
    <h2>Equal share &amp; settle-up</h2>
    <p class="desc">Each partner should carry a <?= $pctLabel ?> share of both sides.
       <strong>Investment gap</strong> compares what they paid from pocket against
       <strong style="color: red;"><?= money($fairPaid) ?></strong> (total paid <?= money($T['paid']) ?> ÷ <?= $nPart ?>).
       <strong>Credit gap</strong> compares the sale money credited to them against
       <strong style="color: green;"><?= money($fairCred) ?></strong> (total credited <?= money($T['credited']) ?> ÷ <?= $nPart ?>) —
       a partner who has drawn less than their share is owed the difference, so it counts
       in their favour. <strong>Settle-up</strong> is cash already transferred between
       partners to even things out — it moves the payer up and the receiver down, so gaps
       actually close as you settle. The three add up to the <strong>Net</strong>, which the
       settle-up plan below uses.</p>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Partner</th>
            <th class="r">Paid</th>
            <th class="r">Investment gap</th>
            <th class="r">Credited</th>
            <th class="r">Credit gap</th>
            <th class="r">Settle-up</th>
            <th class="r">Net</th>
            <th>Position</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="r"><?= money($r['paid']) ?></td>
            <td class="r <?= $r['inv_gap']>=0?'green':'red' ?>"><?= ($r['inv_gap']>=0?'+':'−') . money(abs($r['inv_gap'])) ?></td>
            <td class="r"><?= money($r['credited']) ?></td>
            <td class="r <?= $r['cred_gap']>=0?'green':'red' ?>"><?= ($r['cred_gap']>=0?'+':'−') . money(abs($r['cred_gap'])) ?></td>
            <td class="r <?= $r['adj_gap']>=0?'green':'red' ?>"><?= abs($r['adj_gap'])<0.01 ? '<span class="muted">—</span>' : (($r['adj_gap']>=0?'+':'−') . money(abs($r['adj_gap']))) ?></td>
            <td class="r <?= $r['gap']>=0?'green':'red' ?>"><strong><?= ($r['gap']>=0?'+':'−') . money(abs($r['gap'])) ?></strong></td>
            <td>
              <?php if (abs($r['gap']) < 0.01): ?><span class="muted">even</span>
              <?php elseif ($r['gap'] > 0): ?><span style="color:#1a7f4b">overpaid — owed <?= money($r['gap']) ?></span>
              <?php else: ?><span style="color:#c0392b">underpaid — owes <?= money(abs($r['gap'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td><strong>Total</strong></td>
            <td class="r"><strong><?= money($T['paid']) ?></strong></td>
            <td class="r"><strong><?= money(0) ?></strong></td>
            <td class="r"><strong><?= money($T['credited']) ?></strong></td>
            <td class="r"><strong><?= money(0) ?></strong></td>
            <td class="r"><strong><?= money($T['adj']) ?></strong></td>
            <td class="r"><strong><?= money(0) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="desc" style="margin-top:10px">Each gap column sums to zero across partners.
       Overpaid and underpaid amounts net to zero. To even up, an underpaid partner
       pays an overpaid one — record it on the <a href="movements.php">Account movements</a> page under
       “Partner → partner settlement”, and these differences will update.</p>

    <!-- ── WORKED CALCULATION ────────────────────────────────────── -->
    <div class="sect" style="margin-top:16px;padding-top:14px;border-top:1px solid #eceef0">
      <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#65676b;margin-bottom:4px">How each net is worked out</h3>
      <p class="desc" style="margin-bottom:12px">The same numbers as the table above, shown step by step.
         Nothing new — just the arithmetic behind each partner's net position.</p>
      <?php foreach ($rows as $r): $hasAdj = abs($r['adj_gap']) >= 0.01; ?>
        <div style="margin-bottom:14px;padding:11px 13px;background:#f7f8f9;border-radius:8px;border-left:3px solid <?= $r['gap']>=0 ? '#1a7f4b' : '#c0392b' ?>">
          <div style="font-weight:600;font-size:13px;margin-bottom:7px"><?= e($r['name']) ?></div>
          <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.9;color:#3a3d42">
            <div>
              <span style="color:#65676b">Investment gap:</span>
              <?= money($r['paid']) ?> &minus; <?= money($fairPaid) ?>
              = <strong style="color:<?= $r['inv_gap']>=0 ? '#1a7f4b' : '#c0392b' ?>"><?= ($r['inv_gap']>=0?'+':'−') . money(abs($r['inv_gap'])) ?></strong>
            </div>
            <div>
              <span style="color:#65676b">Credit gap:</span>
              <?= money($fairCred) ?> &minus; <?= money($r['credited']) ?>
              = <strong style="color:<?= $r['cred_gap']>=0 ? '#1a7f4b' : '#c0392b' ?>"><?= ($r['cred_gap']>=0?'+':'−') . money(abs($r['cred_gap'])) ?></strong>
            </div>
            <?php if ($hasAdj): ?>
            <div>
              <span style="color:#65676b">Settle-up:</span>
              <strong style="color:<?= $r['adj_gap']>=0 ? '#1a7f4b' : '#c0392b' ?>"><?= ($r['adj_gap']>=0?'+':'−') . money(abs($r['adj_gap'])) ?></strong>
            </div>
            <?php endif; ?>
            <div style="border-top:1px solid #dfe1e4;margin-top:5px;padding-top:5px">
              <span style="color:#65676b">Net:</span>
              <?= ($r['inv_gap']>=0?'+':'−') . money(abs($r['inv_gap'])) ?>
              <?= $r['cred_gap']>=0 ? '+' : '−' ?> <?= money(abs($r['cred_gap'])) ?>
              <?php if ($hasAdj): ?><?= $r['adj_gap']>=0 ? '+' : '−' ?> <?= money(abs($r['adj_gap'])) ?><?php endif; ?>
              = <strong style="color:<?= $r['gap']>=0 ? '#1a7f4b' : '#c0392b' ?>"><?= ($r['gap']>=0?'+':'−') . money(abs($r['gap'])) ?></strong>
              <span style="color:#65676b">— <?= abs($r['gap'])<0.01 ? 'even' : ($r['gap']>0 ? 'owed' : 'owes') ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <p class="desc" style="margin-top:2px">Fair shares: total paid <?= money($T['paid']) ?> ÷ <?= $nPart ?>
         = <strong><?= money($fairPaid) ?></strong> each &nbsp;·&nbsp;
         total credited <?= money($T['credited']) ?> ÷ <?= $nPart ?>
         = <strong><?= money($fairCred) ?></strong> each.</p>
    </div>

    <?php if ($settlePlan): ?>
      <div class="sect" style="margin-top:16px;padding-top:14px;border-top:1px solid #eceef0">
        <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#65676b;margin-bottom:10px">Settle-up plan</h3>
        <p class="desc" style="margin-bottom:10px">The fewest transfers that would bring everyone to their fair share of <?= money($fairShare) ?>:</p>
        <div class="scroll">
          <table>
            <thead><tr><th>Pays</th><th></th><th>Receives</th><th class="r">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($settlePlan as $t): ?>
              <tr>
                <td style="color:#c0392b;font-weight:500"><?= e($t['from']) ?></td>
                <td style="text-align:center;color:#8a8d91">→</td>
                <td style="color:#1a7f4b;font-weight:500"><?= e($t['to']) ?></td>
                <td class="r"><strong><?= money($t['amt']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="desc" style="margin-top:10px">Record each of these on the <a href="movements.php">Account movements</a> page
           under “Partner → partner settlement” (From = the payer, To = the receiver). Once recorded, that partner's
           credit/debit updates and the differences here shrink to zero.</p>
      </div>
    <?php elseif ($nPart > 0 && abs($T['net']) > 0.01): ?>
      <p class="desc" style="margin-top:12px;color:#1a7f4b">Everyone is at their fair share — nothing to settle. </p>
    <?php endif; ?>
  </div>

  <!-- ── ACCOUNT BALANCE SHARE ────────────────────────────────── -->
  <div class="card">
    <h2>Account balance — equal share</h2>
    <p class="desc">Business money currently in partner accounts totals <strong><?= money($T['bal']) ?></strong>.
       Split <?= $pctLabel ?> each, every partner's share is <strong><?= money($fairBal) ?></strong>.
       This shows who is holding more or less than their share.</p>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Partner</th><th class="r">Account balance</th><th class="r">Fair share (<?= $pctLabel ?>)</th><th class="r">Difference</th><th>Position</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['name']) ?></td>
            <td class="r <?= $r['bal']>=0?'':'red' ?>"><?= money($r['bal']) ?></td>
            <td class="r"><?= money($r['fair_bal']) ?></td>
            <td class="r <?= $r['bal_gap']>=0?'green':'red' ?>"><?= ($r['bal_gap']>=0?'+':'−') . money(abs($r['bal_gap'])) ?></td>
            <td>
              <?php if (abs($r['bal_gap']) < 0.01): ?><span class="muted">even</span>
              <?php elseif ($r['bal_gap'] > 0): ?><span style="color:#1a7f4b">holds <?= money($r['bal_gap']) ?> extra</span>
              <?php else: ?><span style="color:#c0392b">short by <?= money(abs($r['bal_gap'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($balSettlePlan): ?>
      <div class="sect" style="margin-top:16px;padding-top:14px;border-top:1px solid #eceef0">
        <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#65676b;margin-bottom:10px">Settle-up plan</h3>
        <p class="desc" style="margin-bottom:10px">The fewest transfers that would bring everyone to their fair share of <?= money($fairBal) ?> in account balance:</p>
        <div class="scroll">
          <table>
            <thead><tr><th>Pays</th><th></th><th>Receives</th><th class="r">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($balSettlePlan as $t): ?>
              <tr>
                <td style="color:#c0392b;font-weight:500"><?= e($t['from']) ?></td>
                <td style="text-align:center;color:#8a8d91">→</td>
                <td style="color:#1a7f4b;font-weight:500"><?= e($t['to']) ?></td>
                <td class="r"><strong><?= money($t['amt']) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="desc" style="margin-top:10px">Record each on the <a href="movements.php">Account movements</a> page under
           “Partner → partner settlement” (From = payer, To = receiver). This moves the business money between
           accounts so each partner holds their fair share.</p>
      </div>
    <?php elseif ($nPart > 0 && abs($T['bal']) > 0.01): ?>
      <p class="desc" style="margin-top:12px;color:#1a7f4b">Everyone holds their fair share — nothing to settle. </p>
    <?php endif; ?>
  </div>

  <!-- ── PROFIT DISTRIBUTION ──────────────────────────────────── -->
  <div class="card">
    <h2>Profit &amp; distribution</h2>
    <p class="desc">Business profit = all revenue − all expenses. Revenue counts orders <em>and</em>
       money credited in from channels with no order book (a Meesho payout is a sale too).
       Credits that are order money being moved into an account — offline sales and event
       sales — are not counted twice.
       <?php if (!empty($bp['sources']['by_source'])): ?>
         <br>Other channels:
         <?= e(implode(', ', array_map(fn($x) => $x['source'] . ' ' . money($x['amount']),
                                        $bp['sources']['by_source']))) ?>.
       <?php endif; ?>
       Distributing credits each investing partner an equal share to their account.</p>
    <div class="stats" style="margin-bottom:14px">
      <div class="stat"><div class="l">Revenue (all sales)</div><div class="v"><?= money($bp['revenue']) ?></div>
        <div class="c" style="font-size:11px;color:#65676b">
          <?= money($bp['revenue_orders']) ?> orders + <?= money($bp['revenue_other']) ?> other channels</div></div>
      <div class="stat"><div class="l">Expenses</div><div class="v"><?= money($bp['expenses']) ?></div></div>
      <div class="stat"><div class="l">Business profit</div><div class="v <?= $bp['profit']>=0?'green':'red' ?>"><?= money($bp['profit']) ?></div></div>
      <div class="stat"><div class="l">Already distributed</div><div class="v"><?= money($bp['distributed']) ?></div></div>
      <div class="stat"><div class="l">Undistributed</div><div class="v <?= $bp['remaining']>0?'amber':'muted' ?>"><?= money($bp['remaining']) ?></div></div>
    </div>
    <?php if ($bp['remaining'] > 0.01 && $nProfit > 0): ?>
      <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"
            onsubmit="return confirm('Distribute this amount equally to <?= $nProfit ?> partner(s) as account credits?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="distribute_profit">
        <div style="min-width:180px">
          <label style="font-size:13px;color:#65676b">Amount to distribute (₹)</label>
          <input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($bp['remaining'],2,'.','')) ?>"
                 value="<?= e(number_format($bp['remaining'],2,'.','')) ?>"
                 style="width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px">
          <p class="desc" style="font-size:12px">Each partner gets <?= money(round($bp['remaining']/$nProfit,2)) ?> at the full amount.</p>
        </div>
        <button class="btn primary" style="padding:9px 16px">Distribute equally</button>
      </form>
    <?php elseif ($bp['profit'] <= 0): ?>
      <p class="muted">No profit to distribute yet.</p>
    <?php else: ?>
      <p class="muted">All profit has been distributed. </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Expenses by category</h2>
    <p class="desc">Pocket-funded purchases only — total <?= money($catTotal) ?>.</p>
    <div class="scroll">
      <table>
        <thead><tr><th>Category</th><th class="r">Count</th><th class="r">Total</th><th style="width:30%">Share</th></tr></thead>
        <tbody>
        <?php if (!$cats): ?>
          <tr><td colspan="4" class="muted" style="padding:18px 8px">No expenses recorded.</td></tr>
        <?php endif; ?>
        <?php foreach ($cats as $c): $pct = $catTotal>0 ? (float)$c['t']/$catTotal*100 : 0; ?>
          <tr>
            <td><?= e($c['category']) ?></td>
            <td class="r"><?= (int)$c['n'] ?></td>
            <td class="r"><?= money($c['t']) ?></td>
            <td>
              <div class="bar"><span style="width:<?= number_format($pct,1) ?>%"></span></div>
              <span class="muted" style="font-size:12px"><?= number_format($pct,1) ?>%</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php require 'layout_end.php'; ?>
</body>
</html>