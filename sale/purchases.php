<?php
require 'config.php';
require 'stock_lib.php';
$me = require_login();

// Product names available to buy: active catalogue + any product already
// purchased or sold before (so a hidden product with history still lists).
$productNames = array_keys($ITEMS);
$extra = $conn->query(
    'SELECT DISTINCT item FROM purchases
     UNION SELECT DISTINCT item FROM order_items'
);
while ($x = $extra->fetch_assoc()) {
    if (!in_array($x['item'], $productNames, true)) $productNames[] = $x['item'];
}
sort($productNames);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $inTx = false;
    $action = $_POST['action'] ?? 'add';
    try {
        if ($action === 'delete') {
            // Remove a purchase batch and pull its still-remaining units out of
            // stock. Consumed units (already sold) are not returned — only the
            // qty_remaining is removed, matching what the batch still holds.
            $pid = (int)($_POST['purchase_id'] ?? 0);
            if ($pid < 1) throw new Exception('No batch specified.');

            $g = $conn->prepare('SELECT item, qty_remaining FROM purchases WHERE id = ?');
            $g->bind_param('i', $pid); $g->execute();
            $row = $g->get_result()->fetch_assoc(); $g->close();
            if (!$row) throw new Exception('Batch not found.');
            $bItem = $row['item']; $bRem = (int)$row['qty_remaining'];

            $conn->begin_transaction(); $inTx = true;
            // Remove remaining units from stock.
            if ($bRem !== 0) stock_bump($conn, $bItem, -$bRem);
            // Delete the purchase-side ledger rows for this batch, then the batch.
            $d = $conn->prepare("DELETE FROM stock_ledger WHERE ref_type='purchase' AND ref_id=?");
            $d->bind_param('i', $pid); $d->execute(); $d->close();
            $d = $conn->prepare('DELETE FROM purchases WHERE id = ?');
            $d->bind_param('i', $pid); $d->execute(); $d->close();
            $conn->commit(); $inTx = false;
            flash('Batch deleted. ' . $bRem . ' remaining unit(s) removed from ' . $bItem . ' stock.');

        } else {
            // Default: add a new purchase batch.
            $dealerId = (int)($_POST['dealer_id'] ?? 0) ?: null;
            $item     = trim($_POST['item'] ?? '');
            $unitCost = round((float)($_POST['unit_cost'] ?? 0), 2);
            $qty      = (int)($_POST['qty'] ?? 0);
            $date     = trim($_POST['purchase_date'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');

            if ($item === '')  throw new Exception('Pick a product.');
            if ($qty <= 0)     throw new Exception('Quantity must be at least 1.');
            if ($unitCost < 0) throw new Exception('Unit cost cannot be negative.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

            $conn->begin_transaction(); $inTx = true;
            stock_add_purchase($conn, $dealerId, $item, $unitCost, $qty, $date, $notes ?: null, false);
            $conn->commit(); $inTx = false;
            flash('Purchase recorded: ' . $qty . ' × ' . $item . ' @ ' . money($unitCost) . '.');
        }
    } catch (Exception $ex) {
        if (!empty($inTx)) $conn->rollback();
        flash($ex->getMessage(), 'error');
    }
    header('Location: purchases.php' . (isset($_POST['dealer_filter']) && (int)$_POST['dealer_filter'] ? '?dealer=' . (int)$_POST['dealer_filter'] : ''));
    exit;
}

// Dealer filter.
$filterDealer = isset($_GET['dealer']) ? (int)$_GET['dealer'] : 0;

$dealers = $conn->query('SELECT id, name FROM dealers WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// Ledger.
$where = $filterDealer ? 'WHERE p.dealer_id = ?' : '';
$sql = "SELECT p.*, d.name AS dealer
          FROM purchases p
          LEFT JOIN dealers d ON d.id = p.dealer_id
          $where
         ORDER BY p.purchase_date DESC, p.id DESC";
$stmt = $conn->prepare($sql);
if ($filterDealer) $stmt->bind_param('i', $filterDealer);
$stmt->execute();
$ledger = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totSpent = 0.0;
foreach ($ledger as $l) $totSpent += (float)$l['unit_cost'] * (int)$l['qty_bought'];

$PAGE = 'purchases';
$TITLE = 'Stock purchases';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock purchases · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
  input,select{font-family:inherit}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
</style>
</head>
<body>
<?php require 'layout.php'; ?>
<?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
<style>
  .card{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;margin-bottom:4px}
  .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  .grid{display:grid;grid-template-columns:2fr 2fr 1fr 1fr 1.4fr auto;gap:12px;align-items:end}
  label{display:block;font-size:12px;color:#65676b;margin-bottom:4px}
  select,input{width:100%;padding:9px 11px;border:1px solid #ccd0d5;border-radius:8px;font-size:14px}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right}
  .filter-bar{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
  .filter-bar a{font-size:13px;text-decoration:none;color:#1877f2;padding:6px 12px;border:1px solid #d5dae0;border-radius:999px}
  .filter-bar a.on{background:#1877f2;color:#fff;border-color:#1877f2}
  .open-tag{font-size:11px;color:#1a7f37;background:#e6f4ea;padding:1px 7px;border-radius:999px}
  .del-btn{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2;border-radius:6px;padding:5px 10px;cursor:pointer}
  .del-btn:hover{background:#f9d7d3}
  .opening{font-size:11px;color:#8a6d00;background:#fff3cd;padding:1px 7px;border-radius:999px}
  @media(max-width:820px){.grid{grid-template-columns:1fr 1fr}}
</style>

<div class="card">
  <h2>Record a purchase</h2>
  <p class="desc">
    Each purchase is a batch — product, dealer, the price you paid, and how many.
    Buying the same product from different dealers at different prices just makes
    separate batches. Profit uses the oldest batch first (FIFO).
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="grid">
      <div>
        <label>Product</label>
        <select name="item" required>
          <option value="">— pick —</option>
          <?php foreach ($productNames as $p): ?>
            <option value="<?= e($p) ?>"><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Dealer</label>
        <select name="dealer_id">
          <option value="0">— none / self —</option>
          <?php foreach ($dealers as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Unit cost (₹)</label><input type="number" name="unit_cost" step="0.01" min="0" required placeholder="13.00"></div>
      <div><label>Quantity</label><input type="number" name="qty" min="1" required placeholder="100"></div>
      <div><label>Purchase date</label><input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>"></div>
      <div><button class="primary" type="submit">Add batch</button></div>
    </div>
    <div style="margin-top:10px"><label>Notes (optional)</label><input type="text" name="notes" maxlength="255" placeholder="invoice no., quality, etc."></div>
  </form>
  <p class="desc" style="margin-top:10px;margin-bottom:0">
    No dealers listed? Add them on the <a href="dealers.php">Dealers</a> page first.
  </p>
</div>

<div class="card">
  <h2>Purchase ledger</h2>

  <div class="filter-bar">
    <a href="purchases.php" class="<?= $filterDealer ? '' : 'on' ?>">All dealers</a>
    <?php foreach ($dealers as $d): ?>
      <a href="purchases.php?dealer=<?= (int)$d['id'] ?>" class="<?= $filterDealer === (int)$d['id'] ? 'on' : '' ?>"><?= e($d['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <table>
    <thead><tr>
      <th>Date</th><th>Product</th><th>Dealer</th>
      <th class="num">Unit cost</th><th class="num">Bought</th><th class="num">Remaining</th>
      <th class="num">Batch value</th><th>Notes</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php if (!$ledger): ?>
      <tr><td colspan="9" style="color:#65676b">No purchases yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($ledger as $l): ?>
      <tr>
        <td><?= e($l['purchase_date']) ?></td>
        <td>
          <?= e($l['item']) ?>
          <?php if ((int)$l['is_opening']): ?><span class="opening">opening</span><?php endif; ?>
        </td>
        <td><?= e($l['dealer'] ?: '—') ?></td>
        <td class="num"><?= money($l['unit_cost']) ?></td>
        <td class="num"><?= (int)$l['qty_bought'] ?></td>
        <td class="num">
          <?php if ((int)$l['qty_remaining'] > 0): ?>
            <span class="open-tag"><?= (int)$l['qty_remaining'] ?> left</span>
          <?php else: ?>
            <span style="color:#999">sold out</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= money((float)$l['unit_cost'] * (int)$l['qty_bought']) ?></td>
        <td><?= e($l['notes'] ?: '—') ?></td>
        <td>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Delete this batch? <?= (int)$l['qty_remaining'] ?> remaining unit(s) will be removed from <?= e($l['item']) ?> stock. This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="purchase_id" value="<?= (int)$l['id'] ?>">
            <input type="hidden" name="dealer_filter" value="<?= (int)$filterDealer ?>">
            <button type="submit" class="del-btn" style="font-size:13px">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($ledger): ?>
    <tfoot><tr>
      <td colspan="6" style="text-align:right;font-weight:600;padding-top:12px">Total spent<?= $filterDealer ? ' (this dealer)' : '' ?></td>
      <td class="num" style="font-weight:600;padding-top:12px"><?= money($totSpent) ?></td>
      <td></td>
      <td></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<?php require 'layout_end.php'; ?>

</body>
</html>