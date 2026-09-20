<?php
/**
 * material_audit.php — raw material bought against raw material used.
 *
 * A page on its own, and deliberately nothing more than that. It reads
 * three tables that already exist and writes to none of them:
 *
 *   purchases      what was bought, from whom, at what it cost;
 *   order_items    how many of each product have been sold;
 *   product_stock  the running on-hand count, kept by the Stock page.
 *
 * Nothing here changes an order, a price, a cost or a profit figure.
 * Taking stock out of the shelf as sales are written would have been
 * the "proper" way to do it and would also have meant every sale, on
 * every screen, behaving differently — so the numbers are worked out
 * when the page is opened instead. If it is wrong, nothing is damaged;
 * reload it and it is right again.
 *
 * On the name join. order_items and purchases both store a product name,
 * and they do not always agree: the stock import maps 'Oval Key Chain'
 * to 'Key Chain' and 'Bottle 650 ML' to 'Bottle'. Rather than quietly
 * matching what it can, this page lists every name from either side, so
 * a material that was bought under one name and sold under another
 * shows up as two rows — visibly odd, which is the point of an audit.
 *
 * (Both columns are utf8mb4_unicode_ci, so no COLLATE is needed here.
 * products.name is utf8mb4_uca1400_ai_ci, which is why this page joins
 * nothing to products.)
 */
require 'config.php';
$me = require_login();

/** Has any raw material been recorded at all? */
$hasPurchases = false;
try {
    $hasPurchases = (int)$conn->query('SELECT COUNT(*) c FROM purchases')->fetch_assoc()['c'] > 0;
} catch (Throwable $e) {
    $hasPurchases = false;
}

$rows = [];
$totBought = 0.0;
$totStock  = 0.0;

try {
    // Bought, per material.
    $bought = [];
    $res = $conn->query(
        'SELECT item,
                COALESCE(SUM(qty_bought),0) qty,
                COALESCE(SUM(qty_bought * unit_cost),0) val,
                COUNT(*) batches,
                MIN(purchase_date) first_buy,
                MAX(purchase_date) last_buy
           FROM purchases GROUP BY item'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $bought[$r['item']] = [
            'qty'     => (int)$r['qty'],
            'val'     => (float)$r['val'],
            'batches' => (int)$r['batches'],
            'first'   => $r['first_buy'],
            'last'    => $r['last_buy'],
        ];
    }

    // Sold, per product. Read from the order lines as they stand; this
    // does not touch them.
    $sold = [];
    $res = $conn->query(
        'SELECT item, COALESCE(SUM(quantity),0) qty, COUNT(DISTINCT order_id) orders
           FROM order_items GROUP BY item'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $sold[$r['item']] = ['qty' => (int)$r['qty'], 'orders' => (int)$r['orders']];
    }

    // On hand, as the Stock page has it.
    $onHand = [];
    try {
        $res = $conn->query('SELECT item, qty_on_hand FROM product_stock');
        while ($res && ($r = $res->fetch_assoc())) $onHand[$r['item']] = (int)$r['qty_on_hand'];
    } catch (Throwable $e) { /* no stock table yet */ }

    $names = array_unique(array_merge(
        array_keys($bought), array_keys($sold), array_keys($onHand)
    ));
    sort($names);

    foreach ($names as $name) {
        $b = $bought[$name] ?? ['qty' => 0, 'val' => 0.0, 'batches' => 0, 'first' => null, 'last' => null];
        $s = $sold[$name]   ?? ['qty' => 0, 'orders' => 0];
        $h = $onHand[$name] ?? 0;

        // Average is only meaningful when something was bought.
        $avg   = $b['qty'] > 0 ? $b['val'] / $b['qty'] : 0.0;
        $value = round($h * $avg, 2);

        $totBought += $b['val'];
        $totStock  += $value;

        $rows[] = [
            'item'        => $name,
            'bought_qty'  => $b['qty'],
            'bought_val'  => round($b['val'], 2),
            'batches'     => $b['batches'],
            'first'       => $b['first'],
            'last'        => $b['last'],
            'sold_qty'    => $s['qty'],
            'orders'      => $s['orders'],
            'on_hand'     => $h,
            'avg_cost'    => round($avg, 2),
            'stock_value' => $value,
            'sold_cost'   => round($s['qty'] * $avg, 2),
            // Bought minus sold minus on hand. Only meaningful for a
            // material that has actually been bought in.
            'gap'         => $b['qty'] > 0 ? $b['qty'] - $s['qty'] - $h : null,
        ];
    }
} catch (Throwable $e) {
    $rows = [];
}

// Recent purchases, so the page answers "what did we buy lately" too.
$recent = [];
try {
    $res = $conn->query(
        'SELECT p.item, p.qty_bought, p.unit_cost, p.purchase_date, p.notes,
                d.name AS dealer
           FROM purchases p
           LEFT JOIN dealers d ON d.id = p.dealer_id
          ORDER BY p.purchase_date DESC, p.id DESC LIMIT 40'
    );
    while ($res && ($r = $res->fetch_assoc())) $recent[] = $r;
} catch (Throwable $e) { /* table not there yet */ }

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
  .muted{color:#65676b}
  .dim td{opacity:.55}
  .scroll{overflow-x:auto}
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
  .tile{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:14px 16px}
  .tile .k{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  .tile .v{font-size:22px;font-weight:700;margin-top:3px;font-variant-numeric:tabular-nums}
  .warn{background:#fff8e1;border:1px solid #ffe0a3;color:#7a5800;padding:12px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
  .note{background:#f7f8fa;border:1px solid #e9ebee;color:#454749;padding:11px 14px;
    border-radius:10px;font-size:13px;margin-bottom:16px}
</style>

<div class="note">
  This page only reads. It does not change an order, a price, a cost or any
  profit figure anywhere else on the site — the numbers are worked out fresh
  each time you open it.
</div>

<?php if (!$hasPurchases): ?>
  <div class="warn">
    <strong>No raw material recorded yet.</strong> Add what you buy on the
    <a href="purchases.php">Stock purchases</a> page — the dealer, the item, how
    many and what they cost — and it will show up here.
  </div>
<?php endif; ?>

<div class="tiles">
  <div class="tile"><div class="k">Spent on material</div><div class="v"><?= e(money($totBought)) ?></div></div>
  <div class="tile"><div class="k">Still on the shelf</div><div class="v"><?= e(money($totStock)) ?></div></div>
  <div class="tile"><div class="k">Materials tracked</div><div class="v"><?= count(array_filter($rows, fn($r) => $r['bought_qty'] > 0)) ?></div></div>
</div>

<div class="card">
  <h2>Bought against sold</h2>
  <p class="desc">
    What was bought in, how many have gone out in orders, and what is left.
    <strong>Difference</strong> is bought − sold − on hand; it is blank for a
    product that has never been bought as raw material, because there is
    nothing to compare it against.
  </p>
  <div class="scroll">
  <table>
    <thead><tr>
      <th>Material</th>
      <th class="num">Bought</th><th class="num">Spent</th><th class="num">Avg cost</th>
      <th class="num">Sold</th><th class="num">Cost of sales</th>
      <th class="num">On hand</th><th class="num">Value</th>
      <th class="num">Difference</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="muted" style="padding:18px 8px">Nothing to show yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['bought_qty'] === 0 ? 'dim' : '' ?>">
        <td>
          <?= e($r['item']) ?>
          <?php if ($r['bought_qty'] === 0): ?>
            <div class="muted" style="font-size:12px">never bought as raw material</div>
          <?php elseif ($r['batches'] > 0): ?>
            <div class="muted" style="font-size:12px">
              <?= (int)$r['batches'] ?> purchase<?= $r['batches'] === 1 ? '' : 's' ?><?php
                if ($r['last']) echo ', last ' . e(date('d M Y', strtotime($r['last']))); ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="num"><?= $r['bought_qty'] ?: '—' ?></td>
        <td class="num"><?= $r['bought_qty'] ? e(money($r['bought_val'])) : '—' ?></td>
        <td class="num"><?= $r['bought_qty'] ? e(money($r['avg_cost'])) : '—' ?></td>
        <td class="num"><?= $r['sold_qty'] ?: '—' ?></td>
        <td class="num"><?= $r['bought_qty'] && $r['sold_qty'] ? e(money($r['sold_cost'])) : '—' ?></td>
        <td class="num"><?= $r['on_hand'] !== 0 ? (int)$r['on_hand'] : '—' ?></td>
        <td class="num"><?= $r['stock_value'] > 0 ? e(money($r['stock_value'])) : '—' ?></td>
        <td class="num"><?= $r['gap'] === null ? '' : (int)$r['gap'] ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="total">
        <td>Total</td>
        <td class="num"></td><td class="num"><?= e(money($totBought)) ?></td>
        <td class="num"></td><td class="num"></td><td class="num"></td>
        <td class="num"></td><td class="num"><?= e(money($totStock)) ?></td>
        <td class="num"></td>
      </tr>
    </tbody>
  </table>
  </div>
  <p class="desc" style="margin-top:12px;margin-bottom:0">
    A material bought under one name and sold under another appears as two
    rows rather than being quietly matched up — on this database the stock
    import maps names like “Oval Key Chain” to “Key Chain”, so a mismatch is
    worth seeing rather than hiding.
  </p>
</div>

<?php if ($recent): ?>
<div class="card">
  <h2>What was bought, most recent first</h2>
  <p class="desc">The last <?= count($recent) ?> purchases, straight from the
    <a href="purchases.php">Stock purchases</a> page.</p>
  <div class="scroll">
  <table>
    <thead><tr><th>Date</th><th>Material</th><th>Dealer</th>
      <th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Spent</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $p): ?>
      <tr>
        <td><?= e(date('d M Y', strtotime($p['purchase_date']))) ?></td>
        <td><?= e($p['item']) ?></td>
        <td><?= e($p['dealer'] ?? '—') ?></td>
        <td class="num"><?= (int)$p['qty_bought'] ?></td>
        <td class="num"><?= e(money((float)$p['unit_cost'])) ?></td>
        <td class="num"><?= e(money((int)$p['qty_bought'] * (float)$p['unit_cost'])) ?></td>
        <td class="muted"><?= e((string)($p['notes'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</body>
</html>
