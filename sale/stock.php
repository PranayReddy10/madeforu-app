<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
require 'stock_lib.php';
$me = require_login();

// ── Replay past orders against real batches ────────────────────────
// Draws down imported FIFO batches by consuming past order lines in
// chronological order, so stock shows true current on-hand. Seeds NO
// opening batches. Idempotent: refuses to run twice (detected by the
// replay-tagged sale ledger rows).
$NAME_MAP = [
    'Square Magnet'   => 'Square Magnet',
    'Acrylic Magnet'  => 'Acrylic Magnet',
    'Round Magnet'    => 'Round Magnet',
    'MDF Magnet'      => 'MDF Magnet',
    'Cup'             => 'Cup',
    'Oval Key Chain'  => 'Key Chain',
    'Bottle 650 ML'   => 'Bottle',
    'T-shirt'         => 'T-Shirt',
];
$REPLAY_TAG = 'Replay against imported batches';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $inTx = false;
    try {
        if (($_POST['action'] ?? '') !== 'replay') throw new Exception('Unknown action.');

        $done = (int)$conn->query(
            "SELECT COUNT(*) c FROM stock_ledger WHERE kind='sale' AND note=" .
            "'" . $conn->real_escape_string($REPLAY_TAG) . "'"
        )->fetch_assoc()['c'];
        if ($done > 0) throw new Exception('Replay has already been run. It cannot run twice.');

        $openings = (int)$conn->query('SELECT COUNT(*) c FROM purchases WHERE is_opening = 1')->fetch_assoc()['c'];
        if ($openings > 0) throw new Exception('Opening-batch stock exists, which conflicts with replaying against real batches. Clear opening batches first.');

        $batchCount = (int)$conn->query('SELECT COUNT(*) c FROM purchases')->fetch_assoc()['c'];
        if ($batchCount === 0) throw new Exception('No purchase batches found. Import or record purchases first.');

        $conn->begin_transaction(); $inTx = true;

        $lines = $conn->query(
            'SELECT oi.item, oi.quantity, oi.order_id, o.created_at
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              ORDER BY o.created_at ASC, oi.order_id ASC, oi.id ASC'
        );
        $consumed = 0; $skipped = 0; $skipDetail = [];
        while ($ln = $lines->fetch_assoc()) {
            $mapped = $NAME_MAP[$ln['item']] ?? null;
            if ($mapped === null) {
                $skipped++;
                $skipDetail[$ln['item']] = ($skipDetail[$ln['item']] ?? 0) + 1;
                continue;
            }
            stock_consume($conn, $mapped, (int)$ln['quantity'], 'order', (int)$ln['order_id'], $REPLAY_TAG);
            $consumed++;
        }

        $conn->commit(); $inTx = false;

        $msg = "Replay complete: {$consumed} order lines consumed against real batches";
        if ($skipped) {
            $bits = [];
            foreach ($skipDetail as $n => $c) $bits[] = e($n) . " ({$c})";
            $msg .= "; {$skipped} skipped [" . implode(', ', $bits) . "]";
        }
        flash($msg . '.');
    } catch (Exception $ex) {
        if (!empty($inTx)) $conn->rollback();
        flash($ex->getMessage(), 'error');
    }
    header('Location: stock.php');
    exit;
}

$replayDone = (int)$conn->query(
    "SELECT COUNT(*) c FROM stock_ledger WHERE kind='sale' AND note=" .
    "'" . $conn->real_escape_string($REPLAY_TAG) . "'"
)->fetch_assoc()['c'] > 0;
$openingsExist = (int)$conn->query('SELECT COUNT(*) c FROM purchases WHERE is_opening = 1')->fetch_assoc()['c'] > 0;
$batchCount = (int)$conn->query('SELECT COUNT(*) c FROM purchases')->fetch_assoc()['c'];

// Replay preview (read-only).
$replayPreview = []; $replaySkip = [];
$pr = $conn->query('SELECT item, SUM(quantity) q FROM order_items GROUP BY item');
while ($row = $pr->fetch_assoc()) {
    $raw = $row['item']; $q = (int)$row['q'];
    if (isset($NAME_MAP[$raw])) $replayPreview[$NAME_MAP[$raw]] = ($replayPreview[$NAME_MAP[$raw]] ?? 0) + $q;
    else $replaySkip[$raw] = $q;
}
ksort($replayPreview); ksort($replaySkip);

// ── Stock view: every product with on-hand + FIFO value ─────────────
$names = array_keys($ITEMS);
$extra = $conn->query('SELECT DISTINCT item FROM product_stock UNION SELECT DISTINCT item FROM purchases');
while ($x = $extra->fetch_assoc()) if (!in_array($x['item'], $names, true)) $names[] = $x['item'];
sort($names);

$rows = [];
$totUnits = 0; $totValue = 0.0;
foreach ($names as $item) {
    $onHand  = stock_on_hand($conn, $item);
    $batches = stock_open_batches($conn, $item);
    $value = 0.0; $batchUnits = 0;
    foreach ($batches as $b) { $value += (float)$b['unit_cost'] * (int)$b['qty_remaining']; $batchUnits += (int)$b['qty_remaining']; }
    if ($onHand === 0 && !$batches) continue;
    $rows[] = ['item' => $item, 'on_hand' => $onHand, 'batches' => $batches, 'value' => $value, 'batch_units' => $batchUnits];
    $totUnits += $onHand; $totValue += $value;
}

$PAGE = 'stock';
$TITLE = 'Stock on hand';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock on hand · Stall Orders</title>
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
  .kpis{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:4px}
  .kpi .n{font-size:22px;font-weight:700}.kpi .l{font-size:12px;color:#65676b}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2;vertical-align:top}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right}
  .neg{color:#c0392b;font-weight:600}
  .batch{font-size:12px;color:#555;margin-top:3px}
  .batch b{color:#1877f2}
  .warn{background:#fff8e8;border:1px solid #e6c065;border-radius:10px;padding:14px;font-size:13px;color:#6b5600}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  button.danger{background:#c0392b}
  .done{background:#e6f4ea;border:1px solid #b7e0c2;border-radius:10px;padding:14px;font-size:13px;color:#1a7f37}
</style>

<div class="card">
  <h2>Stock on hand</h2>
  <p class="desc">Live count per product. The batches under each show which purchase the stock came from — oldest first, which is the order it'll be costed out in.</p>
  <div class="kpis">
    <div class="kpi"><div class="n"><?= number_format($totUnits) ?></div><div class="l">Total units on hand</div></div>
    <div class="kpi"><div class="n"><?= money($totValue) ?></div><div class="l">Stock value (FIFO batches)</div></div>
  </div>
</div>

<div class="card">
  <table>
    <thead><tr><th>Product</th><th class="num">On hand</th><th>Open batches (oldest first)</th><th class="num">Value</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="4" style="color:#65676b">No stock yet. Record purchases, or run the backfill below.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['item']) ?></td>
        <td class="num <?= $r['on_hand'] < 0 ? 'neg' : '' ?>"><?= number_format($r['on_hand']) ?></td>
        <td>
          <?php if (!$r['batches']): ?>
            <span style="color:#999">none open</span>
          <?php else: foreach ($r['batches'] as $b): ?>
            <div class="batch">
              <b><?= (int)$b['qty_remaining'] ?></b> @ <?= money($b['unit_cost']) ?>
              <?php if ($b['dealer']): ?>· <?= e($b['dealer']) ?><?php endif; ?>
              <?php if ((int)$b['is_opening']): ?>· <span style="color:#8a6d00">opening</span><?php endif; ?>
              · <?= e($b['purchase_date']) ?>
            </div>
          <?php endforeach; endif; ?>
        </td>
        <td class="num"><?= money($r['value']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($totUnits !== $totValue && array_filter($rows, fn($r) => $r['on_hand'] !== $r['batch_units'])): ?>
    <p class="desc" style="margin-top:12px;margin-bottom:0">
      Note: where on-hand differs from the sum of open batches, it's usually negative stock (sold more than purchased) — expected while you're still catching up on recording purchases.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Update stock from past orders</h2>
  <p class="desc">
    Draws down your purchase batches by replaying past orders (FIFO, oldest
    first) so the counts above show true current on-hand — not just gross
    purchased. Run once, after importing or recording purchases. Refuses to run twice.
  </p>
  <?php if ($replayDone): ?>
    <div class="done">✓ Already run. Past orders have been consumed against your batches.</div>
  <?php elseif ($openingsExist): ?>
    <div class="warn">Opening-batch stock exists, which conflicts with replaying against real batches. Clear opening batches first.</div>
  <?php elseif ($batchCount === 0): ?>
    <div class="warn">No purchase batches yet. Record or import purchases first.</div>
  <?php else: ?>
    <div class="warn">
      Ready — <?= $batchCount ?> batches present. Will consume the order lines
      previewed below and skip non-product rows (payments, partner names).
    </div>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Replay past orders against your batches now?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="replay">
      <button class="primary" type="submit">Run stock update</button>
    </form>

    <?php if ($replayPreview): ?>
      <h3 style="font-size:14px;margin:18px 0 6px">Will be consumed</h3>
      <table>
        <thead><tr><th>Product</th><th class="num">Units</th></tr></thead>
        <tbody>
          <?php foreach ($replayPreview as $p => $q): ?>
            <tr><td><?= e($p) ?></td><td class="num"><?= number_format($q) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($replaySkip): ?>
      <h3 style="font-size:14px;margin:18px 0 6px;color:#65676b">Will be skipped (not stock)</h3>
      <table>
        <thead><tr><th>Item</th><th class="num">Lines</th></tr></thead>
        <tbody>
          <?php foreach ($replaySkip as $s => $q): ?>
            <tr><td style="color:#999"><?= e($s) ?></td><td class="num" style="color:#999"><?= number_format($q) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require 'layout_end.php'; ?>

</body>
</html>