<?php
require 'config.php';
$me = require_login();

// ── Status vocabulary ──────────────────────────────────────
$STATUSES = [
    'label_pending'    => 'Label not downloaded',
    'label_downloaded' => 'Label downloaded',
    'out_for_delivery' => 'Out for delivery',
    'shipped'          => 'Shipped',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
    'returned'         => 'Returned',
    'rto'              => 'RTO (came back)',
    'on_hold'          => 'On hold',
];

// Parse "3-June-2026", "12-Jul-2026", "1-July-2026" etc. to Y-m-d, or null.
function parse_date(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $name = trim($_POST['customer_name'] ?? '');
            if ($name === '') throw new Exception('Customer name is required.');

            $productId = (int)($_POST['product_id'] ?? 0);
            $p = $conn->prepare('SELECT name FROM meesho_products WHERE id = ?');
            $p->bind_param('i', $productId);
            $p->execute();
            $prow = $p->get_result()->fetch_assoc();
            $p->close();
            if (!$prow) throw new Exception('Pick a valid product.');
            $productName = $prow['name'];

            $qty = (int)($_POST['quantity'] ?? 1);
            if ($qty < 1 || $qty > 999) throw new Exception('Quantity must be 1–999.');

            $status = $_POST['status'] ?? 'label_pending';
            if (!isset($STATUSES[$status])) $status = 'label_pending';

            $phone       = trim($_POST['phone'] ?? '');
            $orderCode   = trim($_POST['order_id'] ?? '');
            $subOrder    = trim($_POST['sub_order_id'] ?? '');
            $packetQr    = trim($_POST['packet_qr'] ?? '');
            $awb         = trim($_POST['awb'] ?? '');
            $orderDate   = parse_date($_POST['order_date'] ?? '');
            $dispatch    = parse_date($_POST['dispatch_date'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            // Settlement only meaningful when delivered; store if provided.
            $settRaw = trim($_POST['settlement_price'] ?? '');
            $settlement = ($settRaw === '') ? null : round((float)$settRaw, 2);
            if ($settlement !== null && $settlement < 0) throw new Exception('Settlement cannot be negative.');

            $s = $conn->prepare(
                'INSERT INTO meesho_orders
                   (order_date, customer_name, phone, product_id, product_name, quantity,
                    order_id, sub_order_id, packet_qr, awb, dispatch_date, status,
                    settlement_price, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $phoneV    = ($phone     !== '') ? $phone     : null;
            $orderCV   = ($orderCode !== '') ? $orderCode : null;
            $subOV     = ($subOrder  !== '') ? $subOrder  : null;
            $qrV       = ($packetQr  !== '') ? $packetQr  : null;
            $awbV      = ($awb       !== '') ? $awb       : null;
            $notesV    = ($notes     !== '') ? $notes     : null;
            $s->bind_param(
                'ssisssssssssdsi',
                $orderDate, $name, $phoneV, $productId, $productName, $qty,
                $orderCV, $subOV, $qrV, $awbV, $dispatch, $status,
                $settlement, $notesV, $me['id']
            );
            $s->execute(); $s->close();
            flash("Meesho order for $name added.");

        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid order.');

            $name = trim($_POST['customer_name'] ?? '');
            if ($name === '') throw new Exception('Customer name is required.');

            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId === 0) {
                // Product is inactive/renamed — keep whatever the order already has.
                $cur = $conn->prepare('SELECT product_id, product_name FROM meesho_orders WHERE id = ?');
                $cur->bind_param('i', $id);
                $cur->execute();
                $crow = $cur->get_result()->fetch_assoc();
                $cur->close();
                if (!$crow) throw new Exception('Order not found.');
                $productId   = $crow['product_id'] !== null ? (int)$crow['product_id'] : null;
                $productName = $crow['product_name'];
            } else {
                $p = $conn->prepare('SELECT name FROM meesho_products WHERE id = ?');
                $p->bind_param('i', $productId);
                $p->execute();
                $prow = $p->get_result()->fetch_assoc();
                $p->close();
                if (!$prow) throw new Exception('Pick a valid product.');
                $productName = $prow['name'];
            }

            $qty = (int)($_POST['quantity'] ?? 1);
            if ($qty < 1 || $qty > 999) throw new Exception('Quantity must be 1–999.');

            $status = $_POST['status'] ?? 'label_pending';
            if (!isset($STATUSES[$status])) $status = 'label_pending';

            $phoneV   = trim($_POST['phone'] ?? '');        $phoneV = $phoneV !== '' ? $phoneV : null;
            $orderCV  = trim($_POST['order_id'] ?? '');      $orderCV = $orderCV !== '' ? $orderCV : null;
            $subOV    = trim($_POST['sub_order_id'] ?? '');  $subOV = $subOV !== '' ? $subOV : null;
            $qrV      = trim($_POST['packet_qr'] ?? '');      $qrV = $qrV !== '' ? $qrV : null;
            $awbV     = trim($_POST['awb'] ?? '');            $awbV = $awbV !== '' ? $awbV : null;
            $orderDate = parse_date($_POST['order_date'] ?? '');
            $dispatch  = parse_date($_POST['dispatch_date'] ?? '');
            $notesV   = trim($_POST['notes'] ?? '');          $notesV = $notesV !== '' ? $notesV : null;

            $settRaw = trim($_POST['settlement_price'] ?? '');
            $settlement = ($settRaw === '') ? null : round((float)$settRaw, 2);
            if ($settlement !== null && $settlement < 0) throw new Exception('Settlement cannot be negative.');

            // Editing a panel-created row with a real customer name clears the flag.
            $clearReview = ($name !== '' && $name !== '(from panel)') ? 0 : 1;
            $s = $conn->prepare(
                'UPDATE meesho_orders SET
                    order_date=?, customer_name=?, phone=?, product_id=?, product_name=?, quantity=?,
                    order_id=?, sub_order_id=?, packet_qr=?, awb=?, dispatch_date=?, status=?,
                    settlement_price=?, notes=?,
                    needs_review = LEAST(needs_review, ?)
                 WHERE id=?'
            );
            $s->bind_param(
                'ssisssssssssdsii',
                $orderDate, $name, $phoneV, $productId, $productName, $qty,
                $orderCV, $subOV, $qrV, $awbV, $dispatch, $status,
                $settlement, $notesV, $clearReview, $id
            );
            $s->execute(); $s->close();
            flash("Order for $name updated.");

        } elseif ($action === 'set_ids') {
            // Quick fill of packet QR + AWB after creation.
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid order.');
            $qrV  = trim($_POST['packet_qr'] ?? ''); $qrV  = $qrV  !== '' ? $qrV  : null;
            $awbV = trim($_POST['awb'] ?? '');        $awbV = $awbV !== '' ? $awbV : null;
            $s = $conn->prepare('UPDATE meesho_orders SET packet_qr = ?, awb = ? WHERE id = ?');
            $s->bind_param('ssi', $qrV, $awbV, $id);
            $s->execute(); $s->close();
            flash('Tracking details saved.');

        } elseif ($action === 'set_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($id < 1 || !isset($STATUSES[$status])) throw new Exception('Invalid update.');
            $s = $conn->prepare('UPDATE meesho_orders SET status = ? WHERE id = ?');
            $s->bind_param('si', $status, $id);
            $s->execute(); $s->close();
            flash('Status updated.');

        } elseif ($action === 'set_settlement') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid order.');
            $raw = trim($_POST['settlement_price'] ?? '');
            if ($raw === '') {
                $s = $conn->prepare('UPDATE meesho_orders SET settlement_price = NULL WHERE id = ?');
                $s->bind_param('i', $id);
            } else {
                $val = round((float)$raw, 2);
                if ($val < 0) throw new Exception('Settlement cannot be negative.');
                $s = $conn->prepare('UPDATE meesho_orders SET settlement_price = ? WHERE id = ?');
                $s->bind_param('di', $val, $id);
            }
            $s->execute(); $s->close();
            flash('Settlement saved.');

        } elseif ($action === 'toggle_return_cost') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid order.');
            $s = $conn->prepare('UPDATE meesho_orders SET return_cost_lost = 1 - return_cost_lost WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute(); $s->close();
            flash('Return updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Invalid order.');
            $s = $conn->prepare('DELETE FROM meesho_orders WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute(); $s->close();
            flash('Order deleted.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    // Preserve the current filter across the redirect.
    $q = isset($_POST['ret']) && $_POST['ret'] !== '' ? '?f=' . urlencode($_POST['ret']) : '';
    header('Location: meesho.php' . $q);
    exit;
}

// ── Read side ──────────────────────────────────────────────
$activeProducts = $conn->query(
    'SELECT id, name, unit_cost FROM meesho_products WHERE is_active = 1 ORDER BY sort_order, name'
)->fetch_all(MYSQLI_ASSOC);

// cost lookup by product name (live) for profit maths
$costByName = [];
$allProds = $conn->query('SELECT name, unit_cost FROM meesho_products')->fetch_all(MYSQLI_ASSOC);
foreach ($allProds as $r) $costByName[$r['name']] = (float)$r['unit_cost'];

// Filter: a status key, or 'need_label', or 'need_price', or '' = all.
$f = $_GET['f'] ?? '';
$where = '';
if (isset($STATUSES[$f])) {
    $where = "WHERE status = '" . $conn->real_escape_string($f) . "'";
} elseif ($f === 'need_label') {
    $where = "WHERE status = 'label_pending'";
} elseif ($f === 'need_price') {
    $where = "WHERE status IN ('delivered','shipped') AND settlement_price IS NULL";
} elseif ($f === 'review') {
    $where = "WHERE needs_review = 1";
}

$orders = $conn->query(
    "SELECT * FROM meesho_orders $where
     ORDER BY (order_date IS NULL), order_date DESC, id DESC"
)->fetch_all(MYSQLI_ASSOC);

// ── Summary buckets (always over ALL rows, ignoring filter) ─
$counts = array_fill_keys(array_keys($STATUSES), 0);
$needPrice = 0; $reviewCount = 0;
$totalProfit = 0.0; $deliveredWithPrice = 0;
$res = $conn->query('SELECT status, quantity, product_name, settlement_price,
                            return_cost_lost, needs_review FROM meesho_orders');
while ($row = $res->fetch_assoc()) {
    $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    if ((int)$row['needs_review'] === 1) $reviewCount++;
    $cost = ($costByName[$row['product_name']] ?? 0) * max(1, (int)$row['quantity']);

    if (in_array($row['status'], ['delivered', 'shipped'], true)) {
        if ($row['settlement_price'] === null) {
            $needPrice++;
        } else {
            $totalProfit += (float)$row['settlement_price'] - $cost;
            $deliveredWithPrice++;
        }
    } elseif (in_array($row['status'], ['returned', 'rto'], true)) {
        // Goods were made and shipped, so the manufacturing cost is spent.
        // The panel gives a NEGATIVE settlement on returns (Meesho claws the
        // sale back and bills return shipping), so add it as-is when known.
        if ($row['settlement_price'] !== null) {
            $totalProfit += (float)$row['settlement_price'] - $cost;
        } elseif ((int)$row['return_cost_lost'] === 1) {
            $totalProfit -= $cost;   // manual fallback for pre-panel rows
        }
    }
}
// Ads are billed per campaign, never per order — a separate bucket.
$adsTotal = (float)($conn->query('SELECT COALESCE(SUM(total_cost),0) t FROM meesho_ads')
                         ->fetch_assoc()['t'] ?? 0);
$netAfterAds = $totalProfit - $adsTotal;

$flash = flash();

// Helper: profit for a single order row, or null when not computable.
function row_profit(array $o, array $costByName): ?float {
    $cost = ($costByName[$o['product_name']] ?? 0) * (int)$o['quantity'];
    if ($o['status'] === 'delivered' && $o['settlement_price'] !== null) {
        return (float)$o['settlement_price'] - $cost;
    }
    if ($o['status'] === 'returned' && (int)$o['return_cost_lost'] === 1) {
        return -$cost;
    }
    return null;
}

// CSS class controlling the status dropdown colour.
function status_cls(string $s): string {
    return [
        'label_pending'    => 'st-pending',
        'label_downloaded' => 'st-labelled',
        'out_for_delivery' => 'st-out',
        'shipped'          => 'st-out',
        'delivered'        => 'st-delivered',
        'cancelled'        => 'st-cancelled',
        'returned'         => 'st-returned',
        'rto'              => 'st-returned',
        'on_hold'          => 'st-pending',
    ][$s] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meesho orders · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}.danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:11.5px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:500;white-space:nowrap}
  .b-on{background:#e3f5eb;color:#1a7f4b}.b-off{background:#f0f2f5;color:#65676b}
  .b-warn{background:#fdf0d5;color:#a76b00}.b-info{background:#e5eefc;color:#1155c4}
  .b-ret{background:#fbe4e2;color:#b23a2c}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;background:#fff;border:1px solid #dfe1e5;text-decoration:none;color:#1c1e21;font-size:13px;font-weight:500}
  .pill.active{background:#1c2431;color:#fff;border-color:#1c2431}
  .pill b{font-weight:700}
  .pill.alert{border-color:#f0c26b;background:#fff8e8}
  .pill.alert.active{background:#a76b00;color:#fff;border-color:#a76b00}
  .pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
  .stat{display:inline-block;padding:2px 6px;border-radius:5px;font-size:12px;font-weight:600}
  .stat.pos{background:#e3f5eb;color:#1a7f4b}.stat.neg{background:#fbe4e2;color:#b23a2c}.stat.na{color:#9aa0a6}
  .inline{display:flex;gap:5px;align-items:center}
  .inline input{width:78px;padding:5px 7px;font-size:13px}
  .inline select{padding:5px 7px;font-size:13px}
  .inline button{padding:5px 9px;font-size:12.5px}
  .tblwrap{overflow-x:auto}
  .sub{font-size:11.5px;color:#8a8d91}
  .icobtn{padding:5px 8px;font-size:12px}
  details.det summary{cursor:pointer;color:#1877f2;font-size:12.5px}
  .metric{font-size:22px;font-weight:700}
  .metric small{font-size:12px;font-weight:500;color:#65676b;display:block}

  /* Full-width status dropdown, colour-coded by state. */
  .status-sel{width:100%;min-width:150px;font-weight:600;border-width:1px;border-style:solid;
              border-radius:7px;padding:8px 9px;cursor:pointer;font-size:13px;appearance:auto}
  .st-pending  {background:#fdf0d5;color:#8a5800;border-color:#e6c065}
  .st-labelled {background:#e5eefc;color:#0e4bb0;border-color:#a9c6f4}
  .st-out      {background:#e6e0fb;color:#5b34c4;border-color:#c2b2f0}
  .st-delivered{background:#e3f5eb;color:#137a45;border-color:#9adcb8}
  .st-cancelled{background:#f0f2f5;color:#5a5f66;border-color:#ccd0d5}
  .st-returned {background:#fbe4e2;color:#a5321f;border-color:#f0b3aa}

  /* Always-visible tracking IDs under the customer name. */
  .ids{margin-top:5px;font-size:11.5px;line-height:1.55;color:#5a5f66}
  .ids .k{color:#9aa0a6}
  .ids code{background:#f2f3f5;border-radius:4px;padding:0 4px;font-size:11px;
            word-break:break-all}
  .rowform{background:#f7f8fa;border:1px solid #e3e5e9;border-radius:8px;padding:14px;margin-top:8px}
  .rowform .form-grid{margin-bottom:10px}
  .miniids{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px}
  .miniids input{width:150px;padding:5px 7px;font-size:12.5px}
  .actbtns{display:flex;gap:6px;flex-wrap:wrap}
</style>
</head>
<body>
<?php
  $PAGE  = 'meesho';
  $TITLE = 'Meesho orders';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <?php if (!$activeProducts): ?>
    <div class="card" style="border-color:#f0c26b;background:#fff8e8">
      <h2>Set up products first</h2>
      <p class="desc" style="margin-bottom:0">You have no active Meesho products yet.
        Add your variants on the <a href="meesho_products.php">Meesho products</a> page
        (or run <code>meesho-schema.sql</code> to seed them), then come back here to log orders.</p>
    </div>
  <?php endif; ?>

  <!-- Summary + profit -->
  <div class="card">
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <div>
        <div class="metric" style="color:<?= $totalProfit >= 0 ? '#1a7f4b' : '#b23a2c' ?>">
          <?= money($totalProfit) ?>
          <small>Gross profit — settlement minus manufacturing, before ads</small>
        </div>
      </div>
      <div>
        <div class="metric" style="color:#b23a2c"><?= money($adsTotal) ?><small>ads cost</small></div>
      </div>
      <div>
        <div class="metric" style="color:<?= $netAfterAds >= 0 ? '#1a7f4b' : '#b23a2c' ?>">
          <?= money($netAfterAds) ?><small>NET after ads</small>
        </div>
      </div>
      <div style="text-align:right">
        <div class="metric"><?= $deliveredWithPrice ?><small>delivered &amp; priced</small></div>
      </div>
    </div>
    <p class="sub" style="margin-top:10px">
      Full breakdown on the <a href="meesho_stats.php">statistics page</a>.
      Settlements and ads come from the <a href="meesho_import.php">payment file import</a>.
    </p>
  </div>

  <!-- Filter pills (counts over everything) -->
  <div class="pills">
    <?php
      $mk = function($key, $label, $count, $alert=false) use ($f) {
          $active = ($f === $key) ? ' active' : '';
          $a = $alert ? ' alert' : '';
          $href = $key === '' ? 'meesho.php' : 'meesho.php?f=' . urlencode($key);
          return "<a class=\"pill$a$active\" href=\"$href\">$label <b>$count</b></a>";
      };
      $totalAll = array_sum($counts);
      echo $mk('', 'All', $totalAll);
      if ($reviewCount > 0) echo $mk('review', 'Needs review', $reviewCount, true);
      echo $mk('need_label', 'Need label', $counts['label_pending'], true);
      echo $mk('need_price', 'Awaiting price', $needPrice, true);
      echo $mk('label_downloaded', 'Label done', $counts['label_downloaded']);
      echo $mk('out_for_delivery', 'Out for delivery', $counts['out_for_delivery']);
      if ($counts['shipped'])  echo $mk('shipped', 'Shipped', $counts['shipped']);
      echo $mk('delivered', 'Delivered', $counts['delivered']);
      echo $mk('returned', 'Returned', $counts['returned']);
      if ($counts['rto'])      echo $mk('rto', 'RTO', $counts['rto']);
      if ($counts['on_hold'])  echo $mk('on_hold', 'On hold', $counts['on_hold']);
      echo $mk('cancelled', 'Cancelled', $counts['cancelled']);
    ?>
  </div>

  <!-- Add order -->
  <div class="card">
    <details class="det" <?= $orders ? '' : 'open' ?>>
      <summary style="font-size:15px;font-weight:600;color:#1c1e21;list-style:revert">+ Add a Meesho order</summary>
      <form method="post" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="ret" value="<?= e($f) ?>">
        <div class="form-grid">
          <div><label>Order date</label><input name="order_date" type="date"></div>
          <div><label>Customer name *</label><input name="customer_name" required maxlength="120"></div>
          <div><label>Phone</label><input name="phone" maxlength="20"></div>
          <div>
            <label>Product *</label>
            <select name="product_id" required style="width:100%">
              <option value="">— pick —</option>
              <?php foreach ($activeProducts as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (cost <?= money($p['unit_cost']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label>Quantity</label><input name="quantity" type="number" min="1" max="999" value="1"></div>
          <div>
            <label>Status</label>
            <select name="status" class="status-sel" onchange="paintStatus(this)">
              <?php foreach ($STATUSES as $k => $lbl): ?>
                <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label>Order ID</label><input name="order_id" maxlength="40"></div>
          <div><label>Sub-order ID</label><input name="sub_order_id" maxlength="50"></div>
          <div><label>Packet QR</label><input name="packet_qr" maxlength="60"></div>
          <div><label>AWB</label><input name="awb" maxlength="60"></div>
          <div><label>Dispatch date</label><input name="dispatch_date" type="date"></div>
          <div><label>Settlement ₹ (if known)</label><input name="settlement_price" type="number" step="0.01" min="0" placeholder="after delivery"></div>
        </div>
        <div style="margin-top:10px"><label>Notes</label><input name="notes" maxlength="255" style="width:100%"></div>
        <button type="submit" class="primary" style="margin-top:14px">Add order</button>
      </form>
    </details>
  </div>

  <!-- Orders table -->
  <div class="card">
    <h2><?= isset($STATUSES[$f]) ? e($STATUSES[$f])
          : ($f === 'need_label' ? 'Labels to download'
          : ($f === 'need_price' ? 'Delivered, awaiting settlement price'
          : 'All Meesho orders')) ?>
        <span class="sub">(<?= count($orders) ?>)</span></h2>

    <?php if (!$orders): ?>
      <p class="desc" style="margin:0">Nothing here.</p>
    <?php else: ?>
    <div class="tblwrap">
    <table>
      <thead><tr>
        <th>Date</th><th>Customer &amp; tracking</th><th>Product</th><th>Qty</th>
        <th style="min-width:160px">Status</th><th>Settlement</th><th>Profit</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <?php $profit = row_profit($o, $costByName); ?>
        <tr>
          <td style="white-space:nowrap"><?= $o['order_date'] ? e(date('d M', strtotime($o['order_date']))) : '—' ?></td>
          <td style="min-width:210px">
            <strong><?= e($o['customer_name']) ?></strong>
            <?php if ((int)($o['needs_review'] ?? 0) === 1): ?>
              <span class="badge b-warn" title="Created from a payment file — add the customer name">needs name</span>
            <?php endif; ?>
            <?php if ((int)($o['is_ad_order'] ?? 0) === 1): ?>
              <span class="badge b-info" title="This order came from a Meesho ad">ad</span>
            <?php endif; ?>
            <?php if (!empty($o['phone'])): ?><div class="sub"><?= e($o['phone']) ?></div><?php endif; ?>
            <div class="ids">
              <?php if (!empty($o['order_id'])): ?><span class="k">Order</span> <code><?= e($o['order_id']) ?></code><br><?php endif; ?>
              <?php if (!empty($o['sub_order_id'])): ?><span class="k">Sub</span> <code><?= e($o['sub_order_id']) ?></code><br><?php endif; ?>
              <?php if (!empty($o['packet_qr'])): ?><span class="k">QR</span> <code><?= e($o['packet_qr']) ?></code><br><?php endif; ?>
              <?php if (!empty($o['awb'])): ?><span class="k">AWB</span> <code><?= e($o['awb']) ?></code><br><?php endif; ?>
              <?php if (!empty($o['dispatch_date'])): ?><span class="k">Dispatch</span> <?= e(date('d M', strtotime($o['dispatch_date']))) ?><br><?php endif; ?>
              <?php if (!empty($o['notes'])): ?><span class="k">Note</span> <?= e($o['notes']) ?><?php endif; ?>
            </div>
            <?php if (empty($o['packet_qr']) || empty($o['awb'])): ?>
              <!-- Quick add of tracking IDs after creation -->
              <form method="post" class="miniids">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_ids">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="ret" value="<?= e($f) ?>">
                <input name="packet_qr" placeholder="Packet QR" value="<?= e($o['packet_qr'] ?? '') ?>" maxlength="60">
                <input name="awb" placeholder="AWB" value="<?= e($o['awb'] ?? '') ?>" maxlength="60">
                <button class="icobtn">Save tracking</button>
              </form>
            <?php endif; ?>
          </td>
          <td><?= e($o['product_name']) ?></td>
          <td><?= (int)$o['quantity'] ?></td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="ret" value="<?= e($f) ?>">
              <select name="status" class="status-sel <?= status_cls($o['status']) ?>"
                      onchange="this.form.submit()">
                <?php foreach ($STATUSES as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <?php if ($o['status'] === 'returned'): ?>
              <form method="post" style="margin-top:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_return_cost">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="ret" value="<?= e($f) ?>">
                <button class="icobtn <?= (int)$o['return_cost_lost'] ? 'danger' : '' ?>">
                  <?= (int)$o['return_cost_lost'] ? 'Cost lost ✓' : 'Mark cost lost' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
          <td>
            <?php if (in_array($o['status'], ['delivered','returned'], true)): ?>
              <form method="post" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_settlement">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="ret" value="<?= e($f) ?>">
                <input name="settlement_price" type="number" step="0.01" min="0"
                       value="<?= $o['settlement_price'] !== null ? number_format($o['settlement_price'],2,'.','') : '' ?>"
                       placeholder="₹">
                <button>Save</button>
              </form>
            <?php else: ?>
              <span class="sub">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($profit === null): ?>
              <span class="stat na">—</span>
            <?php else: ?>
              <span class="stat <?= $profit >= 0 ? 'pos' : 'neg' ?>"><?= money($profit) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <div class="actbtns">
              <button type="button" class="icobtn" onclick="toggleEdit(<?= (int)$o['id'] ?>)">Edit</button>
              <form method="post" onsubmit="return confirm('Delete this order?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="ret" value="<?= e($f) ?>">
                <button class="danger icobtn">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <!-- Inline edit row, hidden until Edit is clicked -->
        <tr id="edit-<?= (int)$o['id'] ?>" style="display:none">
          <td colspan="8" style="background:#f7f8fa">
            <form method="post" class="rowform">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="ret" value="<?= e($f) ?>">
              <div class="form-grid">
                <div><label>Order date</label>
                  <input name="order_date" type="date" value="<?= e($o['order_date'] ?? '') ?>"></div>
                <div><label>Customer name *</label>
                  <input name="customer_name" required maxlength="120" value="<?= e($o['customer_name']) ?>"></div>
                <div><label>Phone</label>
                  <input name="phone" maxlength="20" value="<?= e($o['phone'] ?? '') ?>"></div>
                <div><label>Product *</label>
                  <select name="product_id" required style="width:100%">
                    <?php foreach ($activeProducts as $p): ?>
                      <option value="<?= (int)$p['id'] ?>" <?= $o['product_name'] === $p['name'] ? 'selected' : '' ?>>
                        <?= e($p['name']) ?> (cost <?= money($p['unit_cost']) ?>)</option>
                    <?php endforeach; ?>
                    <?php
                      // If the order's product is no longer active/renamed, keep it selectable.
                      $known = array_column($activeProducts, 'name');
                      if (!in_array($o['product_name'], $known, true)):
                    ?>
                      <option value="0" selected><?= e($o['product_name']) ?> (inactive)</option>
                    <?php endif; ?>
                  </select></div>
                <div><label>Quantity</label>
                  <input name="quantity" type="number" min="1" max="999" value="<?= (int)$o['quantity'] ?>"></div>
                <div><label>Status</label>
                  <select name="status" class="status-sel <?= status_cls($o['status']) ?>" onchange="paintStatus(this)">
                    <?php foreach ($STATUSES as $k => $lbl): ?>
                      <option value="<?= e($k) ?>" <?= $o['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div><label>Order ID</label>
                  <input name="order_id" maxlength="40" value="<?= e($o['order_id'] ?? '') ?>"></div>
                <div><label>Sub-order ID</label>
                  <input name="sub_order_id" maxlength="50" value="<?= e($o['sub_order_id'] ?? '') ?>"></div>
                <div><label>Packet QR</label>
                  <input name="packet_qr" maxlength="60" value="<?= e($o['packet_qr'] ?? '') ?>"></div>
                <div><label>AWB</label>
                  <input name="awb" maxlength="60" value="<?= e($o['awb'] ?? '') ?>"></div>
                <div><label>Dispatch date</label>
                  <input name="dispatch_date" type="date" value="<?= e($o['dispatch_date'] ?? '') ?>"></div>
                <div><label>Settlement ₹</label>
                  <input name="settlement_price" type="number" step="0.01" min="0"
                         value="<?= $o['settlement_price'] !== null ? number_format($o['settlement_price'],2,'.','') : '' ?>"></div>
              </div>
              <div style="margin-bottom:10px"><label>Notes</label>
                <input name="notes" maxlength="255" style="width:100%" value="<?= e($o['notes'] ?? '') ?>"></div>
              <button type="submit" class="primary">Save changes</button>
              <button type="button" class="btn" onclick="toggleEdit(<?= (int)$o['id'] ?>)">Cancel</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<script>
// Recolour a status <select> to match its selected value.
function paintStatus(sel){
  var map = {
    label_pending:'st-pending', label_downloaded:'st-labelled',
    out_for_delivery:'st-out', shipped:'st-out', delivered:'st-delivered',
    cancelled:'st-cancelled', returned:'st-returned', rto:'st-returned',
    on_hold:'st-pending'
  };
  Object.values(map).forEach(function(c){ sel.classList.remove(c); });
  if (map[sel.value]) sel.classList.add(map[sel.value]);
}
document.querySelectorAll('select.status-sel').forEach(paintStatus);

// Show/hide the inline edit row for an order.
function toggleEdit(id){
  var row = document.getElementById('edit-' + id);
  if (row) row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
}
</script>
<?php require 'layout_end.php'; ?>

</body>
</html>
