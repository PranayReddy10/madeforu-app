<?php
require 'config.php';
$me = require_login();

/* Small helper: run a query returning a single associative row. */
function one(mysqli $conn, string $sql): array {
    $r = $conn->query($sql);
    return $r ? ($r->fetch_assoc() ?: []) : [];
}

/* Single row from a PREPARED statement (used by the period picker below). */
function one_row(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

// ═══════════════════════════════════════════════════════════════════
// PERIOD PICKER (merged in from the old Summary page)
// A chosen date range + optional event, driving the section at the top.
// ═══════════════════════════════════════════════════════════════════
$isDate  = fn($s) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s);
$todayYmd = date('Y-m-d');

$pFrom = $isDate($_GET['from'] ?? '') ? $_GET['from'] : $todayYmd;
$pTo   = $isDate($_GET['to']   ?? '') ? $_GET['to']   : $todayYmd;
if ($pFrom > $pTo) { [$pFrom, $pTo] = [$pTo, $pFrom]; }   // guard inverted range
$pEvent = (int)($_GET['event'] ?? 0);   // 0 = all channels

$eventsList = $conn->query('SELECT id, name, is_active FROM events ORDER BY is_active DESC, name')
                   ->fetch_all(MYSQLI_ASSOC);
$pEventName = '';
foreach ($eventsList as $ev) { if ((int)$ev['id'] === $pEvent) $pEventName = $ev['name']; }

// Headline aggregates for the range.
$pEventCond = $pEvent > 0 ? ' AND event_id = ?' : '';
$pTypes = 'ss'; $pParams = [$pFrom, $pTo];
if ($pEvent > 0) { $pTypes .= 'i'; $pParams[] = $pEvent; }

$periodSum = one_row($conn,
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(subtotal + extra_charge),0) AS gross,
            COALESCE(SUM(discount),0)    AS discounts,
            COALESCE(SUM(total),0)       AS net_revenue,
            COALESCE(SUM(paid_amount),0) AS collected,
            COALESCE(SUM(CASE WHEN total > paid_amount
                              THEN total - paid_amount ELSE 0 END),0) AS outstanding
     FROM orders
     WHERE DATE(created_at) BETWEEN ? AND ?$pEventCond",
    $pTypes, $pParams);

$periodItems = one_row($conn,
    "SELECT COALESCE(SUM(oi.quantity),0) q
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE DATE(o.created_at) BETWEEN ? AND ?$pEventCond",
    $pTypes, $pParams);

// Per-person breakdown (by the admin who created the order).
$sqlByAdmin = "SELECT a.name AS admin_name, COUNT(*) AS orders,
                      COALESCE(SUM(o.total),0) AS revenue,
                      COALESCE(SUM(o.paid_amount),0) AS collected
               FROM orders o LEFT JOIN admins a ON a.id = o.created_by
               WHERE DATE(o.created_at) BETWEEN ? AND ?" .
               ($pEvent > 0 ? ' AND o.event_id = ?' : '') . "
               GROUP BY o.created_by, a.name ORDER BY revenue DESC";
$st = $conn->prepare($sqlByAdmin);
$st->bind_param($pTypes, ...$pParams);
$st->execute();
$byAdmin = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Cash reconciliation: payments TAKEN in the range, split by mode.
$mTypes = 'ss'; $mParams = [$pFrom, $pTo]; $mJoin = '';
if ($pEvent > 0) { $mJoin = ' AND o.event_id = ?'; $mTypes .= 'i'; $mParams[] = $pEvent; }
$st = $conn->prepare(
    "SELECT p.mode, COALESCE(SUM(p.amount),0) amt, COUNT(*) n
     FROM payments p JOIN orders o ON o.id = p.order_id
     WHERE DATE(p.created_at) BETWEEN ? AND ?$mJoin
     GROUP BY p.mode ORDER BY amt DESC");
$st->bind_param($mTypes, ...$mParams);
$st->execute();
$byMode = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$modeTotal = 0.0; foreach ($byMode as $m) $modeTotal += (float)$m['amt'];
$MODE_LABEL = ['cash'=>'Cash','upi'=>'UPI','card'=>'Card','other'=>'Other'];

$rangeLabel = $pFrom === $pTo ? date('D, j M Y', strtotime($pFrom))
    : date('j M Y', strtotime($pFrom)) . ' – ' . date('j M Y', strtotime($pTo));

// ── Sales windows (by order created_at) ─────────────────────────────
// Revenue here = actual payable (orders.total, already net of discount).
$sqlWindow = function(string $cond) {
    return "SELECT COUNT(*) c,
                   COALESCE(SUM(total),0) revenue,
                   COALESCE(SUM(paid_amount),0) collected
            FROM orders" . ($cond ? " WHERE $cond" : "");
};
$today = one($conn, $sqlWindow("DATE(created_at) = CURDATE()"));
$week  = one($conn, $sqlWindow("created_at >= (CURDATE() - INTERVAL 6 DAY)"));
$month = one($conn, $sqlWindow("created_at >= (CURDATE() - INTERVAL 29 DAY)"));
$allT  = one($conn, $sqlWindow(""));

// Fulfillment: orders not yet handed over, and not yet ready.
$pending = one($conn, "SELECT
    SUM(CASE WHEN is_delivered = 0 THEN 1 ELSE 0 END) undelivered,
    SUM(CASE WHEN is_ready = 0 AND is_delivered = 0 THEN 1 ELSE 0 END) not_ready
    FROM orders");

// ── Money owed TO you (customers) ───────────────────────────────────
$owedToYou = one($conn, "SELECT
    COALESCE(SUM(CASE WHEN total > paid_amount THEN total - paid_amount ELSE 0 END),0) amt,
    SUM(CASE WHEN total > 0 AND paid_amount <= 0 THEN 1 ELSE 0 END) unpaid_n,
    SUM(CASE WHEN paid_amount > 0 AND paid_amount < total THEN 1 ELSE 0 END) partial_n
    FROM orders");

// Top unpaid/partial orders to chase.
$owedList = $conn->query("SELECT id, order_no, name, total, paid_amount,
    (total - paid_amount) AS bal
    FROM orders WHERE total > paid_amount
    ORDER BY bal DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

// ── Money YOU owe (vendors, via expenses) ───────────────────────────
// Net owed per expense = amount - discount; paid = sum of expense_payments.
$owedVendor = one($conn, "SELECT
    COALESCE(SUM(bal),0) amt, COUNT(*) n FROM (
      SELECT (e.amount - e.discount) -
             COALESCE((SELECT SUM(amount) FROM expense_payments WHERE expense_id = e.id),0) AS bal
      FROM expenses e
    ) t WHERE bal > 0.001");

$vendorList = $conn->query("SELECT e.id, e.item, e.paid_to,
    (e.amount - e.discount) AS net_owed,
    COALESCE((SELECT SUM(amount) FROM expense_payments WHERE expense_id = e.id),0) AS paid,
    ((e.amount - e.discount) - COALESCE((SELECT SUM(amount) FROM expense_payments WHERE expense_id = e.id),0)) AS bal
    FROM expenses e
    HAVING bal > 0.001
    ORDER BY bal DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

// ── Investment snapshot ─────────────────────────────────────────────
// Paid = pocket shares (expense_payments). Credited/Debited from movements.
// Net invested = Paid - Credited + Debited.  Account balance = Credited - Debited.
$partners = $conn->query(
    'SELECT p.id, p.name, p.is_active,
       (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE partner_id = p.id) AS paid,
       (SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE partner_id = p.id AND direction = "credit") AS credited,
       (SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE partner_id = p.id AND direction = "debit")  AS debited
     FROM partners p ORDER BY p.name'
)->fetch_all(MYSQLI_ASSOC);

$invT = ['paid'=>0.0,'credited'=>0.0,'debited'=>0.0,'net'=>0.0,'bal'=>0.0];
$pRows = [];
foreach ($partners as $p) {
    $paid=(float)$p['paid']; $cr=(float)$p['credited']; $db=(float)$p['debited'];
    $net=$paid-$cr+$db; $bal=$cr-$db;
    if ($paid==0 && $cr==0 && $db==0 && !$p['is_active']) continue;
    $pRows[] = ['name'=>$p['name'],'net'=>$net,'bal'=>$bal];
    $invT['paid']+=$paid; $invT['credited']+=$cr; $invT['debited']+=$db; $invT['net']+=$net; $invT['bal']+=$bal;
}

// ── Sales trends ────────────────────────────────────────────────────
// This calendar month vs last calendar month (revenue + order count).
$thisMonth = one($conn, "SELECT COUNT(*) c, COALESCE(SUM(total),0) revenue
    FROM orders WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
$lastMonth = one($conn, "SELECT COUNT(*) c, COALESCE(SUM(total),0) revenue
    FROM orders WHERE created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                  AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$momPct = n($lastMonth,'revenue') > 0
    ? (n($thisMonth,'revenue') - n($lastMonth,'revenue')) / n($lastMonth,'revenue') * 100 : null;

// Averages and totals across all orders.
$avgOrder = one($conn, "SELECT COALESCE(AVG(total),0) v, COALESCE(SUM(discount),0) disc,
    COALESCE(SUM(total),0) rev, COUNT(*) c FROM orders");
$itemsSold = one($conn, "SELECT COALESCE(SUM(quantity),0) q FROM order_items");

// Best single sales day (by revenue).
$bestDay = one($conn, "SELECT DATE(created_at) d, SUM(total) rev
    FROM orders GROUP BY DATE(created_at) ORDER BY rev DESC LIMIT 1");

// ── Top products ────────────────────────────────────────────────────
// By quantity and revenue, with cost joined for profit.
$topProducts = $conn->query("SELECT oi.item,
        SUM(oi.quantity) qty,
        SUM(oi.line_total) revenue,
        COALESCE(pc.unit_cost,0) unit_cost,
        (SUM(oi.line_total) - SUM(oi.quantity) * COALESCE(pc.unit_cost,0)) profit
    FROM order_items oi
    LEFT JOIN product_costs pc ON pc.item = oi.item
    GROUP BY oi.item, pc.unit_cost
    ORDER BY qty DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

// ── Customers ───────────────────────────────────────────────────────
// Unique by phone; repeat = phones with more than one order.
$custStats = one($conn, "SELECT
    COUNT(DISTINCT phone) uniq,
    COALESCE(SUM(CASE WHEN phone <> '' THEN 1 ELSE 0 END),0) total_named
    FROM orders");
$repeat = one($conn, "SELECT COUNT(*) c FROM (
    SELECT phone FROM orders WHERE phone <> '' GROUP BY phone HAVING COUNT(*) > 1
    ) t");
$topCustomers = $conn->query("SELECT name, phone, COUNT(*) orders, SUM(total) spent
    FROM orders WHERE phone <> ''
    GROUP BY phone, name ORDER BY spent DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// ── Events ──────────────────────────────────────────────────────────
$activeEventCount = ni(one($conn, "SELECT COUNT(*) c FROM events WHERE is_active = 1"), 'c');
// Per-event revenue and a rough profit (revenue - entry - extra). COGS per
// event would need item-cost joins; kept simple here with event costs only.
$topEvents = $conn->query("SELECT e.id, e.name, e.is_active,
        COALESCE((SELECT SUM(total) FROM orders WHERE event_id = e.id),0) revenue,
        (COALESCE((SELECT SUM(total) FROM orders WHERE event_id = e.id),0)
            - e.entry_cost - e.extra_cost) net
    FROM events e
    ORDER BY revenue DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

$flash = flash();

// convenience
function n($a,$k){ return (float)($a[$k] ?? 0); }
function ni($a,$k){ return (int)($a[$k] ?? 0); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:15px;font-weight:600;margin-bottom:2px}
  .card .sub{font-size:12px;color:#8a8d91;margin-bottom:14px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
  .stat{border:1px solid #eceef0;border-radius:8px;padding:14px;background:#fbfcfd}
  .stat .l{font-size:11px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:22px;font-weight:600;margin-top:4px}
  .stat .m{font-size:12px;color:#8a8d91;margin-top:2px}
  .green{color:#1a7f4b}.red{color:#c0392b}.amber{color:#a06a00}.blue{color:#1451a8}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:8px;border-bottom:2px solid #dfe1e5;font-size:11px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .r{text-align:right}.scroll{overflow-x:auto}
  a.btn{padding:8px 14px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:13px;text-decoration:none;color:#1c1e21;display:inline-block}
  a.btn:hover{background:#f0f2f5}
  .btnrow{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .muted{color:#8a8d91}
  .two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media(max-width:760px){.two{grid-template-columns:1fr}}
  h3.sec{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#65676b;margin:6px 2px 10px}
  .filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
  .filters>div{flex:1;min-width:150px}
  .filters label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  .filters input,.filters select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  .filters input:focus,.filters select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .primary{background:#1877f2;color:#fff;border:1px solid #1877f2;border-radius:6px;padding:9px 16px;font-size:14px;cursor:pointer;font-family:inherit}
  .primary:hover{background:#166fe5}
  .quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .rangehdr{font-size:13px;color:#65676b;margin-bottom:14px}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
</style>
</head>
<body>
<?php
  $PAGE  = 'dashboard';
  $TITLE = 'Dashboard';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <!-- ── PERIOD SUMMARY (merged from Summary) ──────────────────── -->
  <h3 class="sec">Period summary</h3>
  <div class="card">
    <div class="sub">Pick a range and optional event. Figures below are for orders created in that range; cash reconciliation counts payments taken in it.</div>
    <form method="get">
      <div class="filters">
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($pFrom) ?>"></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($pTo) ?>"></div>
        <div><label for="event">Event</label>
          <select id="event" name="event">
            <option value="0">All channels</option>
            <?php foreach ($eventsList as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= $pEvent===(int)$ev['id']?'selected':'' ?>>
                <?= e($ev['name']) ?><?= $ev['is_active'] ? '' : ' (closed)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:0 0 auto"><button type="submit" class="primary">Show</button></div>
      </div>
    </form>
    <div class="quick">
      <?php
        $qp = fn($f,$t) => 'dashboard.php?from='.$f.'&to='.$t.($pEvent>0?'&event='.$pEvent:'');
        $d0 = $todayYmd;
        $d7 = date('Y-m-d', strtotime('-6 days'));
        $mStart = date('Y-m-01');
        $lmStart = date('Y-m-01', strtotime('first day of last month'));
        $lmEnd   = date('Y-m-t', strtotime('last day of last month'));
      ?>
      <a class="btn" href="<?= $qp($d0,$d0) ?>">Today</a>
      <a class="btn" href="<?= $qp($d7,$d0) ?>">Last 7 days</a>
      <a class="btn" href="<?= $qp($mStart,$d0) ?>">This month</a>
      <a class="btn" href="<?= $qp($lmStart,$lmEnd) ?>">Last month</a>
    </div>

    <div class="rangehdr" style="margin-top:16px">
      <strong><?= e($rangeLabel) ?></strong>
      <?= $pEvent>0 ? ' · Event: ' . e($pEventName) : ' · All channels' ?>
    </div>
    <div class="stats">
      <div class="stat"><div class="l">Orders</div><div class="v"><?= number_format(ni($periodSum,'orders')) ?></div><div class="m"><?= number_format(ni($periodItems,'q')) ?> items sold</div></div>
      <div class="stat"><div class="l">Gross sales</div><div class="v"><?= money(n($periodSum,'gross')) ?></div><div class="m">incl. charges, before discount</div></div>
      <div class="stat"><div class="l">Discounts</div><div class="v amber"><?= money(n($periodSum,'discounts')) ?></div></div>
      <div class="stat"><div class="l">Net revenue</div><div class="v"><?= money(n($periodSum,'net_revenue')) ?></div><div class="m">payable after discount</div></div>
      <div class="stat"><div class="l">Cash collected</div><div class="v green"><?= money(n($periodSum,'collected')) ?></div><div class="m">against these orders</div></div>
      <div class="stat"><div class="l">Outstanding</div><div class="v <?= n($periodSum,'outstanding')>0.001?'amber':'' ?>"><?= money(n($periodSum,'outstanding')) ?></div><div class="m">still to collect</div></div>
    </div>
  </div>

  <div class="two">
    <div class="card">
      <h2>By person</h2>
      <div class="sub">Orders grouped by the admin who created them — your per-partner split if each partner signs in as themselves.</div>
      <?php if ($byAdmin): ?>
        <div class="scroll"><table>
          <thead><tr><th>Person</th><th class="r">Orders</th><th class="r">Revenue</th><th class="r">Collected</th></tr></thead>
          <tbody>
          <?php $tO=0;$tR=0.0;$tC=0.0; foreach ($byAdmin as $a):
                  $tO+=(int)$a['orders']; $tR+=(float)$a['revenue']; $tC+=(float)$a['collected']; ?>
            <tr>
              <td><?= $a['admin_name']!==null ? e($a['admin_name']) : '<span class="muted">— (removed)</span>' ?></td>
              <td class="r"><?= (int)$a['orders'] ?></td>
              <td class="r"><?= money($a['revenue']) ?></td>
              <td class="r green"><?= money($a['collected']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>Total</td><td class="r"><?= $tO ?></td><td class="r"><?= money($tR) ?></td><td class="r"><?= money($tC) ?></td></tr></tfoot>
        </table></div>
      <?php else: ?><p class="muted">No orders in this period.</p><?php endif; ?>
    </div>

    <div class="card">
      <h2>Cash reconciliation</h2>
      <div class="sub">Payments <strong>taken</strong> in this range, by mode — for counting the drawer at day's end.</div>
      <?php if ($byMode): ?>
        <div class="scroll"><table>
          <thead><tr><th>Mode</th><th class="r">Payments</th><th class="r">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($byMode as $m): ?>
            <tr>
              <td><?= e($MODE_LABEL[$m['mode']] ?? ucfirst($m['mode'])) ?></td>
              <td class="r"><?= (int)$m['n'] ?></td>
              <td class="r"><?= money($m['amt']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td>Total taken</td><td class="r"></td><td class="r"><?= money($modeTotal) ?></td></tr></tfoot>
        </table></div>
      <?php else: ?><p class="muted">No payments taken in this period.</p><?php endif; ?>
    </div>
  </div>

  <!-- ── SALES ────────────────────────────────────────────────── -->
  <h3 class="sec">Sales</h3>
  <div class="card">
    <div class="stats">
      <div class="stat"><div class="l">Today</div><div class="v"><?= money(n($today,'revenue')) ?></div><div class="m"><?= ni($today,'c') ?> order(s)</div></div>
      <div class="stat"><div class="l">Last 7 days</div><div class="v"><?= money(n($week,'revenue')) ?></div><div class="m"><?= ni($week,'c') ?> order(s)</div></div>
      <div class="stat"><div class="l">Last 30 days</div><div class="v"><?= money(n($month,'revenue')) ?></div><div class="m"><?= ni($month,'c') ?> order(s)</div></div>
      <div class="stat"><div class="l">All time</div><div class="v"><?= money(n($allT,'revenue')) ?></div><div class="m"><?= ni($allT,'c') ?> order(s)</div></div>
    </div>

    <div class="stats" style="margin-top:12px">
      <div class="stat"><div class="l">This month</div><div class="v"><?= money(n($thisMonth,'revenue')) ?></div>
        <div class="m">
          <?php if ($momPct === null): ?><span class="muted">no last-month data</span>
          <?php else: ?><span class="<?= $momPct>=0?'green':'red' ?>"><?= ($momPct>=0?'▲ ':'▼ ') . number_format(abs($momPct),1) ?>% vs last month</span><?php endif; ?>
        </div>
      </div>
      <div class="stat"><div class="l">Last month</div><div class="v"><?= money(n($lastMonth,'revenue')) ?></div><div class="m"><?= ni($lastMonth,'c') ?> order(s)</div></div>
      <div class="stat"><div class="l">Avg order value</div><div class="v"><?= money(n($avgOrder,'v')) ?></div><div class="m"><?= ni($avgOrder,'c') ?> orders total</div></div>
      <div class="stat"><div class="l">Items sold</div><div class="v"><?= number_format(n($itemsSold,'q')) ?></div><div class="m">units all-time</div></div>
      <div class="stat"><div class="l">Discounts given</div><div class="v amber"><?= money(n($avgOrder,'disc')) ?></div><div class="m">across all orders</div></div>
      <?php if ($bestDay): ?>
      <div class="stat"><div class="l">Best sales day</div><div class="v"><?= money(n($bestDay,'rev')) ?></div><div class="m"><?= e(date('d M Y', strtotime($bestDay['d']))) ?></div></div>
      <?php endif; ?>
    </div>

    <div class="btnrow">
      <a class="btn" href="index.php">View all orders</a>
      <a class="btn" href="index.php?delivered=0">Pending deliveries (<?= ni($pending,'undelivered') ?>)</a>
      <a class="btn" href="index.php?ready=0&amp;delivered=0">Not ready yet (<?= ni($pending,'not_ready') ?>)</a>
    </div>
  </div>

  <!-- ── TOP PRODUCTS ─────────────────────────────────────────── -->
  <h3 class="sec">Top products</h3>
  <div class="card">
    <div class="sub">By units sold, all-time. Profit uses each product's manufacture cost.</div>
    <?php if ($topProducts): ?>
      <div class="scroll"><table>
        <thead><tr><th>Product</th><th class="r">Units</th><th class="r">Revenue</th><th class="r">Profit</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $tp): ?>
          <tr>
            <td><?= e($tp['item']) ?></td>
            <td class="r"><?= number_format((float)$tp['qty']) ?></td>
            <td class="r"><?= money($tp['revenue']) ?></td>
            <td class="r <?= (float)$tp['unit_cost']>0 ? ((float)$tp['profit']>=0?'green':'red') : 'muted' ?>">
              <?= (float)$tp['unit_cost']>0 ? money($tp['profit']) : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="sub" style="margin-top:8px">A “—” profit means no manufacture cost is set for that product (add it under Manufacture price).</p>
    <?php else: ?>
      <p class="muted">No products sold yet.</p>
    <?php endif; ?>
  </div>

  <!-- ── CUSTOMERS & EVENTS ───────────────────────────────────── -->
  <h3 class="sec">Customers &amp; events</h3>
  <div class="two">
    <div class="card">
      <h2>Customers</h2>
      <div class="stats" style="margin-bottom:12px">
        <div class="stat"><div class="l">Unique customers</div><div class="v"><?= number_format(ni($custStats,'uniq')) ?></div></div>
        <div class="stat"><div class="l">Repeat buyers</div><div class="v blue"><?= number_format(ni($repeat,'c')) ?></div><div class="m">ordered 2+ times</div></div>
      </div>
      <?php if ($topCustomers): ?>
        <div class="scroll"><table>
          <thead><tr><th>Customer</th><th class="r">Orders</th><th class="r">Spent</th></tr></thead>
          <tbody>
          <?php foreach ($topCustomers as $c): ?>
            <tr>
              <td><?= e($c['name']) ?><div class="sub" style="font-family:monospace"><?= e($c['phone']) ?></div></td>
              <td class="r"><?= (int)$c['orders'] ?></td>
              <td class="r"><?= money($c['spent']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Events</h2>
      <div class="stat" style="margin-bottom:12px"><div class="l">Active events</div><div class="v"><?= $activeEventCount ?></div></div>
      <?php if ($topEvents): ?>
        <div class="scroll"><table>
          <thead><tr><th>Event</th><th class="r">Revenue</th><th class="r">Net</th></tr></thead>
          <tbody>
          <?php foreach ($topEvents as $ev): ?>
            <tr>
              <td><a href="event_costs.php?event=<?= (int)$ev['id'] ?>" class="blue" style="text-decoration:none"><?= e($ev['name']) ?></a>
                  <?php if (!$ev['is_active']): ?><span class="sub"> · closed</span><?php endif; ?></td>
              <td class="r"><?= money($ev['revenue']) ?></td>
              <td class="r <?= (float)$ev['net']>=0?'green':'red' ?>"><?= money($ev['net']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="sub" style="margin-top:8px">Net here = revenue − entry/stall fee − event costs. Full P&amp;L (with cost of goods) is on the Event P&amp;L page.</p>
      <?php else: ?>
        <p class="muted">No events yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── MONEY OWED ───────────────────────────────────────────── -->
  <h3 class="sec">Money owed</h3>
  <div class="two">
    <div class="card">
      <h2>Customers owe you</h2>
      <div class="sub"><?= ni($owedToYou,'unpaid_n') ?> unpaid · <?= ni($owedToYou,'partial_n') ?> partial</div>
      <div class="stat" style="margin-bottom:12px"><div class="l">Outstanding to collect</div><div class="v amber"><?= money(n($owedToYou,'amt')) ?></div></div>
      <?php if ($owedList): ?>
        <div class="scroll"><table>
          <thead><tr><th>Order</th><th>Customer</th><th class="r">Balance</th></tr></thead>
          <tbody>
          <?php foreach ($owedList as $o): ?>
            <tr>
              <td><a href="edit.php?id=<?= (int)$o['id'] ?>" class="blue" style="text-decoration:none"><?= e($o['order_no']) ?></a></td>
              <td><?= e($o['name']) ?></td>
              <td class="r amber"><?= money($o['bal']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="btnrow"><a class="btn" href="index.php?paid=unpaid">See unpaid</a><a class="btn" href="index.php?paid=partial">See partial</a></div>
      <?php else: ?>
        <p class="muted">All orders fully paid. </p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>You owe vendors</h2>
      <div class="sub"><?= ni($owedVendor,'n') ?> expense(s) with a balance</div>
      <div class="stat" style="margin-bottom:12px"><div class="l">Outstanding to pay</div><div class="v red"><?= money(n($owedVendor,'amt')) ?></div></div>
      <?php if ($vendorList): ?>
        <div class="scroll"><table>
          <thead><tr><th>Item</th><th>Vendor</th><th class="r">Balance</th></tr></thead>
          <tbody>
          <?php foreach ($vendorList as $v): ?>
            <tr>
              <td><?= e($v['item']) ?></td>
              <td><?= $v['paid_to']!=='' ? e($v['paid_to']) : '<span class="muted">—</span>' ?></td>
              <td class="r red"><?= money($v['bal']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="btnrow"><a class="btn" href="expenses.php">Manage expenses</a></div>
      <?php else: ?>
        <p class="muted">No outstanding vendor balances. </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── INVESTMENT ───────────────────────────────────────────── -->
  <h3 class="sec">Investment</h3>
  <div class="card">
    <div class="stats" style="margin-bottom:14px">
      <div class="stat"><div class="l">Total net invested</div><div class="v"><?= money($invT['net']) ?></div></div>
      <div class="stat"><div class="l">Paid from pocket</div><div class="v"><?= money($invT['paid']) ?></div></div>
      <div class="stat"><div class="l">In partner accounts</div><div class="v green"><?= money($invT['bal']) ?></div></div>
    </div>
    <?php if ($pRows): ?>
      <div class="scroll"><table>
        <thead><tr><th>Partner</th><th class="r">Net invested</th><th class="r">Account balance</th></tr></thead>
        <tbody>
        <?php foreach ($pRows as $p): ?>
          <tr>
            <td><?= e($p['name']) ?></td>
            <td class="r"><strong><?= money($p['net']) ?></strong></td>
            <td class="r <?= $p['bal']>=0?'green':'red' ?>"><?= money($p['bal']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <div class="btnrow">
      <a class="btn" href="investment.php">Full investment summary</a>
      <a class="btn" href="movements.php">Account movements</a>
      <a class="btn" href="expenses.php">Expenses</a>
    </div>
  </div>

<?php require 'layout_end.php'; ?>
</body>
</html>