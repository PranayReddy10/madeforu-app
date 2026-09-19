<?php
require 'config.php';
$me = require_login();

// ── Save edited costs ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $costs = $_POST['cost'] ?? [];
        if (!is_array($costs)) throw new Exception('Bad input.');

        $stmt = $conn->prepare(
            'INSERT INTO product_costs (item, unit_cost) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE unit_cost = VALUES(unit_cost)'
        );
        foreach ($ITEMS as $name => $_price) {
            $c = round((float)($costs[$name] ?? 0), 2);
            if ($c < 0) $c = 0;
            $stmt->bind_param('sd', $name, $c);
            $stmt->execute();
        }
        $stmt->close();
        flash('Costs saved.');
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: costs.php');
    exit;
}

$costs = product_costs($conn, $ITEMS);

// ── Units sold + revenue per item, from real orders ────────────────
$sold = [];
$res = $conn->query('SELECT item, SUM(quantity) qty, SUM(line_total) revenue
                     FROM order_items GROUP BY item');
while ($r = $res->fetch_assoc()) {
    $sold[$r['item']] = ['qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']];
}

// ── Build the table + totals ───────────────────────────────────────
$rows = [];
$totRevenue = $totCost = $totProfit = 0.0;
// Include active catalogue items AND any item with sales history, so hiding
// a product from the catalogue does not erase its profit from this report.
$allNames = array_keys($ITEMS);
foreach (array_keys($sold) as $soldName) {
    if (!in_array($soldName, $allNames, true)) $allNames[] = $soldName;
}
foreach ($allNames as $name) {
    $price   = $ITEMS[$name] ?? ($sold[$name]['qty'] ? $sold[$name]['revenue'] / $sold[$name]['qty'] : 0);
    $qty     = $sold[$name]['qty']     ?? 0;
    $revenue = $sold[$name]['revenue'] ?? 0.0;
    $unit    = $costs[$name] ?? 0.0;
    $cost    = $unit * $qty;
    $profit  = $revenue - $cost;
    $margin  = $revenue > 0 ? ($profit / $revenue * 100) : 0;

    $rows[] = compact('name','price','unit','qty','revenue','cost','profit','margin');
    $totRevenue += $revenue; $totCost += $cost; $totProfit += $profit;
}
$totMargin = $totRevenue > 0 ? ($totProfit / $totRevenue * 100) : 0;

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Costs &amp; Profit · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .wrap{max-width:1000px;margin:0 auto}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .nav h1{font-size:21px;font-weight:600}
  .nav .who{font-size:13px;color:#65676b}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px}
  .stat .l{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:23px;font-weight:600;margin-top:4px}
  .v.green{color:#1a7f4b}.v.red{color:#c0392b}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:10px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .r{text-align:right}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  input{width:110px;padding:7px 9px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;text-align:right}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .pos{color:#1a7f4b}.neg{color:#c0392b}
  .muted{color:#8a8d91}
</style>
</head>
<body>
<?php
  $PAGE  = 'costs';
  $TITLE = 'Manufacture price';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="l">Revenue</div><div class="v"><?= money($totRevenue) ?></div></div>
    <div class="stat"><div class="l">Cost of goods</div><div class="v red"><?= money($totCost) ?></div></div>
    <div class="stat"><div class="l">Profit</div><div class="v <?= $totProfit >= 0 ? 'green' : 'red' ?>"><?= money($totProfit) ?></div></div>
    <div class="stat"><div class="l">Margin</div><div class="v <?= $totProfit >= 0 ? 'green' : 'red' ?>"><?= number_format($totMargin, 1) ?>%</div></div>
  </div>

  <div class="card">
    <h2>Product costs &amp; margin</h2>
    <p class="desc">Costs start as placeholders. Edit them here and press Save.
       Profit is revenue from real orders minus cost &times; units sold.</p>

    <form method="post">
      <?= csrf_field() ?>
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr>
              <th>Item</th><th class="r">Sell price</th><th class="r">Unit cost</th>
              <th class="r">Sold</th><th class="r">Revenue</th><th class="r">Cost</th>
              <th class="r">Profit</th><th class="r">Margin</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['name']) ?></td>
              <td class="r muted"><?= money($r['price']) ?></td>
              <td class="r">
                <input name="cost[<?= e($r['name']) ?>]" type="number" step="0.01" min="0"
                       value="<?= number_format($r['unit'], 2, '.', '') ?>">
              </td>
              <td class="r"><?= $r['qty'] ?></td>
              <td class="r"><?= money($r['revenue']) ?></td>
              <td class="r"><?= money($r['cost']) ?></td>
              <td class="r <?= $r['profit'] >= 0 ? 'pos' : 'neg' ?>"><?= money($r['profit']) ?></td>
              <td class="r <?= $r['profit'] >= 0 ? 'pos' : 'neg' ?>">
                <?= $r['revenue'] > 0 ? number_format($r['margin'], 1) . '%' : '—' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4">Total</td>
              <td class="r"><?= money($totRevenue) ?></td>
              <td class="r"><?= money($totCost) ?></td>
              <td class="r <?= $totProfit >= 0 ? 'pos' : 'neg' ?>"><?= money($totProfit) ?></td>
              <td class="r <?= $totProfit >= 0 ? 'pos' : 'neg' ?>"><?= number_format($totMargin, 1) ?>%</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <button type="submit" class="primary" style="margin-top:16px">Save costs</button>
    </form>
  </div>
<?php require 'layout_end.php'; ?>

</body>
</html>