<?php
require 'config.php';
require_once __DIR__ . '/lib_trade.php';   // sales channels
$me = require_login();

$search     = trim($_GET['search'] ?? '');
// Default the orders view to OFFLINE ONLY on a bare page open. If the event
// filter is explicitly set (even to '' via the "All orders" option), or the
// user arrived with a search or another filter active, respect that instead
// so those never get silently narrowed to offline.
$otherFilterActive = isset($_GET['search']) || isset($_GET['paid'])
                  || isset($_GET['ready']) || isset($_GET['delivered'])
                  || isset($_GET['dispatch']);
if (array_key_exists('event', $_GET)) {
    $fEvent = $_GET['event'];          // explicit choice from a dropdown/link
} elseif ($otherFilterActive) {
    $fEvent = '';                      // searching/filtering -> across all orders
} else {
    $fEvent = '0';                     // fresh open -> offline orders by default
}
// '' = all, '0' = offline only, N = event id
$fPaid      = $_GET['paid'] ?? '';
$fReady     = $_GET['ready'] ?? '';
$fDelivered = $_GET['delivered'] ?? '';
$fDispatch  = $_GET['dispatch'] ?? '';   // '' = any, '1' = has AWB, '0' = no AWB

$where = []; $params = []; $types = '';

if ($search !== '') {
    $like = '%' . $search . '%';

    // Phone is stored as 10 digits. Match it against the search digits, and
    // also against a country-code-stripped version, so "+91 62017 81217",
    // "62017 81217", "6201781217" and a partial "6201" all find the order.
    // Name and order-no still match the raw term.
    $digits = preg_replace('/[^0-9]/', '', $search);
    if ($digits !== '') {
        // Also try a country-code-stripped form, inline so this does not
        // depend on any helper in config.php being present.
        $stripped = $digits;
        if (strlen($stripped) > 10 && substr($stripped, 0, 2) === '91') {
            $stripped = substr($stripped, 2);
        }
        $stripped = ltrim($stripped, '0');

        $phoneA = '%' . $digits . '%';
        $phoneB = '%' . $stripped . '%';
        $where[] = '((o.phone LIKE ? OR o.phone LIKE ?) OR o.name LIKE ? OR o.order_no LIKE ?)';
        $params[] = $phoneA; $params[] = $phoneB; $params[] = $like; $params[] = $like;
        $types .= 'ssss';
    } else {
        // No digits typed -- a name or order-no search only.
        $where[] = '(o.name LIKE ? OR o.order_no LIKE ?)';
        $params[] = $like; $params[] = $like;
        $types .= 'ss';
    }
}
// Mirror pay_status() exactly. A fully-discounted order (total = 0) owes
// nothing, so it counts as paid -- not unpaid, and not invisible to filters.
if ($fPaid === 'paid')        $where[] = '(o.total <= 0 OR o.paid_amount >= o.total)';
elseif ($fPaid === 'unpaid')  $where[] = '(o.total > 0 AND o.paid_amount <= 0)';
elseif ($fPaid === 'partial') $where[] = '(o.paid_amount > 0 AND o.paid_amount < o.total)';

if ($fReady !== '')     { $where[]='o.is_ready = ?';     $params[]=(int)$fReady;     $types.='i'; }
if ($fDelivered !== '') { $where[]='o.is_delivered = ?'; $params[]=(int)$fDelivered; $types.='i'; }
// Dispatched means "has a Delhivery tracking number" -- the single source of
// truth for an online delivery order. A blank AWB is the same as none.
if ($fDispatch === '1')      $where[] = "(o.awb IS NOT NULL AND o.awb <> '')";
elseif ($fDispatch === '0')  $where[] = "(o.awb IS NULL OR o.awb = '')";
if ($fEvent === '0')      { $where[] = 'o.event_id IS NULL'; }
elseif ($fEvent !== '')   { $where[] = 'o.event_id = ?'; $params[] = (int)$fEvent; $types .= 'i'; }

// A correlated subquery instead of JOIN + GROUP BY.
// `SELECT o.*` with `GROUP BY o.id` is rejected under ONLY_FULL_GROUP_BY,
// which is the MySQL default since 5.7. This form works everywhere.
$sql = 'SELECT o.*, a.name AS admin_name, ev.name AS event_name,
               (SELECT GROUP_CONCAT(CONCAT(i.item, " x", i.quantity) SEPARATOR ", ")
                  FROM order_items i WHERE i.order_id = o.id) AS item_list
        FROM orders o
        LEFT JOIN admins a  ON a.id = o.created_by
        LEFT JOIN events ev ON ev.id = o.event_id';
// The channel column arrives with a migration, so the join is added only
// once it exists -- this page must keep working in the window between
// uploading it and running the SQL.
if (db_column_exists($conn, 'orders', 'channel_id') && trade_has_table($conn, 'channels')) {
    $sql = str_replace(
        'SELECT o.*, a.name AS admin_name, ev.name AS event_name,',
        'SELECT o.*, a.name AS admin_name, ev.name AS event_name, ch.name AS channel_name,',
        $sql
    ) . ' LEFT JOIN channels ch ON ch.id = o.channel_id';
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY o.id DESC';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats must respect the SAME filters as the order list, or the totals at
// the top describe a different set of orders than the rows below. Reuse the
// $where/$params already built above.
$statWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$statSql = "
  SELECT COUNT(*) AS total_orders,
    COALESCE(SUM(o.subtotal + o.extra_charge), 0) AS gross_value,
    COALESCE(SUM(o.discount), 0)    AS total_discount,
    COALESCE(SUM(o.total), 0)       AS total_value,
    COALESCE(SUM(o.paid_amount), 0) AS collected,
    COALESCE(SUM(CASE WHEN o.total > o.paid_amount THEN o.total - o.paid_amount ELSE 0 END), 0) AS outstanding,
    SUM(CASE WHEN o.total > 0 AND o.paid_amount <= 0 THEN 1 ELSE 0 END)                  AS unpaid_count,
    SUM(CASE WHEN o.paid_amount > 0 AND o.paid_amount < o.total THEN 1 ELSE 0 END)       AS partial_count,
    SUM(CASE WHEN o.total <= 0 OR o.paid_amount >= o.total THEN 1 ELSE 0 END)            AS paid_count,
    SUM(CASE WHEN o.is_ready = 0 THEN 1 ELSE 0 END)                                      AS not_ready,
    SUM(CASE WHEN o.is_ready = 1 AND o.is_delivered = 0 THEN 1 ELSE 0 END)               AS ready_undelivered,
    SUM(CASE WHEN o.awb IS NOT NULL AND o.awb <> '' THEN 1 ELSE 0 END)                   AS dispatched_count
  FROM orders o" . $statWhere;

$st = $conn->prepare($statSql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$stats = $st->get_result()->fetch_assoc();
$st->close();

// Items sold + per-item revenue, filtered to the same orders via a join.
$qtySql = "SELECT COALESCE(SUM(i.quantity),0) q
           FROM order_items i JOIN orders o ON o.id = i.order_id" . $statWhere;
$st = $conn->prepare($qtySql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$qty = (int)$st->get_result()->fetch_assoc()['q'];
$st->close();

$topSql = "SELECT i.item, SUM(i.quantity) qty, SUM(i.line_total) revenue
           FROM order_items i JOIN orders o ON o.id = i.order_id" . $statWhere . "
           GROUP BY i.item ORDER BY revenue DESC";
$st = $conn->prepare($topSql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$topItems = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$activeEvents = $conn->query('SELECT id, name, is_paid, entry_cost FROM events WHERE is_active = 1 ORDER BY id DESC')->fetch_all(MYSQLI_ASSOC);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Madeforu Order Management</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}

  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .nav h1{font-size:22px;font-weight:600}
  .nav .who{font-size:13px;color:#65676b}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:16px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:12px}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(148px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px}
  .stat .l{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:23px;font-weight:600;margin-top:4px}
  .stat-link{text-decoration:none;color:inherit;display:block}
  .stat-link:hover{background:#f0f2f5;text-decoration:none}
  .stat-link.stat-on{border-color:#e11d48;background:#fff5f6}
  .v.green{color:#1a7f4b}.v.red{color:#c0392b}.v.amber{color:#a06a00}.v.rose{color:#be123c}

  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;background:#fff;font-family:inherit}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px;border-color:#1877f2}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}

  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;
              cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .danger:hover{background:#fdeceb}

  .line{display:grid;grid-template-columns:2fr 1fr 110px 40px;gap:10px;align-items:end;margin-bottom:10px}
  .line .lt{padding:9px 10px;background:#f0f2f5;border-radius:6px;font-weight:600;font-size:14px;text-align:right}
  .cbx{position:relative}
  .cbx .cbx-in{width:100%;box-sizing:border-box}
  .cbx .cbx-list{position:absolute;z-index:30;left:0;right:0;top:calc(100% + 2px);max-height:240px;overflow-y:auto;
    background:#fff;border:1px solid #cbd2d9;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.14);display:none}
  .cbx.open .cbx-list{display:block}
  .cbx .cbx-opt{padding:8px 10px;font-size:14px;cursor:pointer;display:flex;justify-content:space-between;gap:8px}
  .cbx .cbx-opt small{color:#7a8590;font-weight:600}
  .cbx .cbx-opt:hover,.cbx .cbx-opt.active{background:#eef2f7}
  .cbx .cbx-opt.hide{display:none}
  .cbx .cbx-empty{padding:8px 10px;font-size:13px;color:#9aa5b1}
  .rm{padding:9px 0;color:#c0392b;border-color:#f0c0bb;text-align:center}
  @media(max-width:640px){.line{grid-template-columns:1fr 1fr}.line .lt{grid-column:1/2}.rm{grid-column:2/3}}

  .summary{background:#f7f8fa;border-radius:8px;padding:14px;margin-bottom:14px}
  .srow{display:flex;justify-content:space-between;padding:5px 0;font-size:14px}
  .srow.big{font-size:17px;font-weight:600;border-top:1px solid #dfe1e5;margin-top:6px;padding-top:10px}
  .srow .bal{color:#c0392b}
  .srow .disc{color:#a06a00}
  .quick-d{display:flex;gap:6px}
  .quick-d button{flex:1;padding:9px 4px;font-size:13px}
  .disc-cell{font-size:13px;color:#a06a00;white-space:nowrap}
  .extra-cell{font-size:13px;color:#1a7f4b;white-space:nowrap}
  /* The amount stays on one line, but the reason underneath is a sentence and
     must wrap -- nowrap on the cell would stretch the whole table. */
  .disc-cell .reason,.extra-cell .reason{font-size:11px;color:#8a8d91;
     white-space:normal;max-width:190px;line-height:1.35;margin-top:2px}

  .check{display:flex;align-items:center;gap:8px;padding:10px;background:#f7f8fa;border-radius:6px}
  .check input{width:auto}
  .check label{margin:0;font-size:14px;color:#1c1e21}
  .check.dim label{color:#8a8d91}

  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:10px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;
     text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:10px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  tr:hover td{background:#fafbfc}
  .scroll{overflow-x:auto}

  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:500;white-space:nowrap}
  .b-paid{background:#e3f5eb;color:#1a7f4b}
  .b-unpaid{background:#fdeceb;color:#c0392b}
  .b-partial{background:#fdf3e0;color:#a06a00}
  .b-yes{background:#e7f0fd;color:#1451a8}
  .b-done{background:#e3f5eb;color:#1a7f4b}
  .b-no{background:#f0f2f5;color:#65676b}
  .toggle{background:none;border:none;padding:0;cursor:pointer}

  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .empty{text-align:center;color:#8a8d91;padding:32px;font-size:14px}
  .actions{display:flex;gap:6px;flex-wrap:wrap}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .filters>div{flex:1;min-width:130px}
  .top-items{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
  .ti{background:#f7f8fa;border-radius:8px;padding:10px 12px}
  .ti .n{font-size:13px;font-weight:500}
  .ti .d{font-size:12px;color:#65676b;margin-top:2px}
  .dhl-chip{font-size:11px;color:#9f1239;background:#ffe4e6;display:inline-block;
            padding:1px 6px;border-radius:10px;margin-top:3px;text-decoration:none;
            font-family:monospace;white-space:nowrap}
  .dhl-chip:hover{background:#fecdd3;text-decoration:none}
  .items-cell{font-size:13px;max-width:220px}
  .phone-cell{white-space:nowrap}
  .phone-cell a{color:#1877f2;text-decoration:none}
  .phone-cell a:hover{text-decoration:underline}
  .wa-icon{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
           margin-left:6px;border-radius:50%;background:#e8f7ee;color:#1fa855;vertical-align:middle}
  .wa-icon:hover{background:#25d366;color:#fff;text-decoration:none}
  .btn-wa{background:#25d366;color:#fff;border-color:#25d366;display:inline-flex;align-items:center;gap:6px}
  .btn-wa:hover{background:#1fa855;color:#fff}
  .btn-track{background:#1877f2;color:#fff;border-color:#1877f2;display:inline-flex;align-items:center;gap:6px}
  .btn-track:hover{background:#166fe5;color:#fff}
  .btn-track.copied{background:#1a7f4b;border-color:#1a7f4b}
  .hint{font-size:12px;color:#8a8d91;margin-top:6px}
  .event-switch{display:flex;align-items:center;gap:8px}
  .event-switch label{margin:0;font-size:13px;color:#65676b;white-space:nowrap}
  .event-switch select{width:auto;min-width:180px}
</style>
</head>
<body>
<?php
  $PAGE  = 'orders';
  $TITLE = 'Orders';
  // Event quick-switch dropdown for the top bar.
  ob_start(); ?>
  <form method="get" class="event-switch">
    <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
    <?php if ($fPaid !== ''): ?><input type="hidden" name="paid" value="<?= e($fPaid) ?>"><?php endif; ?>
    <?php if ($fReady !== ''): ?><input type="hidden" name="ready" value="<?= e($fReady) ?>"><?php endif; ?>
    <?php if ($fDelivered !== ''): ?><input type="hidden" name="delivered" value="<?= e($fDelivered) ?>"><?php endif; ?>
    <?php if ($fDispatch !== ''): ?><input type="hidden" name="dispatch" value="<?= e($fDispatch) ?>"><?php endif; ?>
    <label for="event-top">Viewing</label>
    <select id="event-top" name="event" onchange="this.form.submit()">
      <option value="" <?= $fEvent===''?'selected':'' ?>>All orders</option>
      <option value="0" <?= $fEvent==='0'?'selected':'' ?>>Offline only</option>
      <?php foreach ($activeEvents as $ev): ?>
        <option value="<?= (int)$ev['id'] ?>" <?= (string)$fEvent===(string)$ev['id']?'selected':'' ?>>
          <?= e($ev['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php $TOPBAR_HTML = ob_get_clean();
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="l">Total orders</div><div class="v"><?= (int)$stats['total_orders'] ?></div></div>
    <div class="stat"><div class="l">Items sold</div><div class="v"><?= (int)$qty ?></div></div>
    <div class="stat"><div class="l">Gross value</div><div class="v"><?= money($stats['gross_value']) ?></div></div>
    <div class="stat"><div class="l">Discounts given</div><div class="v amber"><?= money($stats['total_discount']) ?></div></div>
    <div class="stat"><div class="l">Net payable</div><div class="v"><?= money($stats['total_value']) ?></div></div>
    <div class="stat"><div class="l">Collected</div><div class="v green"><?= money($stats['collected']) ?></div></div>
    <div class="stat"><div class="l">Outstanding</div><div class="v red"><?= money($stats['outstanding']) ?></div></div>
    <div class="stat"><div class="l">Fully paid</div><div class="v green"><?= (int)$stats['paid_count'] ?></div></div>
    <div class="stat"><div class="l">Part paid</div><div class="v amber"><?= (int)$stats['partial_count'] ?></div></div>
    <div class="stat"><div class="l">Unpaid</div><div class="v red"><?= (int)$stats['unpaid_count'] ?></div></div>
    <div class="stat"><div class="l">Not ready</div><div class="v amber"><?= (int)$stats['not_ready'] ?></div></div>
    <div class="stat"><div class="l">Awaiting pickup</div><div class="v amber"><?= (int)$stats['ready_undelivered'] ?></div></div>
    <?php
      // Toggle the dispatched filter without discarding whatever else is
      // active. A bare "?dispatch=1" would silently reset the event view.
      $dq = $_GET;
      if ($fDispatch === '1') unset($dq['dispatch']);   // already on -> click clears it
      else                    $dq['dispatch'] = '1';
      $dHref = '?' . http_build_query($dq);
    ?>
    <a class="stat stat-link <?= $fDispatch === '1' ? 'stat-on' : '' ?>" href="<?= e($dHref) ?>"
       title="<?= $fDispatch === '1' ? 'Clear the dispatched filter' : 'Show only dispatched orders' ?>">
      <div class="l">Online dispatched</div>
      <div class="v rose"><?= (int)$stats['dispatched_count'] ?></div>
    </a>
  </div>

  <?php if ($topItems): ?>
  <div class="card">
    <h2>Sales by item</h2>
    <div class="top-items">
      <?php foreach ($topItems as $t): ?>
        <div class="ti">
          <div class="n"><?= e($t['item']) ?></div>
          <div class="d"><?= (int)$t['qty'] ?> sold · <?= money($t['revenue']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>New order</h2>
    <form method="post" action="save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="grid" style="margin-bottom:16px">
        <div>
          <label for="name">Customer name</label>
          <input id="name" name="name" required maxlength="120" placeholder="Full name">
        </div>
        <div>
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" required pattern="[0-9]{10}" maxlength="20"
                 inputmode="numeric" placeholder="10-digit number">
        </div>
        <div>
          <label for="event_id">Event</label>
          <select id="event_id" name="event_id">
            <option value="">Offline / walk-up</option>
            <?php foreach ($activeEvents as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= (string)$fEvent === (string)$ev['id'] ? 'selected' : '' ?>>
                <?= e($ev['name']) ?><?= $ev['is_paid'] ? ' (entry ' . money($ev['entry_cost']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php $CHANNELS = channels_all($conn, true); if ($CHANNELS): ?>
        <div>
          <label for="channel_id">Channel</label>
          <select id="channel_id" name="channel_id" onchange="channelChanged()">
            <option value="">Not recorded</option>
            <?php foreach ($CHANNELS as $ch): ?>
              <option value="<?= (int)$ch['id'] ?>"><?= e($ch['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div id="chan-hint" class="muted" style="font-size:12px;margin-top:4px"></div>
        </div>
        <?php endif; ?>
      </div>

      <label style="margin-bottom:8px">Products</label>
      <div id="lines"></div>
      <button type="button" class="btn" onclick="addLine()" style="margin-bottom:16px">+ Add product</button>

      <div class="grid" style="margin-bottom:16px">
        <div>
          <label for="extra_charge">Additional charges (₹)</label>
          <input id="extra_charge" name="extra_charge" type="number" step="0.01" min="0"
                 value="0" oninput="calc()">
        </div>
        <div>
          <label for="extra_charge_reason">Charge reason (optional)</label>
          <input id="extra_charge_reason" name="extra_charge_reason" maxlength="120"
                 placeholder="e.g. delivery, rush fee, packing">
        </div>
        <div></div>
      </div>

      <div class="grid" style="margin-bottom:16px">
        <div>
          <label for="discount">Discount (₹ off)</label>
          <input id="discount" name="discount" type="number" step="0.01" min="0"
                 value="0" oninput="calc()">
        </div>
        <div>
          <label for="discount_reason">Discount reason (optional)</label>
          <input id="discount_reason" name="discount_reason" maxlength="120"
                 placeholder="e.g. bulk order, friend">
        </div>
        <div>
          <label>&nbsp;</label>
          <div class="quick-d">
            <button type="button" class="btn" onclick="discPct(10)">10%</button>
            <button type="button" class="btn" onclick="discPct(20)">20%</button>
            <button type="button" class="btn" onclick="setDisc(0)">Clear</button>
          </div>
        </div>
      </div>

      <div class="summary">
        <div class="srow"><span>Subtotal</span><span id="s-sub">₹0.00</span></div>
        <div class="srow" id="row-extra" style="display:none">
          <span>Additional charges</span><span id="s-extra">+₹0.00</span>
        </div>
        <div class="srow" id="row-disc" style="display:none">
          <span>Discount</span><span class="disc" id="s-disc">-₹0.00</span>
        </div>
        <div class="srow" style="font-weight:600"><span>Order total</span><span id="s-total">₹0.00</span></div>
        <div class="srow"><span>Paying now</span><span id="s-paid">₹0.00</span></div>
        <div class="srow big"><span>Balance due</span><span class="bal" id="s-bal">₹0.00</span></div>
      </div>

      <div class="grid" style="margin-bottom:16px">
        <div>
          <label for="paid_amount">Amount paying now</label>
          <input id="paid_amount" name="paid_amount" type="number" step="0.01" min="0" value="0" oninput="calc()">
        </div>
        <div>
          <label for="payment_mode">Payment mode</label>
          <select id="payment_mode" name="payment_mode">
            <?php foreach ($PAYMENT_MODES as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>&nbsp;</label>
          <button type="button" class="btn" onclick="payFull()" style="width:100%">Pay full amount</button>
        </div>
        <div>
          <label for="notes">Notes (optional)</label>
          <input id="notes" name="notes" maxlength="255" placeholder="Custom text, colour…">
        </div>
      </div>

      <label style="margin-bottom:8px">Fulfilment status</label>
      <div class="grid">
        <div class="check" id="wrap_ready">
          <input type="checkbox" id="is_ready" name="is_ready" value="1" onchange="syncStatus('ready')">
          <label for="is_ready">Order ready</label>
        </div>
        <div class="check">
          <input type="checkbox" id="is_delivered" name="is_delivered" value="1" onchange="syncStatus('delivered')">
          <label for="is_delivered">Handed over</label>
        </div>
      </div>
      <p class="hint">Ticking "handed over" marks the order ready automatically.</p>

      <button type="submit" class="primary" style="margin-top:16px">Create order</button>
    </form>
  </div>

  <div class="card">
    <h2>Search &amp; filter</h2>
    <form method="get">
      <div class="filters">
        <div style="flex:2">
          <label for="search">Phone / name / order no.</label>
          <input id="search" name="search" value="<?= e($search) ?>" placeholder="e.g. 9876543210">
        </div>
        <div>
          <label for="paid">Payment</label>
          <select id="paid" name="paid">
            <option value="">All</option>
            <?php foreach (['paid'=>'Fully paid','partial'=>'Part paid','unpaid'=>'Unpaid'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $fPaid===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="ready">Ready</label>
          <select id="ready" name="ready">
            <option value="">All</option>
            <option value="1" <?= $fReady==='1'?'selected':'' ?>>Ready</option>
            <option value="0" <?= $fReady==='0'?'selected':'' ?>>Not ready</option>
          </select>
        </div>
        <div>
          <label for="delivered">Delivery</label>
          <select id="delivered" name="delivered">
            <option value="">All</option>
            <option value="1" <?= $fDelivered==='1'?'selected':'' ?>>Handed over</option>
            <option value="0" <?= $fDelivered==='0'?'selected':'' ?>>Pending</option>
          </select>
        </div>
        <div>
          <label for="dispatch">Courier</label>
          <select id="dispatch" name="dispatch">
            <option value="">All</option>
            <option value="1" <?= $fDispatch==='1'?'selected':'' ?>>Dispatched</option>
            <option value="0" <?= $fDispatch==='0'?'selected':'' ?>>Not dispatched</option>
          </select>
        </div>
        <div>
          <label for="fevent">Event</label>
          <select id="fevent" name="event">
            <option value="">All</option>
            <option value="0" <?= $fEvent==='0'?'selected':'' ?>>Offline only</option>
            <?php foreach ($activeEvents as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= (string)$fEvent===(string)$ev['id']?'selected':'' ?>>
                <?= e($ev['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:0;display:flex;gap:8px">
          <button type="submit" class="primary">Search</button>
          <a class="btn" href="index.php">Clear</a>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Orders (<?= count($orders) ?>)</h2>
    <?php if (!$orders): ?>
      <p class="empty">No orders found.</p>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th>Order no.</th><th>Customer</th><th>Phone</th><th>Products</th>
            <th>Subtotal</th><th>Discount</th><th>Add. charges</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th>
            <th>Ready</th><th>Handed over</th><th>By</th><th>Time</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o):
          $bal = (float)$o['total'] - (float)$o['paid_amount'];
          $st  = pay_status((float)$o['total'], (float)$o['paid_amount']);
          $wa  = whatsapp_link($o['phone'], whatsapp_message($o, $o['item_list'] ?? ''));
        ?>
          <tr>
            <td style="font-family:monospace;font-size:12px"><?= e($o['order_no']) ?></td>
            <td>
              <?= e($o['name']) ?>
              <?php if (!empty($o['channel_name'])): ?>
                <div style="font-size:11px;color:#7a5800;background:#fff3e0;display:inline-block;padding:1px 6px;border-radius:10px;margin-top:2px"><?= e($o['channel_name']) ?></div>
              <?php endif; ?>
              <?php if (!empty($o['event_name'])): ?>
                <div style="font-size:11px;color:#1451a8;background:#e7f0fd;display:inline-block;padding:1px 6px;border-radius:10px;margin-top:2px"><?= e($o['event_name']) ?></div>
              <?php endif; ?>
              <?php if ($o['notes']): ?><div style="font-size:12px;color:#65676b"><?= e($o['notes']) ?></div><?php endif; ?>
              <?php if ($dhl = delhivery_link($o['awb'] ?? null)): ?>
                <a class="dhl-chip" href="<?= e($dhl) ?>" target="_blank" rel="noopener"
                   title="Track <?= e($o['awb']) ?> on Delhivery">
                  Delhivery · <?= e($o['awb']) ?>
                </a>
              <?php endif; ?>
            </td>
            <td class="phone-cell">
              <a href="tel:<?= e($o['phone']) ?>"><?= e($o['phone']) ?></a>
              <?php if ($wa): ?>
                <a class="wa-icon" href="<?= e($wa) ?>" target="_blank" rel="noopener"
                   title="Message on WhatsApp" aria-label="Message <?= e($o['name']) ?> on WhatsApp">
                  <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true">
                    <path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15s-.77.96-.94 1.16c-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.01-1.04 2.47 1.06 2.86 1.21 3.06c.15.2 2.09 3.2 5.08 4.48.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.69.25-1.28.17-1.41-.07-.13-.27-.2-.57-.35z"/>
                    <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.05-1.32A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.1.81.83-3.02-.2-.31A8.2 8.2 0 1 1 12 20.2z"/>
                  </svg>
                </a>
              <?php endif; ?>
            </td>
            <td class="items-cell"><?= e($o['item_list'] ?? '—') ?></td>
            <td><?= money($o['subtotal']) ?></td>
            <td class="disc-cell">
              <?php if ((float)$o['discount'] > 0.001): ?>
                -<?= money($o['discount']) ?>
                <?php if ($o['discount_reason']): ?>
                  <div class="reason"><?= e($o['discount_reason']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#c8ccd1">—</span>
              <?php endif; ?>
            </td>
            <td class="extra-cell">
              <?php if ((float)$o['extra_charge'] > 0.001): ?>
                +<?= money($o['extra_charge']) ?>
                <?php if ($o['extra_charge_reason']): ?>
                  <div class="reason"><?= e($o['extra_charge_reason']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#c8ccd1">—</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:600"><?= money($o['total']) ?></td>
            <td style="color:#1a7f4b"><?= money($o['paid_amount']) ?></td>
            <td style="color:<?= $bal > 0.001 ? '#c0392b' : '#65676b' ?>;font-weight:<?= $bal > 0.001 ? '600':'400' ?>">
              <?= money(max($bal, 0)) ?>
            </td>
            <td><span class="badge b-<?= $st ?>"><?= ['paid'=>'Paid','partial'=>'Part paid','unpaid'=>'Unpaid'][$st] ?></span></td>
            <td>
              <form method="post" action="save.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="field" value="is_ready">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <button class="toggle" title="Toggle ready">
                  <span class="badge <?= $o['is_ready']?'b-yes':'b-no' ?>"><?= $o['is_ready']?'Ready':'Pending' ?></span>
                </button>
              </form>
            </td>
            <td>
              <form method="post" action="save.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="field" value="is_delivered">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <button class="toggle" title="Toggle handover">
                  <span class="badge <?= $o['is_delivered']?'b-done':'b-no' ?>"><?= $o['is_delivered']?'Handed over':'Pending' ?></span>
                </button>
              </form>
            </td>
            <td style="font-size:12px;color:#65676b"><?= e($o['admin_name'] ?? '—') ?></td>
            <td style="font-size:12px;color:#65676b;white-space:nowrap"><?= date('d M, g:i a', strtotime($o['created_at'])) ?></td>
            <td>
              <div class="actions">
                <button type="button" class="btn btn-track" onclick="copyTrack(this, '<?= e($o['order_no']) ?>')"
                        style="padding:5px 10px;font-size:13px" title="Copy tracking link">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                  </svg>
                  Track URL
                </button>
                <a class="btn" href="edit.php?id=<?= (int)$o['id'] ?>" style="padding:5px 10px;font-size:13px">Edit</a>
                <form method="post" action="save.php" onsubmit="return confirm('Delete this order?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                  <button class="danger" style="padding:5px 10px;font-size:13px">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div style="margin-bottom:32px">
    <a class="btn" href="export.php?<?= http_build_query($_GET) ?>">Export CSV</a>
  </div>

<?php require 'layout_end.php'; ?>

<script>

/* Copy the public tracking URL for an order to the clipboard. */
function copyTrack(btn, orderNo) {
  const url = window.location.origin + '/track.php?q=' + encodeURIComponent(orderNo);
  const done = () => {
    const original = btn.innerHTML;
    btn.classList.add('copied');
    btn.innerHTML = '✓ Copied!';
    setTimeout(() => { btn.classList.remove('copied'); btn.innerHTML = original; }, 1500);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done).catch(() => fallbackCopy(url, done));
  } else {
    fallbackCopy(url, done);
  }
}
function fallbackCopy(text, done) {
  const t = document.createElement('textarea');
  t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
  document.body.appendChild(t); t.focus(); t.select();
  try { document.execCommand('copy'); done(); }
  catch (e) { prompt('Copy this tracking link:', text); }
  document.body.removeChild(t);
}

/* Normalise an Indian mobile as the user types or pastes.
   Strips spaces, +, dashes; drops a leading country code or 0;
   keeps the last 10 digits. A genuine 10-digit number is left alone. */
function normalisePhone(el) {
  let d = el.value.replace(/\D/g, '');
  if (d.length > 10 && d.startsWith('91')) d = d.slice(2);
  d = d.replace(/^0+/, '');
  if (d.length > 10) d = d.slice(-10);
  // Only rewrite when something actually changed, so typing at the end
  // does not fight the cursor.
  if (el.value !== d) el.value = d;
}
function attachPhone(id) {
  const el = document.getElementById(id);
  if (!el) return;
  // setTimeout lets the pasted text land in the field before we clean it.
  el.addEventListener('input', () => setTimeout(() => normalisePhone(el), 0));
  el.addEventListener('paste', () => setTimeout(() => normalisePhone(el), 0));
}

const ITEMS = <?= json_encode($ITEMS) ?>;
/* Per-channel price overrides, so the running total in this form is the
   total save.php will actually charge. Pricing the lines from the
   catalogue here while the server prices them from the channel would
   disagree only AFTER the sale was saved, which is the worst moment to
   find out. */
const CHANNEL_PRICES = <?= json_encode(channel_prices_all($conn)) ?>;
const MAXQ  = <?= max($QTY_OPTIONS) ?>;
let seq = 0;

/* What this product sells for through the channel now selected. */
function rateFor(name) {
  const sel = document.getElementById('channel_id');
  const cid = sel ? sel.value : '';
  if (cid && CHANNEL_PRICES[cid] && CHANNEL_PRICES[cid][name] !== undefined) {
    return CHANNEL_PRICES[cid][name];
  }
  return ITEMS[name] !== undefined ? ITEMS[name] : 0;
}

/* The channel changed, so every line is worth something different. */
function channelChanged() {
  document.querySelectorAll('.line select[name="item[]"]').forEach(sel => {
    [...sel.options].forEach(o => {
      if (!o.value) return;
      const r = rateFor(o.value);
      o.dataset.price = r;
      o.textContent = o.value + ' — ₹' + r;
    });
  });
  const hint = document.getElementById('chan-hint');
  if (hint) {
    const sel = document.getElementById('channel_id');
    const cid = sel ? sel.value : '';
    const n   = (cid && CHANNEL_PRICES[cid]) ? Object.keys(CHANNEL_PRICES[cid]).length : 0;
    hint.textContent = n
      ? sel.options[sel.selectedIndex].text + ' has its own price for ' + n
        + ' product' + (n === 1 ? '' : 's') + '.'
      : '';
  }
  calc();
}

/* Delivered implies ready. The server enforces this too. */
function syncStatus(changed) {
  const r = document.getElementById('is_ready');
  const d = document.getElementById('is_delivered');
  if (changed === 'delivered' && d.checked) r.checked = true;
  if (changed === 'ready' && !r.checked)    d.checked = false;
  document.getElementById('wrap_ready').classList.toggle('dim', d.checked);
}

function addLine(item = '', qty = 1) {
  const id = 'ln' + (seq++);
  const opts = Object.keys(ITEMS).map(n => {
    const p = rateFor(n);
    return `<option value="${escAttr(n)}" data-price="${p}" ${n === item ? 'selected' : ''}>${esc(n)} — ₹${p}</option>`;
  }).join('');

  const div = document.createElement('div');
  div.className = 'line';
  div.id = id;
  div.innerHTML = `
    <div class="cbx">
      <select name="item[]" required onchange="calc()" style="display:none">
        <option value="">Select product</option>${opts}</select>
      <input type="text" class="cbx-in" placeholder="Type to search product…" autocomplete="off"
             value="${item ? escAttr(item) : ''}">
      <div class="cbx-list"></div>
    </div>
    <div><input name="quantity[]" type="number" min="1" max="999" step="1" required
                value="${qty}" inputmode="numeric" oninput="calc()"></div>
    <div class="lt">₹0.00</div>
    <button type="button" class="btn rm" onclick="rmLine('${id}')" aria-label="Remove">×</button>`;
  document.getElementById('lines').appendChild(div);
  initCbx(div.querySelector('.cbx'));
  calc();
}

/* ---- searchable product combobox ---------------------------------------- */
function esc(s){return String(s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function escAttr(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}

/* Fuzzy match: every query char must appear in order in the target.
   Lets "squ" hit "Square Magnet", "acmg" hit "Acrylic Magnet", etc. */
function cbxMatch(q, name){
  q = q.toLowerCase().replace(/\s+/g,''); if(!q) return true;
  const t = name.toLowerCase(); let i = 0;
  for(const ch of t){ if(ch === q[i]) i++; if(i === q.length) return true; }
  return false;
}

function initCbx(cbx){
  const sel  = cbx.querySelector('select');
  const inp  = cbx.querySelector('.cbx-in');
  const list = cbx.querySelector('.cbx-list');
  const items = Object.entries(ITEMS);

  function render(q){
    const hits = items.filter(([n]) => cbxMatch(q, n));
    list.innerHTML = hits.length
      ? hits.map(([n]) =>
          `<div class="cbx-opt" data-val="${escAttr(n)}">${esc(n)}<small>₹${rateFor(n)}</small></div>`).join('')
      : `<div class="cbx-empty">No product matches “${esc(q)}”</div>`;
  }
  function open(){ render(inp.value); cbx.classList.add('open'); }
  function close(){ cbx.classList.remove('open'); }
  function pick(val){
    sel.value = val;
    inp.value = val;
    close();
    calc();
  }

  inp.addEventListener('focus', open);
  inp.addEventListener('input', () => { open(); });
  inp.addEventListener('keydown', e => {
    const act = list.querySelector('.cbx-opt.active');
    const all = [...list.querySelectorAll('.cbx-opt')];
    if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){
      e.preventDefault(); if(!all.length) return;
      let i = all.indexOf(act);
      i = e.key === 'ArrowDown' ? Math.min(i+1, all.length-1) : Math.max(i-1, 0);
      if(i < 0) i = 0;
      all.forEach(o => o.classList.remove('active'));
      all[i].classList.add('active'); all[i].scrollIntoView({block:'nearest'});
    } else if(e.key === 'Enter'){
      if(cbx.classList.contains('open')){ e.preventDefault(); (act || all[0]) && pick((act||all[0]).dataset.val); }
    } else if(e.key === 'Escape'){ close(); }
  });
  list.addEventListener('mousedown', e => {
    const o = e.target.closest('.cbx-opt'); if(o){ e.preventDefault(); pick(o.dataset.val); }
  });
  inp.addEventListener('blur', () => setTimeout(() => {
    // If typed text isn't an exact product, keep whatever's selected in sync.
    if(inp.value !== sel.value){
      const exact = items.find(([n]) => n.toLowerCase() === inp.value.trim().toLowerCase());
      if(exact) pick(exact[0]); else { inp.value = sel.value; }
    }
    close();
  }, 150));
}

function rmLine(id) {
  const lines = document.getElementById('lines');
  if (lines.children.length <= 1) { alert('An order needs at least one product.'); return; }
  document.getElementById(id).remove();
  calc();
}

function lineTotals() {
  let total = 0;
  document.querySelectorAll('.line').forEach(l => {
    const sel  = l.querySelector('select[name="item[]"]');
    const rate = sel.value ? parseFloat(rateFor(sel.value)) || 0 : 0;
    const q    = parseInt(l.querySelector('[name="quantity[]"]').value) || 0;
    const lt   = rate * q;
    l.querySelector('.lt').textContent = '₹' + lt.toFixed(2);
    total += lt;
  });
  return total;
}

function calc() {
  const sub     = lineTotals();
  const discEl  = document.getElementById('discount');
  const extraEl = document.getElementById('extra_charge');
  const paidEl  = document.getElementById('paid_amount');

  // Additional charges are never negative -- that would be a discount.
  let extra = parseFloat(extraEl.value) || 0;
  if (extra < 0) { extra = 0; extraEl.value = '0'; }

  // Mirrors the server: the discount ceiling is the whole charged base, so
  // a discount can cancel a delivery fee.
  const base = sub + extra;
  let disc = parseFloat(discEl.value) || 0;
  if (disc < 0)    { disc = 0;    discEl.value = '0'; }
  if (disc > base) { disc = base; discEl.value = base.toFixed(2); }

  const total = base - disc;

  let paid = parseFloat(paidEl.value) || 0;
  if (paid > total) { paid = total; paidEl.value = total.toFixed(2); }

  document.getElementById('s-sub').textContent    = '₹' + sub.toFixed(2);
  document.getElementById('s-extra').textContent  = '+₹' + extra.toFixed(2);
  document.getElementById('row-extra').style.display = extra > 0 ? 'flex' : 'none';
  document.getElementById('s-disc').textContent   = '-₹' + disc.toFixed(2);
  document.getElementById('row-disc').style.display = disc > 0 ? 'flex' : 'none';
  document.getElementById('s-total').textContent  = '₹' + total.toFixed(2);
  document.getElementById('s-paid').textContent   = '₹' + paid.toFixed(2);
  document.getElementById('s-bal').textContent    = '₹' + (total - paid).toFixed(2);
}

function setDisc(v) {
  document.getElementById('discount').value = v.toFixed(2);
  calc();
}

/* Percentage buttons are a shortcut only. What is stored is a flat amount.
   The percentage applies to the goods, not to delivery or rush fees -- you
   discount what you sell, not what it costs to send. */
function discPct(pct) {
  setDisc(lineTotals() * pct / 100);
}

function payFull() {
  const sub   = lineTotals();
  const extra = parseFloat(document.getElementById('extra_charge').value) || 0;
  const disc  = parseFloat(document.getElementById('discount').value) || 0;
  document.getElementById('paid_amount').value = Math.max(sub + extra - disc, 0).toFixed(2);
  calc();
}

addLine();
attachPhone('phone');
</script>
</body>
</html>