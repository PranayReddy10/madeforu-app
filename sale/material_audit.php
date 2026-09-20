<?php
/**
 * material_audit.php — raw material bought against raw material used.
 *
 * The question this answers is "where did it go". Purchases have been
 * recorded for a while, but nothing consumed them: save.php never called
 * stock_lib, so bought and used were two unconnected piles and the only
 * way to know whether they agreed was to count the shelf.
 *
 * Now every sale draws its material out FIFO and records which batch it
 * came from, so three numbers can be compared for each item:
 *
 *   bought   — every purchase batch, at what it actually cost;
 *   used     — what sales took, priced at what those particular units
 *              cost rather than at today's catalogue cost;
 *   on hand  — the running count.
 *
 * Bought minus used minus on hand should be zero. When it is not, stock
 * moved without being written down — breakage, a sample, a miscount —
 * and the gap is the size of it. That column is the point of the page:
 * a number that is usually zero is worth far more than one that is
 * always approximately right.
 *
 * Note on cost. The value here is the FIFO cost of the actual units. It
 * is deliberately NOT what profit is calculated from: order_items.unit_cost
 * froze the standard cost at the moment of sale, and that is what Stats
 * and the P&L use. The two answer different questions -- "what did this
 * sale cost us at the price we plan around" and "what did these specific
 * units cost" -- and where they disagree is worth knowing rather than
 * quietly reconciling.
 */
require 'config.php';
require_once __DIR__ . '/lib_trade.php';
require_once __DIR__ . '/stock_lib.php';
$me = require_login();

$ready = stock_draws_ready($conn);
$usage = $ready ? stock_usage($conn) : [];

// One material can be singled out, for the "which orders ate this" list.
$only = trim((string)($_GET['item'] ?? ''));

// Which orders consumed what, most recent first. Joined to orders so a
// draw can be traced to the sale that caused it, and to purchases so it
// can be traced back to the dealer it came from.
$draws = [];
if ($ready) {
    $sql = 'SELECT sd.item, sd.qty, sd.unit_cost, sd.ref_id, sd.created_at,
                   sd.purchase_id, p.purchase_date, d.name AS dealer,
                   o.order_no, o.name AS customer, o.created_at AS order_date
              FROM stock_draws sd
              LEFT JOIN purchases p ON p.id = sd.purchase_id
              LEFT JOIN dealers   d ON d.id = p.dealer_id
              LEFT JOIN orders    o ON o.id = sd.ref_id AND sd.ref_type = \'order\'';
    $params = []; $types = '';
    if ($only !== '') { $sql .= ' WHERE sd.item = ?'; $params[] = $only; $types = 's'; }
    $sql .= ' ORDER BY sd.id DESC LIMIT 300';
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $draws = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

$totBought = $totUsed = $totStock = 0.0;
$anyGap = false;
foreach ($usage as $u) {
    $totBought += $u['bought_value'];
    $totUsed   += $u['used_value'];
    $totStock  += $u['stock_value'];
    if ($u['unaccounted'] !== 0) $anyGap = true;
}

// How many orders have actually drawn stock. Until this is non-zero the
// "used" column is empty for a reason, and the page should say so
// rather than looking broken.
$drawnOrders = 0;
if ($ready) {
    $drawnOrders = (int)($conn->query(
        "SELECT COUNT(DISTINCT ref_id) c FROM stock_draws WHERE ref_type = 'order'"
    )->fetch_assoc()['c'] ?? 0);
}

$PAGE  = 'material_audit';
$TITLE = 'Material audit';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Material audit · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
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
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  tr.total td{font-weight:700;border-top:2px solid #e4e6eb;border-bottom:none}
  .gap{color:#c0392b;font-weight:600}
  .ok{color:#1a7f37}
  .muted{color:#65676b}
  .scroll{overflow-x:auto}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:16px}
  .tile{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:14px 16px}
  .tile .k{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  .tile .v{font-size:22px;font-weight:700;margin-top:3px;font-variant-numeric:tabular-nums}
  .warn{background:#fff8e1;border:1px solid #ffe0a3;color:#7a5800;padding:12px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
  .chips a{text-decoration:none;font-size:13px;padding:6px 13px;border-radius:999px;
    border:1px solid #ccd0d5;color:#1c1e21;background:#fff}
  .chips a.on{background:#1877f2;color:#fff;border-color:#1877f2}
</style>

<?php if (!$ready): ?>
  <div class="warn">
    <strong>Not set up yet.</strong> Run
    <code>sale/api/migrations/2026-09-channels-accounts-stock.sql</code> against the
    database to start recording which purchase batch each sale draws from.
  </div>
<?php else: ?>

<?php if (!$usage): ?>
  <div class="warn">
    <strong>Nothing bought in yet.</strong> Record raw material on the
    <a href="purchases.php">Stock purchases</a> page and it will appear here.
    A product only starts being drawn down once it has been purchased at
    least once — that is what stops every catalogue item that has never
    been bought from showing a made-up shortfall.
  </div>
<?php elseif ($drawnOrders === 0): ?>
  <div class="warn">
    <strong>Bought, but nothing used yet.</strong> Sales draw material from
    the moment this was switched on, so the <em>used</em> column fills up as
    new orders are taken. Orders written before then are not drawn down —
    their material left the building before any of this was counted.
  </div>
<?php endif; ?>

<div class="tiles">
  <div class="tile"><div class="k">Bought</div><div class="v"><?= e(money($totBought)) ?></div></div>
  <div class="tile"><div class="k">Used in sales</div><div class="v"><?= e(money($totUsed)) ?></div></div>
  <div class="tile"><div class="k">Still on the shelf</div><div class="v"><?= e(money($totStock)) ?></div></div>
  <div class="tile">
    <div class="k">Orders drawn</div>
    <div class="v"><?= $drawnOrders ?></div>
  </div>
</div>

<div class="card">
  <h2>Bought against used</h2>
  <p class="desc">
    Money figures are what the units actually cost, batch by batch — not
    today's catalogue cost.
    <strong>Unaccounted</strong> is bought minus used minus on hand, and
    should be zero; anything else moved without being written down.
  </p>
  <div class="scroll">
  <table>
    <thead><tr>
      <th>Material</th>
      <th class="num">Bought</th><th class="num">Cost</th>
      <th class="num">Used</th><th class="num">Cost of use</th>
      <th class="num">On hand</th><th class="num">Value</th>
      <th class="num">Unaccounted</th>
    </tr></thead>
    <tbody>
    <?php foreach ($usage as $u): ?>
      <tr>
        <td><a href="material_audit.php?item=<?= e(urlencode($u['item'])) ?>"><?= e($u['item']) ?></a></td>
        <td class="num"><?= (int)$u['bought_qty'] ?></td>
        <td class="num"><?= e(money($u['bought_value'])) ?></td>
        <td class="num"><?= (int)$u['used_qty'] ?></td>
        <td class="num"><?= e(money($u['used_value'])) ?></td>
        <td class="num"><?= (int)$u['on_hand'] ?></td>
        <td class="num"><?= e(money($u['stock_value'])) ?></td>
        <td class="num <?= $u['unaccounted'] === 0 ? 'ok' : 'gap' ?>">
          <?= $u['unaccounted'] === 0 ? '0' : e((string)$u['unaccounted']) ?>
        </td>
      </tr>
    <?php endforeach; ?>
      <tr class="total">
        <td>Total</td><td class="num"></td>
        <td class="num"><?= e(money($totBought)) ?></td>
        <td class="num"></td>
        <td class="num"><?= e(money($totUsed)) ?></td>
        <td class="num"></td>
        <td class="num"><?= e(money($totStock)) ?></td>
        <td class="num"></td>
      </tr>
    </tbody>
  </table>
  </div>
  <?php if ($anyGap): ?>
    <p class="desc" style="margin-top:12px;margin-bottom:0">
      A non-zero figure is not necessarily an error — an adjustment made on the
      <a href="stock.php">Stock on hand</a> page shows up here until the
      purchase behind it is recorded.
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Which sale used which batch</h2>
  <p class="desc">
    Every draw, newest first. This is the trace: a sale, the batch it came
    out of, the dealer it was bought from and what those units cost.
  </p>

  <div class="chips">
    <a href="material_audit.php" class="<?= $only === '' ? 'on' : '' ?>">All materials</a>
    <?php foreach ($usage as $u): ?>
      <a href="material_audit.php?item=<?= e(urlencode($u['item'])) ?>"
         class="<?= $only === $u['item'] ? 'on' : '' ?>"><?= e($u['item']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$draws): ?>
    <p class="desc" style="margin-bottom:0">
      No sale has drawn <?= $only === '' ? 'any material' : e($only) ?> yet.
    </p>
  <?php else: ?>
  <div class="scroll">
  <table>
    <thead><tr>
      <th>Order</th><th>Customer</th><th>Material</th>
      <th class="num">Units</th><th class="num">Unit cost</th><th class="num">Cost</th>
      <th>From batch</th>
    </tr></thead>
    <tbody>
    <?php foreach ($draws as $d): ?>
      <tr>
        <td>
          <?php if ($d['order_no']): ?>
            <a href="edit.php?id=<?= (int)$d['ref_id'] ?>"><?= e($d['order_no']) ?></a>
          <?php else: ?>
            <span class="muted">order <?= (int)$d['ref_id'] ?> (deleted)</span>
          <?php endif; ?>
          <div class="muted" style="font-size:12px"><?= e(date('d M Y', strtotime($d['created_at']))) ?></div>
        </td>
        <td><?= e((string)($d['customer'] ?? '—')) ?></td>
        <td><?= e($d['item']) ?></td>
        <td class="num"><?= (int)$d['qty'] ?></td>
        <td class="num"><?= e(money($d['unit_cost'])) ?></td>
        <td class="num"><?= e(money((int)$d['qty'] * (float)$d['unit_cost'])) ?></td>
        <td>
          <?php if ($d['purchase_id'] === null): ?>
            <span class="gap">no batch — sold beyond stock</span>
          <?php else: ?>
            <?= e((string)($d['dealer'] ?? 'No dealer')) ?>
            <div class="muted" style="font-size:12px">bought <?= e(date('d M Y', strtotime($d['purchase_date']))) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; ?>
</body>
</html>
