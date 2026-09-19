<?php
require 'config.php';
$me = require_login();

// ── Save per-event costs ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $evId  = (int)($_POST['event_id'] ?? 0);
        $costs = $_POST['cost'] ?? [];
        if ($evId < 1)          throw new Exception('Invalid event.');
        if (!is_array($costs))  throw new Exception('Bad input.');

        // Upsert one row per item. A blank field means "use the global cost",
        // so we DELETE that override rather than storing 0.
        $ins = $conn->prepare(
            'INSERT INTO event_item_costs (event_id, item, unit_cost) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE unit_cost = VALUES(unit_cost)'
        );
        $del = $conn->prepare('DELETE FROM event_item_costs WHERE event_id = ? AND item = ?');
        foreach ($costs as $item => $val) {
            $item = (string)$item;
            $val  = trim((string)$val);
            if ($val === '') {
                $del->bind_param('is', $evId, $item);
                $del->execute();
            } else {
                $c = round((float)$val, 2);
                if ($c < 0) $c = 0;
                $ins->bind_param('isd', $evId, $item, $c);
                $ins->execute();
            }
        }
        $ins->close();
        $del->close();
        flash('Costs saved for this event.');
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: event_costs.php?event=' . (int)($_POST['event_id'] ?? 0));
    exit;
}

// ── Which event? Default to the first active one. ──────────────────
$eventId = isset($_GET['event']) ? (int)$_GET['event'] : 0;

$events = $conn->query('SELECT id, name, is_paid, entry_cost, extra_cost, start_date, end_date
                        FROM events ORDER BY is_active DESC, id DESC')->fetch_all(MYSQLI_ASSOC);

if ($eventId < 1 && $events) $eventId = (int)$events[0]['id'];

// Selected event row.
$event = null;
foreach ($events as $ev) {
    if ((int)$ev['id'] === $eventId) { $event = $ev; break; }
}

$rows = [];
$revenue = $cogs = 0.0;   // $revenue here = gross line-item value (pre-discount)
$orderCount = 0;
$grossSales = 0.0;        // order subtotals + additional charges (pre-discount)
$discountTot = 0.0;       // total discount given across the event's orders
$netSales   = 0.0;        // what customers actually pay = subtotal - discount

if ($event) {
    // Per-item revenue + cost for this event, joining costs by item name.
    // LEFT JOIN so an item with no cost row still appears (cost 0).
    // Cost preference: this event's cost, else the global product cost, else 0.
    // eic.unit_cost is per-event; pc.unit_cost is the global fallback.
    $sql = 'SELECT i.item,
                   SUM(i.quantity)                              AS qty,
                   SUM(i.line_total)                            AS revenue,
                   COALESCE(eic.unit_cost, pc.unit_cost, 0)     AS unit_cost,
                   (eic.unit_cost IS NOT NULL)                  AS event_override,
                   SUM(i.quantity) * COALESCE(eic.unit_cost, pc.unit_cost, 0) AS cost
            FROM order_items i
            JOIN orders o                ON o.id = i.order_id
            LEFT JOIN product_costs pc   ON pc.item = i.item
            LEFT JOIN event_item_costs eic ON eic.item = i.item AND eic.event_id = ?
            WHERE o.event_id = ?
            GROUP BY i.item, eic.unit_cost, pc.unit_cost
            ORDER BY revenue DESC';
    $s = $conn->prepare($sql);
    $s->bind_param('ii', $eventId, $eventId);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['profit'] = (float)$r['revenue'] - (float)$r['cost'];
        $r['margin'] = (float)$r['revenue'] > 0 ? $r['profit'] / (float)$r['revenue'] * 100 : 0;
        $rows[] = $r;
        $revenue += (float)$r['revenue'];
        $cogs    += (float)$r['cost'];
    }
    $s->close();

    $s = $conn->prepare('SELECT COUNT(*) c FROM orders WHERE event_id = ?');
    $s->bind_param('i', $eventId);
    $s->execute();
    $orderCount = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();

    // Order-level money: subtotal, discount and actual payable (total).
    // The per-item table above sums line_total (pre-discount, since a discount
    // applies to the whole order, not one item). The headline revenue and the
    // bottom line must use the ACTUAL payable, so pull it straight from orders.
    $s = $conn->prepare(
        'SELECT COALESCE(SUM(subtotal + extra_charge),0) sub,
                COALESCE(SUM(discount),0)  disc,
                COALESCE(SUM(total),0)     net
         FROM orders WHERE event_id = ?'
    );
    $s->bind_param('i', $eventId);
    $s->execute();
    $m = $s->get_result()->fetch_assoc();
    $s->close();
    $grossSales  = (float)$m['sub'];
    $discountTot = (float)$m['disc'];
    $netSales    = (float)$m['net'];
}

// ── Data for the cost editor: every catalogue item, this event's override
//    (if any) and the global cost as the placeholder/default. ───────
$editRows = [];
if ($event) {
    // Global costs.
    $globalCost = [];
    $gc = $conn->query('SELECT item, unit_cost FROM product_costs');
    while ($g = $gc->fetch_assoc()) $globalCost[$g['item']] = (float)$g['unit_cost'];

    // This event's overrides.
    $override = [];
    $oc = $conn->prepare('SELECT item, unit_cost FROM event_item_costs WHERE event_id = ?');
    $oc->bind_param('i', $eventId);
    $oc->execute();
    $ocr = $oc->get_result();
    while ($o = $ocr->fetch_assoc()) $override[$o['item']] = (float)$o['unit_cost'];
    $oc->close();

    // Catalogue = active products, plus any item that has sales at this event.
    $names = array_keys($ITEMS);
    foreach ($rows as $r) if (!in_array($r['item'], $names, true)) $names[] = $r['item'];

    foreach ($names as $n) {
        $editRows[] = [
            'item'     => $n,
            'global'   => $globalCost[$n] ?? 0,
            'override' => array_key_exists($n, $override) ? $override[$n] : null,
        ];
    }
}

$totalQty = 0;
foreach ($rows as $__r) $totalQty += (int)$__r['qty'];

$grossProfit = $netSales - $cogs;   // profit on actual payable, after discount
$entryCost   = $event ? (float)$event['entry_cost'] : 0;
$extraCost   = $event ? (float)$event['extra_cost'] : 0;
$eventCost   = $entryCost + $extraCost;
$netProfit   = $grossProfit - $eventCost;
$netMargin   = $netSales > 0 ? $netProfit / $netSales * 100 : 0;

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event Cost &amp; Profit · Stall Orders</title>
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
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  select{width:100%;max-width:360px;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
  .stat{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:14px}
  .stat .l{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.4px}
  .stat .v{font-size:22px;font-weight:600;margin-top:4px}
  .v.green{color:#1a7f4b}.v.red{color:#c0392b}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:10px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:9px 8px;border-bottom:1px solid #eceef0}
  .r{text-align:right}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  .btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .pos{color:#1a7f4b}.neg{color:#c0392b}.muted{color:#8a8d91}
  .breakdown{background:#f7f8fa;border-radius:10px;padding:16px;max-width:420px}
  .brow{display:flex;justify-content:space-between;padding:6px 0;font-size:15px}
  .brow.sub{color:#65676b;font-size:14px;padding-left:12px}
  .brow.total{border-top:1px solid #dfe1e5;margin-top:8px;padding-top:12px;font-size:19px;font-weight:700}
</style>
</head>
<body>
<?php
  $PAGE  = 'event_costs';
  $TITLE = 'Event P&L';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <?php if (!$events): ?>
    <div class="card"><p class="muted">No events yet. Create one on the Events page first.</p></div>
  <?php else: ?>

  <div class="card">
    <form method="get">
      <label for="event">Choose event</label>
      <select id="event" name="event" onchange="this.form.submit()">
        <?php foreach ($events as $ev): ?>
          <option value="<?= (int)$ev['id'] ?>" <?= (int)$ev['id']===$eventId?'selected':'' ?>>
            <?= e($ev['name']) ?>
            <?php if ($ev['start_date']): ?> — <?= date('d M', strtotime($ev['start_date'])) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($event): ?>

  <div class="stats">
    <div class="stat"><div class="l">Orders</div><div class="v"><?= $orderCount ?></div></div>
    <div class="stat"><div class="l">Revenue</div><div class="v"><?= money($netSales) ?></div>
      <?php if ($discountTot > 0.001): ?><div style="font-size:11px;color:#65676b;margin-top:2px">after <?= money($discountTot) ?> discount</div><?php endif; ?></div>
    <div class="stat"><div class="l">Cost of goods</div><div class="v red"><?= money($cogs) ?></div></div>
    <div class="stat"><div class="l">Event costs</div><div class="v red"><?= money($eventCost) ?></div></div>
    <div class="stat"><div class="l">Net profit</div><div class="v <?= $netProfit>=0?'green':'red' ?>"><?= money($netProfit) ?></div></div>
    <div class="stat"><div class="l">Net margin</div><div class="v <?= $netProfit>=0?'green':'red' ?>"><?= number_format($netMargin,1) ?>%</div></div>
  </div>

  <div class="card">
    <h2>Bottom line</h2>
    <div class="breakdown">
      <div class="brow"><span>Gross sales (incl. charges, before discount)</span><span><?= money($grossSales) ?></span></div>
      <?php if ($discountTot > 0.001): ?>
        <div class="brow"><span>Less discounts given</span><span class="neg">-<?= money($discountTot) ?></span></div>
      <?php endif; ?>
      <div class="brow" style="font-weight:600;border-top:1px solid #e4e6eb;padding-top:8px">
        <span>Revenue (actual payable)</span><span><?= money($netSales) ?></span>
      </div>
      <div class="brow"><span>Less cost of goods</span><span class="neg">-<?= money($cogs) ?></span></div>
      <div class="brow" style="font-weight:600;border-top:1px solid #e4e6eb;padding-top:8px">
        <span>Product profit</span><span class="<?= $grossProfit>=0?'pos':'neg' ?>"><?= money($grossProfit) ?></span>
      </div>
      <div class="brow sub"><span>Entry / stall fee</span><span class="neg">-<?= money($entryCost) ?></span></div>
      <div class="brow sub"><span>Additional event cost</span><span class="neg">-<?= money($extraCost) ?></span></div>
      <div class="brow total">
        <span>Net event profit</span>
        <span class="<?= $netProfit>=0?'pos':'neg' ?>"><?= money($netProfit) ?></span>
      </div>
    </div>
    <?php if ($netProfit < 0): ?>
      <p style="color:#c0392b;font-size:13px;margin-top:12px">
        This event is running at a loss once its ₹<?= number_format($eventCost,0) ?> cost is counted.
      </p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Costs for this event</h2>
    <p class="desc">Sellers change between events, so set what each item cost you
       <strong>at this event</strong>. Leave a box blank to use the global cost
       from the Manufacture price page. Blank shows the global value as a hint.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
      <div style="overflow-x:auto">
        <table>
          <thead>
            <tr><th>Item</th><th class="r">Global cost</th><th class="r">This event\'s cost</th><th>Using</th></tr>
          </thead>
          <tbody>
          <?php foreach ($editRows as $er): ?>
            <tr>
              <td><?= e($er['item']) ?></td>
              <td class="r muted"><?= money($er['global']) ?></td>
              <td class="r">
                <input name="cost[<?= e($er['item']) ?>]" type="number" step="0.01" min="0"
                       style="width:120px;padding:7px 9px;border:1px solid #ccd0d5;border-radius:6px;text-align:right"
                       value="<?= $er['override'] !== null ? number_format($er['override'],2,'.','') : '' ?>"
                       placeholder="<?= number_format($er['global'],2,'.','') ?>">
              </td>
              <td>
                <?php if ($er['override'] !== null): ?>
                  <span class="badge" style="background:#fdf3e0;color:#a06a00;padding:2px 8px;border-radius:20px;font-size:12px">Event price</span>
                <?php else: ?>
                  <span class="muted" style="font-size:12px">Global</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn" style="margin-top:14px;background:#1877f2;color:#fff;border-color:#1877f2">Save event costs</button>
    </form>
  </div>

  <div class="card">
    <h2>By product</h2>
    <?php if (!$rows): ?>
      <p class="muted">No sales recorded for this event yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr><th>Item</th><th class="r">Sold</th><th class="r">Gross</th>
              <th class="r">Unit cost</th><th class="r">Cost</th><th class="r">Profit</th><th class="r">Margin</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['item']) ?></td>
            <td class="r"><?= (int)$r['qty'] ?></td>
            <td class="r"><?= money($r['revenue']) ?></td>
            <td class="r">
              <span class="muted"><?= money($r['unit_cost']) ?></span>
              <?php if (!empty($r['event_override'])): ?>
                <span style="color:#a06a00;font-size:11px">·event</span>
              <?php endif; ?>
            </td>
            <td class="r"><?= money($r['cost']) ?></td>
            <td class="r <?= $r['profit']>=0?'pos':'neg' ?>"><?= money($r['profit']) ?></td>
            <td class="r <?= $r['profit']>=0?'pos':'neg' ?>"><?= number_format($r['margin'],1) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php $grossLineProfit = $revenue - $cogs; ?>
          <tr>
            <td>Product totals</td>
            <td class="r"><?= $totalQty ?></td>
            <td class="r"><?= money($revenue) ?></td>
            <td class="r"></td>
            <td class="r"><?= money($cogs) ?></td>
            <td class="r <?= $grossLineProfit>=0?'pos':'neg' ?>"><?= money($grossLineProfit) ?></td>
            <td class="r"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p class="desc" style="margin-top:12px">Gross is the pre-discount value of each item's sales. Order-level
       discounts<?php if ($discountTot > 0.001): ?> (<?= money($discountTot) ?> total)<?php endif; ?>
       are applied to the whole order, so they appear in the Bottom line above rather than per item.
       Cost of goods uses this event's costs where set
       (marked <span style="color:#a06a00">·event</span>), otherwise the global cost.
       Items showing ₹0 have no cost entered anywhere.</p>
    <?php endif; ?>
  </div>

  <?php endif; /* event */ ?>
  <?php endif; /* events exist */ ?>
<?php require 'layout_end.php'; ?>

</body>
</html>