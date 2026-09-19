<?php
require 'config.php';
$me = require_login();

/* Small helper: a single associative row from a prepared statement. */
function one_row(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}
function nf($a,$k){ return (float)($a[$k] ?? 0); }
function ni($a,$k){ return (int)($a[$k] ?? 0); }

// ── Inputs ─────────────────────────────────────────────────────────
// Dates: validate the YYYY-MM-DD shape; anything else falls back to today.
$isDate = fn($s) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s);
$today  = date('Y-m-d');

$from  = $isDate($_GET['from'] ?? '') ? $_GET['from'] : $today;
$to    = $isDate($_GET['to']   ?? '') ? $_GET['to']   : $today;
// Guard an inverted range so BETWEEN never returns empty by accident.
if ($from > $to) { [$from, $to] = [$to, $from]; }

$eventId = (int)($_GET['event'] ?? 0);   // 0 = all channels

// Events for the picker.
$events = $conn->query('SELECT id, name, is_active FROM events ORDER BY is_active DESC, name')
              ->fetch_all(MYSQLI_ASSOC);
$eventName = '';
foreach ($events as $ev) { if ((int)$ev['id'] === $eventId) $eventName = $ev['name']; }

// ── Order-window aggregates (by order created_at date) ─────────────
// Revenue meanings mirror the rest of the app:
//   gross       = sum of subtotals + additional charges (before discount)
//   discounts   = sum of discounts given
//   net_revenue = sum of totals (what is actually payable)
// Additional charges (delivery, rush fees) are counted as sales, so that
// gross - discounts = net_revenue reconciles exactly. Leaving them out of
// gross would make net look bigger than gross on any order with a fee.
//   collected   = sum of paid_amount
//   outstanding = balance still to collect on these orders
$eventCond = $eventId > 0 ? ' AND event_id = ?' : '';
$sql = "SELECT
          COUNT(*)                       AS orders,
          COALESCE(SUM(subtotal + extra_charge),0) AS gross,
          COALESCE(SUM(discount),0)      AS discounts,
          COALESCE(SUM(total),0)         AS net_revenue,
          COALESCE(SUM(paid_amount),0)   AS collected,
          COALESCE(SUM(CASE WHEN total > paid_amount
                            THEN total - paid_amount ELSE 0 END),0) AS outstanding
        FROM orders
        WHERE DATE(created_at) BETWEEN ? AND ?$eventCond";
$types = 'ss'; $params = [$from, $to];
if ($eventId > 0) { $types .= 'i'; $params[] = $eventId; }
$sum = one_row($conn, $sql, $types, $params);

// Items sold in the window.
$sqlItems = "SELECT COALESCE(SUM(oi.quantity),0) q
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE DATE(o.created_at) BETWEEN ? AND ?$eventCond";
$itemsSold = one_row($conn, $sqlItems, $types, $params);

// ── Per-admin breakdown (created_by) ───────────────────────────────
// created_by is an admin; ON DELETE SET NULL means a removed admin shows as —.
$sqlAdmin = "SELECT a.name AS admin_name,
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total),0) AS revenue,
                    COALESCE(SUM(o.paid_amount),0) AS collected
             FROM orders o
             LEFT JOIN admins a ON a.id = o.created_by
             WHERE DATE(o.created_at) BETWEEN ? AND ?" .
             ($eventId > 0 ? ' AND o.event_id = ?' : '') . "
             GROUP BY o.created_by, a.name
             ORDER BY revenue DESC";
$stmt = $conn->prepare($sqlAdmin);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$byAdmin = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Payment-mode split (by PAYMENT date, for cash reconciliation) ──
// Note: this counts money actually taken in the window, which is what you
// want when counting the cash drawer — not money owed on orders booked today.
$sqlModeTypes = 'ss'; $sqlModeParams = [$from, $to];
$modeEventJoin = '';
if ($eventId > 0) { $modeEventJoin = ' AND o.event_id = ?'; $sqlModeTypes .= 'i'; $sqlModeParams[] = $eventId; }
$sqlMode = "SELECT p.mode, COALESCE(SUM(p.amount),0) amt, COUNT(*) n
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE DATE(p.created_at) BETWEEN ? AND ?$modeEventJoin
            GROUP BY p.mode
            ORDER BY amt DESC";
$stmt = $conn->prepare($sqlMode);
$stmt->bind_param($sqlModeTypes, ...$sqlModeParams);
$stmt->execute();
$byMode = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$modeTotal = 0.0; foreach ($byMode as $m) $modeTotal += (float)$m['amt'];

// Human labels for payment modes (mirrors config's $PAYMENT_MODES).
$MODE_LABEL = ['cash'=>'Cash','upi'=>'UPI','card'=>'Card','other'=>'Other'];

// Nice human range label.
$rangeLabel = $from === $to ? date('D, j M Y', strtotime($from))
    : date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Summary · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:15px;font-weight:600;margin-bottom:2px}
  .card .sub{font-size:12px;color:#8a8d91;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
  .filters>div{flex:1;min-width:150px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .quick a{font-size:13px;padding:6px 12px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
  .stat{border:1px solid #eceef0;border-radius:8px;padding:14px;background:#fbfcfd}
  .stat .l{font-size:11px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:22px;font-weight:600;margin-top:4px}
  .stat .m{font-size:12px;color:#8a8d91;margin-top:2px}
  .green{color:#1a7f4b}.red{color:#c0392b}.amber{color:#a06a00}.blue{color:#1451a8}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:8px;border-bottom:2px solid #dfe1e5;font-size:11px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  .r{text-align:right}.scroll{overflow-x:auto}
  .muted{color:#8a8d91}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .rangehdr{font-size:13px;color:#65676b;margin-bottom:14px}
</style>
</head>
<body>
<?php
  $PAGE  = 'summary';
  $TITLE = 'Summary';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <!-- ── FILTER ─────────────────────────────────────────────────── -->
  <div class="card">
    <h2>Pick a period</h2>
    <div class="sub">Figures are for orders created in the chosen range. Cash reconciliation below counts payments taken in the range.</div>
    <form method="get">
      <div class="filters">
        <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div><label for="event">Event</label>
          <select id="event" name="event">
            <option value="0">All channels</option>
            <?php foreach ($events as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= $eventId===(int)$ev['id']?'selected':'' ?>>
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
        $q = fn($f,$t) => 'summary.php?from='.$f.'&to='.$t.($eventId>0?'&event='.$eventId:'');
        $d0 = $today;
        $d7 = date('Y-m-d', strtotime('-6 days'));
        $mStart = date('Y-m-01');
        $lmStart = date('Y-m-01', strtotime('first day of last month'));
        $lmEnd   = date('Y-m-t', strtotime('last day of last month'));
      ?>
      <a class="btn" href="<?= $q($d0,$d0) ?>">Today</a>
      <a class="btn" href="<?= $q($d7,$d0) ?>">Last 7 days</a>
      <a class="btn" href="<?= $q($mStart,$d0) ?>">This month</a>
      <a class="btn" href="<?= $q($lmStart,$lmEnd) ?>">Last month</a>
    </div>
  </div>

  <!-- ── HEADLINE ───────────────────────────────────────────────── -->
  <div class="card">
    <div class="rangehdr">
      <strong><?= e($rangeLabel) ?></strong>
      <?= $eventId>0 ? ' · Event: ' . e($eventName) : ' · All channels' ?>
    </div>
    <div class="stats">
      <div class="stat"><div class="l">Orders</div><div class="v"><?= number_format(ni($sum,'orders')) ?></div><div class="m"><?= number_format(ni($itemsSold,'q')) ?> items sold</div></div>
      <div class="stat"><div class="l">Gross sales</div><div class="v"><?= money(nf($sum,'gross')) ?></div><div class="m">incl. charges, before discount</div></div>
      <div class="stat"><div class="l">Discounts</div><div class="v amber"><?= money(nf($sum,'discounts')) ?></div></div>
      <div class="stat"><div class="l">Net revenue</div><div class="v"><?= money(nf($sum,'net_revenue')) ?></div><div class="m">payable after discount</div></div>
      <div class="stat"><div class="l">Cash collected</div><div class="v green"><?= money(nf($sum,'collected')) ?></div><div class="m">against these orders</div></div>
      <div class="stat"><div class="l">Outstanding</div><div class="v <?= nf($sum,'outstanding')>0.001?'amber':'' ?>"><?= money(nf($sum,'outstanding')) ?></div><div class="m">still to collect</div></div>
    </div>
  </div>

  <!-- ── BY ADMIN ───────────────────────────────────────────────── -->
  <div class="card">
    <h2>By person</h2>
    <div class="sub">Orders grouped by the admin who created them. If each partner has their own login, this is your per-partner split.</div>
    <?php if ($byAdmin): ?>
      <div class="scroll"><table>
        <thead><tr><th>Person</th><th class="r">Orders</th><th class="r">Revenue</th><th class="r">Collected</th></tr></thead>
        <tbody>
        <?php
          $tO=0; $tR=0.0; $tC=0.0;
          foreach ($byAdmin as $a):
            $tO += (int)$a['orders']; $tR += (float)$a['revenue']; $tC += (float)$a['collected'];
        ?>
          <tr>
            <td><?= $a['admin_name']!==null ? e($a['admin_name']) : '<span class="muted">— (removed admin)</span>' ?></td>
            <td class="r"><?= (int)$a['orders'] ?></td>
            <td class="r"><?= money($a['revenue']) ?></td>
            <td class="r green"><?= money($a['collected']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td>Total</td><td class="r"><?= $tO ?></td><td class="r"><?= money($tR) ?></td><td class="r"><?= money($tC) ?></td></tr>
        </tfoot>
      </table></div>
    <?php else: ?>
      <p class="muted">No orders in this period.</p>
    <?php endif; ?>
  </div>

  <!-- ── CASH RECONCILIATION ────────────────────────────────────── -->
  <div class="card">
    <h2>Cash reconciliation</h2>
    <div class="sub">Payments <strong>taken</strong> in this range, split by mode — for counting the drawer at the end of the day.</div>
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
        <tfoot>
          <tr><td>Total taken</td><td class="r"></td><td class="r"><?= money($modeTotal) ?></td></tr>
        </tfoot>
      </table></div>
    <?php else: ?>
      <p class="muted">No payments taken in this period.</p>
    <?php endif; ?>
  </div>

<?php require 'layout_end.php'; ?>
</body>
</html>