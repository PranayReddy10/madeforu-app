<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

function partner_map(mysqli $conn): array {
    $m = [];
    $res = $conn->query('SELECT id, name, is_active FROM partners ORDER BY is_active DESC, name');
    while ($r = $res->fetch_assoc()) $m[(int)$r['id']] = $r;
    return $m;
}
$partners = partner_map($conn);
// Gaps shown inline in the investment-settlement dropdowns so the payer and
// receiver are obvious at a glance rather than looked up on another page.
$pageGaps = investment_gaps($conn);

/**
 * Current investment gaps for every partner, using the SAME basis as the
 * equal-share table on investment.php:
 *     contribution = paid - credited + invest_adjust
 *     gap          = contribution - (total contribution / partner count)
 * Negative gap = underpaid (owes, and is the one who PAYS a settlement).
 * Positive gap = overpaid  (is owed, and RECEIVES a settlement).
 * Returns [partner_id => gap]. Kept in step with investment.php by design:
 * if the basis changes there, it must change here too.
 */
function investment_gaps(mysqli $conn): array {
    $res = $conn->query(
        'SELECT p.id,
           (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE partner_id = p.id) AS paid,
           (SELECT COALESCE(SUM(amount),0) FROM account_movements
              WHERE partner_id = p.id AND direction = "credit") AS credited,
           (SELECT COALESCE(SUM(invest_adjust),0) FROM account_movements
              WHERE partner_id = p.id) AS invest_adj
         FROM partners p WHERE p.is_active = 1'
    );
    $contrib = []; $total = 0.0;
    while ($r = $res->fetch_assoc()) {
        $c = round((float)$r['paid'] - (float)$r['credited'] + (float)$r['invest_adj'], 2);
        $contrib[(int)$r['id']] = $c;
        $total += $c;
    }
    $n = count($contrib);
    if ($n === 0) return [];
    $fair = round($total / $n, 2);
    $gaps = [];
    foreach ($contrib as $id => $c) $gaps[$id] = round($c - $fair, 2);
    return $gaps;
}

/** Current account balance for a partner = credited - debited. */
function partner_balance(mysqli $conn, int $pid): float {
    $s = $conn->prepare(
        "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) b
         FROM account_movements WHERE partner_id = ?"
    );
    $s->bind_param('i', $pid); $s->execute();
    $b = (float)$s->get_result()->fetch_assoc()['b']; $s->close();
    return round($b, 2);
}

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $date   = $_POST['mov_date'] ?? '';
            $pid    = (int)($_POST['partner_id'] ?? 0);
            $dir    = ($_POST['direction'] ?? '') === 'debit' ? 'debit' : 'credit';
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $src    = trim($_POST['source'] ?? '');
            $note   = trim($_POST['note'] ?? '');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');
            if (!isset($partners[$pid])) throw new Exception('Choose a partner.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            if ($action === 'add') {
                $s = $conn->prepare(
                    'INSERT INTO account_movements (mov_date, partner_id, direction, amount, source, note)
                     VALUES (?,?,?,?,?,?)'
                );
                $s->bind_param('sisdss', $date, $pid, $dir, $amount, $src, $note);
                $s->execute();
                $s->close();
                flash('Movement added.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $s = $conn->prepare(
                    'UPDATE account_movements SET mov_date=?, partner_id=?, direction=?, amount=?, source=?, note=?
                     WHERE id=?'
                );
                $s->bind_param('sisdssi', $date, $pid, $dir, $amount, $src, $note, $id);
                $s->execute();
                $s->close();
                flash('Movement updated.');
            }
        } elseif ($action === 'settle_personal') {
            // A partner takes business money from their account for personal use.
            // Recorded as a debit tagged 'personal'. Warn (not block) if it
            // exceeds their current balance — that just means they now owe the
            // business (balance goes negative).
            $date   = $_POST['mov_date'] ?? date('Y-m-d');
            $pid    = (int)($_POST['partner_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $note   = trim($_POST['note'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');
            if (!isset($partners[$pid])) throw new Exception('Choose a partner.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            $bal = partner_balance($conn, $pid);
            $src = 'Personal use';
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, amount, source, note)
                 VALUES (?,?,'debit','personal',?,?,?)"
            );
            $s->bind_param('sidss', $date, $pid, $amount, $src, $note);
            $s->execute(); $s->close();

            $msg = 'Settled ' . money($amount) . ' as personal use from ' . e($partners[$pid]['name']) . '.';
            if ($amount - $bal > 0.01) {
                $short = round($amount - $bal, 2);
                $msg .= ' Note: this is ' . money($short) . ' more than their balance — they now owe the business.';
                flash($msg, 'error');   // amber-style warning surfaced as a notice
            } else {
                flash($msg);
            }

        } elseif ($action === 'settle_transfer') {
            // Partner A settles an amount to Partner B (A owes B, or moves
            // business money between accounts). Paired rows: debit A, credit B,
            // same amount + reference, grouped by transfer_id so they read as one.
            $date = $_POST['mov_date'] ?? date('Y-m-d');
            $from = (int)($_POST['from_id'] ?? 0);
            $to   = (int)($_POST['to_id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $note = trim($_POST['note'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');
            if (!isset($partners[$from]) || !isset($partners[$to])) throw new Exception('Choose both partners.');
            if ($from === $to) throw new Exception('The two partners must be different.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            $fromName = $partners[$from]['name']; $toName = $partners[$to]['name'];
            $bal = partner_balance($conn, $from);

            $conn->begin_transaction();
            // Use a transfer_id = the first inserted row's id, to group the pair.
            $srcOut = 'Settlement to ' . $toName;
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, amount, source, note)
                 VALUES (?,?,'debit','transfer',?,?,?,?)"
            );
            $s->bind_param('siidss', $date, $from, $to, $amount, $srcOut, $note);
            $s->execute();
            $tid = (int)$conn->insert_id;
            $s->close();

            $srcIn = 'Settlement from ' . $fromName;
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, transfer_id, amount, source, note)
                 VALUES (?,?,'credit','transfer',?,?,?,?,?)"
            );
            $s->bind_param('siiidss', $date, $to, $from, $tid, $amount, $srcIn, $note);
            $s->execute();
            $s->close();

            // Tag the first row with the same transfer_id.
            $u = $conn->prepare('UPDATE account_movements SET transfer_id = ? WHERE id = ?');
            $u->bind_param('ii', $tid, $tid); $u->execute(); $u->close();
            $conn->commit();

            $msg = 'Settled ' . money($amount) . ' from ' . e($fromName) . ' to ' . e($toName) . '.';
            if ($amount - $bal > 0.01) {
                $msg .= ' Note: more than ' . e($fromName) . '\'s balance — their account goes negative.';
                flash($msg, 'error');
            } else {
                flash($msg);
            }

        } elseif ($action === 'settle_invest') {
            // INVESTMENT settlement: an underpaid partner reimburses an
            // overpaid one to even out the fair-share of total investment.
            // This adjusts NET INVESTED only — it does NOT move business money
            // through account balances. Paired rows, kind='invest':
            //   payer    -> invest_adjust = +amount  (net invested rises)
            //   receiver -> invest_adjust = -amount  (net invested falls)
            // direction/amount are left at 0 so credited/debited (and thus
            // account balance) are untouched.
            $date = $_POST['mov_date'] ?? date('Y-m-d');
            $from = (int)($_POST['from_id'] ?? 0);   // payer (was underpaid)
            $to   = (int)($_POST['to_id'] ?? 0);     // receiver (was overpaid)
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $note = trim($_POST['note'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');
            if (!isset($partners[$from]) || !isset($partners[$to])) throw new Exception('Choose both partners.');
            if ($from === $to) throw new Exception('The two partners must be different.');
            if ($amount <= 0) throw new Exception('Amount must be greater than zero.');

            $fromName = $partners[$from]['name']; $toName = $partners[$to]['name'];

            // ── Direction & size guards ──────────────────────────────
            // A settlement only makes sense underpaid -> overpaid. Getting the
            // two partners the wrong way round pushes BOTH gaps further from
            // zero, which is easy to do and hard to spot afterwards. Tolerance
            // of 0.01 keeps float noise from tripping the checks.
            $gaps    = investment_gaps($conn);
            $fromGap = $gaps[$from] ?? 0.0;   // payer:    expect negative
            $toGap   = $gaps[$to]   ?? 0.0;   // receiver: expect positive

            if ($fromGap > 0.01) {
                throw new Exception(
                    e($fromName) . ' is overpaid by ' . money($fromGap) .
                    ' - they are owed money, so they should not be paying a settlement.' .
                    ' The payer must be an underpaid partner. Check the investment page for who owes what.'
                );
            }
            if ($toGap < -0.01) {
                throw new Exception(
                    e($toName) . ' is underpaid by ' . money(abs($toGap)) .
                    ' - they owe money, so they should not be receiving a settlement.' .
                    ' The receiver must be an overpaid partner.'
                );
            }
            if ($amount > $toGap + 0.01) {
                throw new Exception(
                    e($toName) . ' is only owed ' . money($toGap) . ', but this settlement is ' .
                    money($amount) . '. Paying more would flip them into being underpaid.' .
                    ' Reduce the amount to ' . money($toGap) . ' or less.'
                );
            }
            if ($amount > abs($fromGap) + 0.01) {
                throw new Exception(
                    e($fromName) . ' only owes ' . money(abs($fromGap)) . ', but this settlement is ' .
                    money($amount) . '. Paying more would flip them into being overpaid.' .
                    ' Reduce the amount to ' . money(abs($fromGap)) . ' or less.'
                );
            }
            $zero = 0.0;

            $conn->begin_transaction();
            // Payer's net invested rises (+). Stored as a credit-direction row
            // only for listing; amount=0 keeps it out of balance sums.
            $posAdj = $amount;
            $srcOut = 'Investment settlement to ' . $toName;
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, amount, invest_adjust, source, note)
                 VALUES (?,?,'credit','invest',?,?,?,?,?)"
            );
            $s->bind_param('siiddss', $date, $from, $to, $zero, $posAdj, $srcOut, $note);
            $s->execute();
            $tid = (int)$conn->insert_id;
            $s->close();

            // Receiver's net invested falls (−).
            $negAdj = -$amount;
            $srcIn = 'Investment settlement from ' . $fromName;
            $s = $conn->prepare(
                "INSERT INTO account_movements (mov_date, partner_id, direction, kind, counterparty_id, transfer_id, amount, invest_adjust, source, note)
                 VALUES (?,?,'debit','invest',?,?,?,?,?,?)"
            );
            $s->bind_param('siiiddss', $date, $to, $from, $tid, $zero, $negAdj, $srcIn, $note);
            $s->execute();
            $s->close();

            $u = $conn->prepare('UPDATE account_movements SET transfer_id = ? WHERE id = ?');
            $u->bind_param('ii', $tid, $tid); $u->execute(); $u->close();
            $conn->commit();

            flash('Investment settlement: ' . money($amount) . ' from ' . e($fromName) . ' to ' . e($toName) . ' — net invested updated (account balances unchanged).');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // If this row is part of a transfer, remove both sides.
            $g = $conn->prepare('SELECT transfer_id FROM account_movements WHERE id = ?');
            $g->bind_param('i', $id); $g->execute();
            $trow = $g->get_result()->fetch_assoc(); $g->close();
            $tid = $trow['transfer_id'] ?? null;
            if (!empty($tid)) {
                $s = $conn->prepare('DELETE FROM account_movements WHERE transfer_id = ?');
                $s->bind_param('i', $tid); $s->execute(); $s->close();
                flash('Settlement (both sides) deleted.');
            } else {
                // If this credit stamped offline orders, free them first so
                // they can be re-credited, then delete the movement.
                $u = $conn->prepare('UPDATE orders SET credited_mov_id = NULL WHERE credited_mov_id = ?');
                $u->bind_param('i', $id); $u->execute(); $u->close();

                $s = $conn->prepare('DELETE FROM account_movements WHERE id = ?');
                $s->bind_param('i', $id); $s->execute(); $s->close();
                flash('Movement deleted.');
            }
        }
    } catch (Exception $ex) {
        @$conn->rollback();
        flash($ex->getMessage(), 'error');
    }
    $q = http_build_query(array_filter([
        'partner'   => $_POST['r_partner'] ?? '',
        'direction' => $_POST['r_direction'] ?? '',
    ], fn($v) => $v !== ''));
    header('Location: movements.php' . ($q ? "?$q" : ''));
    exit;
}

// ── Filters ────────────────────────────────────────────────────────
$fPartner = (int)($_GET['partner'] ?? 0);
$fDir     = ($_GET['direction'] ?? '');
if ($fDir !== 'credit' && $fDir !== 'debit') $fDir = '';

$where = []; $params = []; $types = '';
if ($fPartner > 0) { $where[]='m.partner_id = ?'; $params[]=$fPartner; $types.='i'; }
if ($fDir !== '')  { $where[]='m.direction = ?';  $params[]=$fDir;     $types.='s'; }

$sql = 'SELECT m.*, p.name AS partner_name
        FROM account_movements m LEFT JOIN partners p ON p.id = m.partner_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY m.mov_date DESC, m.id DESC';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totCredit = $totDebit = 0.0;
foreach ($rows as $r) {
    if ($r['direction'] === 'credit') $totCredit += (float)$r['amount'];
    else                              $totDebit  += (float)$r['amount'];
}

$filterQ = array_filter(['partner'=>$fPartner?:'', 'direction'=>$fDir], fn($v)=>$v!==''&&$v!==0);

// Uncredited offline sales (no event, not yet credited to a partner) — shown
// so you can credit that income to a partner from here.
$offUncredited = ['amt'=>0.0,'n'=>0,'first'=>null,'last'=>null];
$ou = $conn->query(
    "SELECT COALESCE(SUM(total),0) amt, COUNT(*) n,
            MIN(DATE(created_at)) first, MAX(DATE(created_at)) last
     FROM orders WHERE event_id IS NULL AND credited_mov_id IS NULL"
)->fetch_assoc();
if ($ou) $offUncredited = ['amt'=>(float)$ou['amt'],'n'=>(int)$ou['n'],'first'=>$ou['first'],'last'=>$ou['last']];

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account Movements · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select,textarea{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit}
  textarea{resize:vertical;min-height:38px}
  input:focus,select:focus,textarea:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:14px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;
              cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:10px 8px;border-bottom:1px solid #eceef0;vertical-align:top}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  .r{text-align:right}.scroll{overflow-x:auto}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}.acts button{padding:5px 10px;font-size:13px}
  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:500}
  .b-cr{background:#e3f5eb;color:#1a7f4b}.b-db{background:#fdeceb;color:#c0392b}
  .pill{display:inline-block;padding:2px 8px;border-radius:12px;background:#e7f0fd;font-size:11px;color:#1451a8}
  details{margin-top:8px}summary{cursor:pointer;font-size:13px;color:#1877f2}
  .muted{color:#8a8d91;font-size:13px}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.filters>div{flex:1;min-width:140px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px}
  .stat .l{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:22px;font-weight:600;margin-top:4px}.green{color:#1a7f4b}.red{color:#c0392b}
</style>
</head>
<body>
<?php
  $PAGE  = 'movements';
  $TITLE = 'Account movements';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <p class="muted" style="margin:-6px 0 16px">Sales income (credits) and account-funded spending (debits).</p>

  <div class="stats">
    <div class="stat"><div class="l">Credits shown</div><div class="v green"><?= money($totCredit) ?></div></div>
    <div class="stat"><div class="l">Debits shown</div><div class="v red"><?= money($totDebit) ?></div></div>
    <div class="stat"><div class="l">Net (credit − debit)</div><div class="v <?= ($totCredit-$totDebit)>=0?'green':'red' ?>"><?= money($totCredit-$totDebit) ?></div></div>
  </div>

  <div class="card">
    <h2>Add a movement</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div class="grid">
        <div><label>Date</label><input type="date" name="mov_date" required value="<?= e(date('Y-m-d')) ?>"></div>
        <div><label>Partner</label>
          <select name="partner_id" required>
            <option value="">Choose…</option>
            <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue; ?>
              <option value="<?= (int)$id ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Direction</label>
          <select name="direction" required>
            <option value="credit">Credit — money in (sales)</option>
            <option value="debit">Debit — spent from account</option>
          </select>
        </div>
        <div><label>Amount (₹)</label><input type="number" name="amount" step="0.01" min="0.01" required></div>
        <div><label>Source / purpose</label><input name="source" maxlength="200" placeholder="e.g. Meesho payout"></div>
      </div>
      <label>Note</label>
      <textarea name="note" maxlength="500" placeholder="optional"></textarea>
      <button type="submit" class="primary" style="margin-top:12px">Add movement</button>
    </form>
  </div>

  <div class="card">
    <h2>Settlements</h2>
    <p class="muted" style="margin-bottom:12px">Record when a partner uses business money for personal use, or settles an amount to another partner. These adjust account balances.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
      <div style="border:1px solid #eceef0;border-radius:8px;padding:14px">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:10px">Personal use (from own balance)</h3>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="settle_personal">
          <div style="margin-bottom:10px"><label>Partner</label>
            <select name="partner_id" required>
              <option value="">Choose…</option>
              <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue; ?><option value="<?= (int)$id ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:120px"><label>Date</label><input type="date" name="mov_date" required value="<?= e(date('Y-m-d')) ?>"></div>
            <div style="flex:1;min-width:120px"><label>Amount (₹)</label><input type="number" name="amount" step="0.01" min="0.01" required></div>
          </div>
          <div style="margin-top:10px"><label>Note</label><input name="note" maxlength="500" placeholder="what it was used for"></div>
          <button class="primary" style="margin-top:12px">Record personal use</button>
        </form>
      </div>

      <div style="border:1px solid #eceef0;border-radius:8px;padding:14px">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:10px">Partner → partner settlement</h3>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="settle_transfer">
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <div style="flex:1;min-width:120px"><label>From (pays)</label>
              <select name="from_id" required>
                <option value="">Choose…</option>
                <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue; ?><option value="<?= (int)$id ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div style="flex:1;min-width:120px"><label>To (receives)</label>
              <select name="to_id" required>
                <option value="">Choose…</option>
                <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue; ?><option value="<?= (int)$id ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
            <div style="flex:1;min-width:120px"><label>Date</label><input type="date" name="mov_date" required value="<?= e(date('Y-m-d')) ?>"></div>
            <div style="flex:1;min-width:120px"><label>Amount (₹)</label><input type="number" name="amount" step="0.01" min="0.01" required></div>
          </div>
          <div style="margin-top:10px"><label>Note</label><input name="note" maxlength="500" placeholder="reason for settlement"></div>
          <button class="primary" style="margin-top:12px">Record settlement</button>
        </form>
      </div>

      <div style="border:1px solid #eceef0;border-radius:8px;padding:14px">
        <h3 style="font-size:14px;font-weight:600;margin-bottom:4px">Investment settlement</h3>
        <p class="muted" style="font-size:12px;margin-bottom:10px">Underpaid partner reimburses an overpaid one to even out total investment. Adjusts net invested only — does <strong>not</strong> touch account balances. See the settle-up plan on the <a href="investment.php">Investment</a> page.</p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="settle_invest">
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php
              // Label each option with the partner's current position so the
              // right pair is obvious: only underpaid partners can pay, only
              // overpaid ones can receive (enforced server-side too).
              $gapLabel = function(int $id) use ($pageGaps): string {
                  $g = $pageGaps[$id] ?? 0.0;
                  if (abs($g) < 0.01) return ' — even';
                  return $g < 0
                      ? ' — underpaid ' . money(abs($g))
                      : ' — overpaid ' . money($g);
              };
            ?>
            <div style="flex:1;min-width:120px"><label>From (pays / was underpaid)</label>
              <select name="from_id" required>
                <option value="">Choose…</option>
                <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue;
                      $g = $pageGaps[$id] ?? 0.0; ?>
                  <option value="<?= (int)$id ?>"<?= $g > 0.01 ? ' disabled' : '' ?>><?= e($p['name']) . $gapLabel((int)$id) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="flex:1;min-width:120px"><label>To (receives / was overpaid)</label>
              <select name="to_id" required>
                <option value="">Choose…</option>
                <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue;
                      $g = $pageGaps[$id] ?? 0.0; ?>
                  <option value="<?= (int)$id ?>"<?= $g < -0.01 ? ' disabled' : '' ?>><?= e($p['name']) . $gapLabel((int)$id) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
            <div style="flex:1;min-width:120px"><label>Date</label><input type="date" name="mov_date" required value="<?= e(date('Y-m-d')) ?>"></div>
            <div style="flex:1;min-width:120px"><label>Amount (₹)</label><input type="number" name="amount" step="0.01" min="0.01" required></div>
          </div>
          <div style="margin-top:10px"><label>Note</label><input name="note" maxlength="500" placeholder="e.g. evening out investment"></div>
          <button class="primary" style="margin-top:12px">Record investment settlement</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Credit offline sales to a partner</h2>
    <?php if ($offUncredited['n'] > 0): ?>
      <p class="muted" style="margin-bottom:12px">
        <strong><?= money($offUncredited['amt']) ?></strong> across <?= $offUncredited['n'] ?> offline order(s)
        (<?= e(date('d M Y', strtotime($offUncredited['first']))) ?> – <?= e(date('d M Y', strtotime($offUncredited['last']))) ?>)
        has not been credited to any partner yet. Credit a date range to move that income into a partner's account.
      </p>
      <form method="post" action="save.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"
            onsubmit="return confirm('Credit offline sales in this date range to the chosen partner?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="credit_offline">
        <div style="flex:1;min-width:130px"><label>From</label>
          <input type="date" name="from" required value="<?= e($offUncredited['first']) ?>"></div>
        <div style="flex:1;min-width:130px"><label>To</label>
          <input type="date" name="to" required value="<?= e($offUncredited['last']) ?>"></div>
        <div style="flex:1;min-width:150px"><label>Credit to partner</label>
          <select name="partner_id" required>
            <option value="">Choose…</option>
            <?php foreach ($partners as $id=>$p): if(!$p['is_active']) continue; ?><option value="<?= (int)$id ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <button class="primary" type="submit" style="padding:9px 16px">Credit</button>
      </form>
      <p class="muted" style="font-size:12px;margin-top:8px">Only uncredited offline orders in the range are counted — already-credited ones are skipped, so you can't double-credit. Credits appear in the movements list below and feed the Investment summary.</p>
    <?php else: ?>
      <p class="muted">All offline sales have been credited to a partner. </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Filter</h2>
    <form method="get">
      <div class="filters">
        <div><label>Partner</label>
          <select name="partner">
            <option value="">All</option>
            <?php foreach ($partners as $id=>$p): ?>
              <option value="<?= (int)$id ?>" <?= $fPartner===(int)$id?'selected':'' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Direction</label>
          <select name="direction">
            <option value="">All</option>
            <option value="credit" <?= $fDir==='credit'?'selected':'' ?>>Credits</option>
            <option value="debit"  <?= $fDir==='debit'?'selected':'' ?>>Debits</option>
          </select>
        </div>
        <div style="flex:0">
          <button class="primary" type="submit">Apply</button>
          <a class="btn" href="movements.php">Clear</a>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2><?= count($rows) ?> movement(s)</h2>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Date</th><th>Partner</th><th>Direction</th><th>Source</th><th class="r">Amount</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="muted" style="padding:18px 8px">No movements match.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): $cr = $r['direction']==='credit'; ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($r['mov_date']))) ?></td>
            <td><?= e($r['partner_name'] ?? '—') ?></td>
            <td>
              <?php $kind = $r['kind'] ?? 'normal'; ?>
              <?php if ($kind === 'invest'): ?>
                <span class="badge" style="background:#efe7fb;color:#5b3ba0">Investment</span>
              <?php else: ?>
                <span class="badge <?= $cr?'b-cr':'b-db' ?>"><?= $cr?'Credit':'Debit' ?></span>
              <?php endif; ?>
              <?php if ($kind === 'personal'): ?><span class="pill" style="background:#fdeceb;color:#c0392b">personal</span>
              <?php elseif ($kind === 'transfer'): ?><span class="pill" style="background:#e7f0fd;color:#1451a8">settlement</span>
              <?php elseif ($kind === 'profit'): ?><span class="pill" style="background:#e3f5eb;color:#1a7f4b">profit</span>
              <?php elseif ($kind === 'invest'): ?><span class="pill" style="background:#efe7fb;color:#5b3ba0">settle-up</span><?php endif; ?>
            </td>
            <td>
              <?= $r['source']!=='' ? e($r['source']) : '<span class="muted">—</span>' ?>
              <?php if (!empty($r['event_id'])): ?><span class="pill" style="margin-left:6px">from event</span><?php endif; ?>
              <?php if ($r['note'] !== ''): ?><div class="muted"><?= e($r['note']) ?></div><?php endif; ?>
            </td>
            <?php if (($r['kind'] ?? 'normal') === 'invest'): $adj = (float)($r['invest_adjust'] ?? 0); ?>
              <td class="r" style="color:#5b3ba0"><?= ($adj>=0?'+':'−') . money(abs($adj)) ?> <span class="muted" style="font-size:11px">net inv.</span></td>
            <?php else: ?>
              <td class="r <?= $cr?'green':'red' ?>"><?= ($cr?'+':'−') . money($r['amount']) ?></td>
            <?php endif; ?>
            <td>
              <div class="acts">
                <form method="post" onsubmit="return confirm('Delete this movement?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                  <button class="danger">Delete</button>
                </form>
              </div>
              <details>
                <summary>Edit</summary>
                <form method="post" style="margin-top:10px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                  <div class="grid">
                    <div><label>Date</label><input type="date" name="mov_date" required value="<?= e($r['mov_date']) ?>"></div>
                    <div><label>Partner</label>
                      <select name="partner_id" required>
                        <?php foreach ($partners as $id=>$p): ?>
                          <option value="<?= (int)$id ?>" <?= (int)$r['partner_id']===(int)$id?'selected':'' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div><label>Direction</label>
                      <select name="direction" required>
                        <option value="credit" <?= $cr?'selected':'' ?>>Credit</option>
                        <option value="debit"  <?= !$cr?'selected':'' ?>>Debit</option>
                      </select>
                    </div>
                    <div><label>Amount (₹)</label><input type="number" name="amount" step="0.01" min="0.01" required value="<?= e(number_format((float)$r['amount'],2,'.','')) ?>"></div>
                    <div><label>Source</label><input name="source" maxlength="200" value="<?= e($r['source']) ?>"></div>
                  </div>
                  <label>Note</label>
                  <textarea name="note" maxlength="500"><?= e($r['note']) ?></textarea>
                  <button class="primary" style="margin-top:10px">Save changes</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="4">Totals</td><td class="r">+<?= money($totCredit) ?> / −<?= money($totDebit) ?></td><td></td></tr>
        </tfoot>
      </table>
    </div>
  </div>
<?php require 'layout_end.php'; ?>
</body>
</html>