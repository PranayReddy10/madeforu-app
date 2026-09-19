<?php
require 'config.php';
$me = require_login();

// ── Date range ─────────────────────────────────────────────
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$preset = $_GET['p'] ?? '';
if ($preset === 'all')     { $from = ''; $to = ''; }
elseif ($preset === '30')  { $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); }
elseif ($preset === 'month'){ $from = date('Y-m-01'); $to = date('Y-m-t'); }
elseif ($preset === 'prev') { $from = date('Y-m-01', strtotime('first day of last month'));
                              $to   = date('Y-m-t', strtotime('last day of last month')); }
$fromOk = ($from !== '' && strtotime($from)) ? date('Y-m-d', strtotime($from)) : null;
$toOk   = ($to   !== '' && strtotime($to))   ? date('Y-m-d', strtotime($to))   : null;

// Order-side range uses order_date; ads use the date the ad actually RAN
// (duration_date), not the date Meesho billed it (deduction_date). Meesho bills
// late — the June file charges May ads on 29-June — so billing date would push
// spend into the wrong month. Fall back to deduction_date when duration is null.
$ADS_DATE = 'COALESCE(duration_date, deduction_date)';
$oWhere = []; $aWhere = [];
if ($fromOk) { $oWhere[] = "order_date >= '" . $conn->real_escape_string($fromOk) . "'";
               $aWhere[] = "$ADS_DATE >= '" . $conn->real_escape_string($fromOk) . "'"; }
if ($toOk)   { $oWhere[] = "order_date <= '" . $conn->real_escape_string($toOk) . "'";
               $aWhere[] = "$ADS_DATE <= '" . $conn->real_escape_string($toOk) . "'"; }
$oSql = $oWhere ? 'WHERE ' . implode(' AND ', $oWhere) : '';
$aSql = $aWhere ? 'WHERE ' . implode(' AND ', $aWhere) : '';

// ── Cost lookup ────────────────────────────────────────────
$costByName = [];
$r = $conn->query('SELECT name, unit_cost FROM meesho_products');
while ($p = $r->fetch_assoc()) $costByName[$p['name']] = (float)$p['unit_cost'];

// ── Pull orders in range ───────────────────────────────────
$rows = $conn->query(
    "SELECT status, quantity, product_name, sku, settlement_price, sale_amount,
            return_amount, is_ad_order, order_date
       FROM meesho_orders $oSql"
)->fetch_all(MYSQLI_ASSOC);

$totalOrders = count($rows);
$byStatus = []; $unitsSold = 0; $adOrders = 0;
$grossSales = 0.0;      // what customers paid (Total Sale Amount)
$settlement = 0.0;      // what Meesho actually paid us
$mfgCost    = 0.0;      // our manufacturing cost on fulfilled orders
$deliveredN = 0; $returnedN = 0; $awaitingPrice = 0;
$perProduct = [];

// Statuses that mean the goods actually went out and were paid for.
$EARNING = ['delivered', 'shipped'];
// Statuses where stock left but came back (cost still incurred).
$LOSSY   = ['returned', 'rto'];

foreach ($rows as $o) {
    $st = $o['status'];
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
    if ((int)$o['is_ad_order'] === 1) $adOrders++;

    $qty  = max(1, (int)$o['quantity']);
    $cost = ($costByName[$o['product_name']] ?? 0) * $qty;
    $settleV = ($o['settlement_price'] !== null) ? (float)$o['settlement_price'] : null;

    $pk = $o['product_name'] ?: ($o['sku'] ?: 'Unknown');
    if (!isset($perProduct[$pk])) {
        $perProduct[$pk] = ['orders'=>0,'units'=>0,'settle'=>0.0,'cost'=>0.0,'delivered'=>0];
    }
    $perProduct[$pk]['orders']++;

    if (in_array($st, $EARNING, true)) {
        $deliveredN++;
        $unitsSold += $qty;
        $perProduct[$pk]['units'] += $qty;
        $perProduct[$pk]['delivered']++;
        $mfgCost += $cost;
        $perProduct[$pk]['cost'] += $cost;
        if ($settleV !== null) {
            $settlement += $settleV;
            $perProduct[$pk]['settle'] += $settleV;
        } else {
            $awaitingPrice++;
        }
        if ($o['sale_amount'] !== null) $grossSales += (float)$o['sale_amount'];
    } elseif (in_array($st, $LOSSY, true)) {
        $returnedN++;
        // Goods were made and shipped, so the manufacturing cost is spent.
        $mfgCost += $cost;
        $perProduct[$pk]['cost'] += $cost;
        // A return usually carries a NEGATIVE settlement (Meesho claws back
        // the sale and charges return shipping) — count it as-is.
        if ($settleV !== null) {
            $settlement += $settleV;
            $perProduct[$pk]['settle'] += $settleV;
        }
    }
    // cancelled / label_* / on_hold: nothing made, nothing earned.
}

// ── Ads ────────────────────────────────────────────────────
$adsRow = $conn->query("SELECT COALESCE(SUM(total_cost),0) t, COUNT(*) c FROM meesho_ads $aSql")
               ->fetch_assoc();
$adsCost   = (float)$adsRow['t'];
$adsRows   = (int)$adsRow['c'];

// ── The bottom line ────────────────────────────────────────
$grossProfit = $settlement - $mfgCost;   // before ads
$netProfit   = $grossProfit - $adsCost;  // after ads

// ── Monthly trend ──────────────────────────────────────────
$trend = [];
$tr = $conn->query(
    "SELECT DATE_FORMAT(order_date,'%Y-%m') m,
            COUNT(*) orders,
            SUM(CASE WHEN status IN ('delivered','shipped') THEN 1 ELSE 0 END) delivered,
            SUM(CASE WHEN status IN ('delivered','shipped') THEN COALESCE(settlement_price,0) ELSE 0 END) settle
       FROM meesho_orders
      WHERE order_date IS NOT NULL " . ($oSql ? str_replace('WHERE', 'AND', $oSql) : '') . "
      GROUP BY m ORDER BY m"
);
while ($t = $tr->fetch_assoc()) $trend[$t['m']] = $t;

$ta = $conn->query(
    "SELECT DATE_FORMAT($ADS_DATE,'%Y-%m') m, COALESCE(SUM(total_cost),0) ads
       FROM meesho_ads " . ($aSql ?: '') . "
      GROUP BY m ORDER BY m"
);
while ($t = $ta->fetch_assoc()) {
    if (!isset($trend[$t['m']])) $trend[$t['m']] = ['m'=>$t['m'],'orders'=>0,'delivered'=>0,'settle'=>0];
    $trend[$t['m']]['ads'] = (float)$t['ads'];
}
ksort($trend);

// Sort products by units sold
uasort($perProduct, function($a, $b) { return $b['units'] <=> $a['units']; });

$STATUS_LABEL = [
    'label_pending'=>'Label not downloaded','label_downloaded'=>'Label downloaded',
    'out_for_delivery'=>'Out for delivery','delivered'=>'Delivered','cancelled'=>'Cancelled',
    'returned'=>'Returned','shipped'=>'Shipped','rto'=>'RTO','on_hold'=>'On hold',
];
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meesho statistics · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{padding:8px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit}
  button,.btn{padding:8px 14px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:13px;
              cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .btn.on{background:#1c2431;color:#fff;border-color:#1c2431}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:11.5px;
     text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:9px 8px;border-bottom:1px solid #eceef0}
  td.n,th.n{text-align:right}
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
  .kpi{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px 16px}
  .kpi b{display:block;font-size:23px;font-weight:700;line-height:1.25}
  .kpi span{font-size:11.5px;color:#65676b;display:block;margin-top:2px}
  .kpi.big{background:#1c2431;border-color:#1c2431}
  .kpi.big b{color:#fff}.kpi.big span{color:#b6bcc6}
  .pos{color:#1a7f4b}.neg{color:#b23a2c}
  .bar{height:7px;border-radius:4px;background:#eceef0;overflow:hidden;margin-top:5px}
  .bar i{display:block;height:100%;background:#1877f2}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .warn{background:#fff8e8;border:1px solid #f0c26b;border-radius:8px;padding:11px 14px;
        margin-bottom:14px;font-size:13px}
  .presets{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
  .flow{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:13px;margin-top:6px}
  .flow div{background:#f7f8fa;border:1px solid #e3e5e9;border-radius:7px;padding:8px 12px}
  .flow .op{background:none;border:none;color:#8a8d91;font-weight:700;padding:0 2px}
  .sub{font-size:11.5px;color:#8a8d91}
</style>
</head>
<body>
<?php
  $PAGE  = 'meesho_stats';
  $TITLE = 'Meesho statistics';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <!-- Range picker -->
  <div class="card">
    <div class="presets">
      <a class="btn <?= ($from===''&&$to==='')?'on':'' ?>" href="meesho_stats.php?p=all">All time</a>
      <a class="btn" href="meesho_stats.php?p=30">Last 30 days</a>
      <a class="btn" href="meesho_stats.php?p=month">This month</a>
      <a class="btn" href="meesho_stats.php?p=prev">Last month</a>
    </div>
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div><label>From</label><input type="date" name="from" value="<?= e($fromOk ?? '') ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= e($toOk ?? '') ?>"></div>
      <div><button class="primary" type="submit">Apply</button></div>
      <div style="margin-left:auto;align-self:center" class="sub">
        <?= $fromOk || $toOk
              ? 'Showing ' . e($fromOk ?? 'start') . ' → ' . e($toOk ?? 'today')
              : 'Showing all time' ?>
      </div>
    </form>
  </div>

  <?php if ($awaitingPrice > 0): ?>
    <div class="warn">
      <strong><?= $awaitingPrice ?> delivered order(s) have no settlement price yet</strong>,
      so the sales and profit below are understated. Upload the latest payment file on the
      <a href="meesho_import.php">Import page</a> to fill them in automatically.
    </div>
  <?php endif; ?>

  <!-- Headline KPIs -->
  <div class="kpis" style="margin-bottom:16px">
    <div class="kpi"><b><?= number_format($totalOrders) ?></b><span>Total orders</span></div>
    <div class="kpi"><b><?= number_format($deliveredN) ?></b><span>Delivered / shipped</span></div>
    <div class="kpi"><b><?= number_format($unitsSold) ?></b><span>Units sold</span></div>
    <div class="kpi"><b><?= money($settlement) ?></b><span>Meesho settlement (sales)</span></div>
    <div class="kpi"><b class="neg"><?= money($adsCost) ?></b><span>Ads cost</span></div>
    <div class="kpi big"><b class="<?= $netProfit >= 0 ? 'pos' : 'neg' ?>" style="color:<?= $netProfit>=0?'#5ee2a0':'#ff8b7d' ?>">
        <?= money($netProfit) ?></b><span>NET PROFIT after ads</span></div>
  </div>

  <!-- Money flow -->
  <div class="card">
    <h2>Where the money went</h2>
    <p class="desc">Meesho pays you a settlement per delivered order. Your manufacturing cost and
       your ad spend both come out of that.</p>
    <div class="flow">
      <div><strong><?= money($settlement) ?></strong><br><span class="sub">Settlement received</span></div>
      <span class="op">−</span>
      <div><strong><?= money($mfgCost) ?></strong><br><span class="sub">Manufacturing cost</span></div>
      <span class="op">=</span>
      <div><strong class="<?= $grossProfit>=0?'pos':'neg' ?>"><?= money($grossProfit) ?></strong><br>
           <span class="sub">Gross profit</span></div>
      <span class="op">−</span>
      <div><strong class="neg"><?= money($adsCost) ?></strong><br><span class="sub">Ads</span></div>
      <span class="op">=</span>
      <div style="background:#1c2431;border-color:#1c2431">
        <strong style="color:<?= $netProfit>=0?'#5ee2a0':'#ff8b7d' ?>"><?= money($netProfit) ?></strong><br>
        <span class="sub" style="color:#b6bcc6">Net profit</span></div>
    </div>
    <?php if ($settlement > 0): ?>
      <p class="sub" style="margin-top:12px">
        Ads eat <strong><?= number_format($adsCost / $settlement * 100, 1) ?>%</strong> of your settlement.
        <?php if ($mfgCost > 0): ?>
          Manufacturing is <strong><?= number_format($mfgCost / $settlement * 100, 1) ?>%</strong>.
        <?php endif; ?>
        <?= $adOrders ?> of <?= $totalOrders ?> orders came from ads.
      </p>
    <?php endif; ?>
    <?php if ($mfgCost == 0.0 && $deliveredN > 0): ?>
      <div class="warn" style="margin-top:12px;margin-bottom:0">
        Manufacturing cost is ₹0 — set a real unit cost for each product on the
        <a href="meesho_products.php">Meesho products</a> page, or profit here is just settlement minus ads.
      </div>
    <?php endif; ?>
  </div>

  <!-- Status breakdown -->
  <div class="card">
    <h2>Order status</h2>
    <table>
      <thead><tr><th>Status</th><th class="n">Orders</th><th class="n">Share</th><th style="width:35%"></th></tr></thead>
      <tbody>
      <?php if (!$byStatus): ?><tr><td colspan="4" class="sub">No orders in this range.</td></tr><?php endif; ?>
      <?php arsort($byStatus); foreach ($byStatus as $st => $n): ?>
        <tr>
          <td><?= e($STATUS_LABEL[$st] ?? $st) ?></td>
          <td class="n"><?= number_format($n) ?></td>
          <td class="n"><?= $totalOrders ? number_format($n/$totalOrders*100,1) : '0' ?>%</td>
          <td><div class="bar"><i style="width:<?= $totalOrders ? ($n/$totalOrders*100) : 0 ?>%"></i></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Per product -->
  <div class="card">
    <h2>By product</h2>
    <p class="desc">Units = magnets shipped (pack size × quantity is already in your product setup).
       Profit here is settlement minus manufacturing, before ads (ads can't be split per product —
       Meesho bills them per campaign).</p>
    <table>
      <thead><tr>
        <th>Product</th><th class="n">Orders</th><th class="n">Delivered</th><th class="n">Units</th>
        <th class="n">Settlement</th><th class="n">Cost</th><th class="n">Gross profit</th>
      </tr></thead>
      <tbody>
      <?php if (!$perProduct): ?><tr><td colspan="7" class="sub">Nothing yet.</td></tr><?php endif; ?>
      <?php foreach ($perProduct as $name => $p): $gp = $p['settle'] - $p['cost']; ?>
        <tr>
          <td><?= e($name) ?></td>
          <td class="n"><?= number_format($p['orders']) ?></td>
          <td class="n"><?= number_format($p['delivered']) ?></td>
          <td class="n"><?= number_format($p['units']) ?></td>
          <td class="n"><?= money($p['settle']) ?></td>
          <td class="n"><?= money($p['cost']) ?></td>
          <td class="n <?= $gp>=0?'pos':'neg' ?>"><strong><?= money($gp) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Monthly -->
  <div class="card">
    <h2>Month by month</h2>
    <table>
      <thead><tr>
        <th>Month</th><th class="n">Orders</th><th class="n">Delivered</th>
        <th class="n">Settlement</th><th class="n">Ads</th><th class="n">Settlement − ads</th>
      </tr></thead>
      <tbody>
      <?php if (!$trend): ?><tr><td colspan="6" class="sub">No data.</td></tr><?php endif; ?>
      <?php foreach ($trend as $m => $t):
              $ads = (float)($t['ads'] ?? 0); $s = (float)($t['settle'] ?? 0); $d = $s - $ads; ?>
        <tr>
          <td><?= e(date('M Y', strtotime($m . '-01'))) ?></td>
          <td class="n"><?= number_format((int)$t['orders']) ?></td>
          <td class="n"><?= number_format((int)$t['delivered']) ?></td>
          <td class="n"><?= money($s) ?></td>
          <td class="n neg"><?= money($ads) ?></td>
          <td class="n <?= $d>=0?'pos':'neg' ?>"><strong><?= money($d) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="sub" style="margin-top:10px">Ads are matched to the month the ad actually
       <strong>ran</strong> (Deduction Duration), not the month Meesho billed it — Meesho bills
       days or weeks late, so billing date would push spend into the wrong month.</p>
  </div>

  <?php if ($adsRows === 0): ?>
    <div class="warn">No ads data for this range. Upload a payment file on the
      <a href="meesho_import.php">Import page</a> — the "Ads Cost" sheet loads automatically.</div>
  <?php endif; ?>
<?php require 'layout_end.php'; ?>

</body>
</html>