<?php
require 'config.php';
require 'stock_lib.php';
$me = require_login();

/*
 * stock_replay.php — draw down imported FIFO batches by replaying past orders.
 *
 * Unlike the Stock-page backfill, this seeds NO opening batches. It assumes
 * real purchase batches already exist (from the 2026 import) and simply
 * consumes past order lines against them in chronological order, so stock
 * reflects true current on-hand.
 *
 * Past-order item names are mapped to catalogue product names where they
 * differ, and non-product rows (payments, partner names) are skipped.
 *
 * Idempotent: refuses to run if a replay has already been recorded (detected
 * by a 'sale' ledger row tagged as replay).
 */

// Map past-order item names -> stock product names. Anything not listed and
// not already a real product is treated as SKIP (payments, partner rows).
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
// Explicit skips (documented, so the summary can report them).
$SKIP_NAMES = ['Initial payment', 'Shravani', 'Pranay Reddy', 'Pooja', 'Bhanu'];

$REPLAY_TAG = 'Replay against imported batches';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $inTx = false;
    try {
        if (($_POST['action'] ?? '') !== 'replay') throw new Exception('Unknown action.');

        // Guard: already replayed?
        $done = (int)$conn->query(
            "SELECT COUNT(*) c FROM stock_ledger WHERE kind='sale' AND note=" .
            "'" . $conn->real_escape_string($REPLAY_TAG) . "'"
        )->fetch_assoc()['c'];
        if ($done > 0) throw new Exception('Replay has already been run. It cannot run twice.');

        // Guard: opening-batch backfill must NOT have been run, or stock would
        // double up. Detect its opening batches.
        $openings = (int)$conn->query('SELECT COUNT(*) c FROM purchases WHERE is_opening = 1')->fetch_assoc()['c'];
        if ($openings > 0) throw new Exception('The Stock-page backfill (opening batches) has run. That conflicts with replaying against real batches. Clear opening batches first, or use only one method.');

        // Guard: real batches must exist to consume from.
        $batchCount = (int)$conn->query('SELECT COUNT(*) c FROM purchases')->fetch_assoc()['c'];
        if ($batchCount === 0) throw new Exception('No purchase batches found. Import purchases first, then replay.');

        $conn->begin_transaction(); $inTx = true;

        // Every past order line, oldest order first.
        $lines = $conn->query(
            'SELECT oi.item, oi.quantity, oi.order_id, o.created_at
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
              ORDER BY o.created_at ASC, oi.order_id ASC, oi.id ASC'
        );

        $consumed = 0; $skipped = 0; $skipDetail = [];
        while ($ln = $lines->fetch_assoc()) {
            $raw = $ln['item'];
            $mapped = $NAME_MAP[$raw] ?? null;
            if ($mapped === null) {
                $skipped++;
                $skipDetail[$raw] = ($skipDetail[$raw] ?? 0) + 1;
                continue;
            }
            stock_consume($conn, $mapped, (int)$ln['quantity'], 'order', (int)$ln['order_id'], $REPLAY_TAG);
            $consumed++;
        }

        $conn->commit(); $inTx = false;

        $msg = "Replay complete: {$consumed} order lines consumed";
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
    header('Location: stock_replay.php');
    exit;
}

// Status for the page.
$replayed = (int)$conn->query(
    "SELECT COUNT(*) c FROM stock_ledger WHERE kind='sale' AND note=" .
    "'" . $conn->real_escape_string($REPLAY_TAG) . "'"
)->fetch_assoc()['c'] > 0;

$openings = (int)$conn->query('SELECT COUNT(*) c FROM purchases WHERE is_opening = 1')->fetch_assoc()['c'];
$batchCount = (int)$conn->query('SELECT COUNT(*) c FROM purchases')->fetch_assoc()['c'];

// Preview: what would be consumed vs skipped (read-only, no writes).
$preview = [];
$skipPreview = [];
$res = $conn->query('SELECT item, SUM(quantity) q FROM order_items GROUP BY item');
while ($r = $res->fetch_assoc()) {
    $raw = $r['item']; $q = (int)$r['q'];
    if (isset($NAME_MAP[$raw])) $preview[$NAME_MAP[$raw]] = ($preview[$NAME_MAP[$raw]] ?? 0) + $q;
    else $skipPreview[$raw] = $q;
}
ksort($preview); ksort($skipPreview);

$PAGE = 'stock_replay';
$TITLE = 'Stock replay';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock replay · Stall Orders</title>
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
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right}
  .warn{background:#fff8e8;border:1px solid #e6c065;border-radius:10px;padding:14px;font-size:13px;color:#6b5600}
  .done{background:#e6f4ea;border:1px solid #b7e0c2;border-radius:10px;padding:14px;font-size:13px;color:#1a7f37}
  .err{background:#fdeceb;border:1px solid #f5c6c2;border-radius:10px;padding:14px;font-size:13px;color:#c0392b}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  .skip{color:#999}
</style>

<div class="card">
  <h2>Draw down stock from past orders</h2>
  <p class="desc">
    This replays every past order against your real imported purchase batches
    (FIFO, oldest batch first) so stock shows true current on-hand — not just
    gross purchased. It seeds no opening stock. Run it once, after importing.
  </p>

  <?php if ($replayed): ?>
    <div class="done">✓ Replay already run. Past orders have been consumed against the imported batches. This can't run again.</div>
  <?php elseif ($openings > 0): ?>
    <div class="err">
      Conflict: the Stock-page backfill has seeded <?= $openings ?> opening batch(es).
      Replaying on top would double-count. Use only one method — either the
      opening-batch backfill, or this replay against real batches.
    </div>
  <?php elseif ($batchCount === 0): ?>
    <div class="err">No purchase batches found yet. Run the import first, then come back here.</div>
  <?php else: ?>
    <div class="warn">
      Ready. <?= $batchCount ?> purchase batches present. This will consume the
      order lines previewed below. It refuses to run twice.
    </div>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Replay past orders against imported batches now?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="replay">
      <button class="primary" type="submit">Run replay</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Preview — will be consumed</h2>
  <p class="desc">Total units per product that past orders would draw down (mapped names).</p>
  <table>
    <thead><tr><th>Product</th><th class="num">Units to consume</th></tr></thead>
    <tbody>
      <?php if (!$preview): ?><tr><td colspan="2" class="skip">Nothing to consume.</td></tr><?php endif; ?>
      <?php foreach ($preview as $p => $q): ?>
        <tr><td><?= e($p) ?></td><td class="num"><?= number_format($q) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($skipPreview): ?>
<div class="card">
  <h2>Preview — will be skipped</h2>
  <p class="desc">Rows in order history that aren't stock products (payments, partner names, unmapped items).</p>
  <table>
    <thead><tr><th>Item name</th><th class="num">Lines/units</th></tr></thead>
    <tbody>
      <?php foreach ($skipPreview as $s => $q): ?>
        <tr><td class="skip"><?= e($s) ?></td><td class="num skip"><?= number_format($q) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require 'layout_end.php'; ?>

</body>
</html>
