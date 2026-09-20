<?php
require 'config.php';
require_once __DIR__ . '/lib_trade.php';   // sales channels
$me = require_login();

// Inline SVG reused across the buttons below.
define('WA_SVG', '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true">'
  . '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.05-1.32A10 10 0 1 0 12 2zm5.47 12.38c-.3-.15-1.75-.86-2.02-.96'
  . '-.27-.1-.47-.15-.67.15s-.77.96-.94 1.16c-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75'
  . '-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6'
  . '-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.01-1.04 2.47 1.06 2.86 1.21 3.06c.15.2 '
  . '2.09 3.2 5.08 4.48.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.69.25-1.28.17'
  . '-1.41-.07-.13-.27-.2-.57-.35z"/></svg>');

$id = (int)($_GET['id'] ?? 0);

$s = $conn->prepare('SELECT o.*, a.name AS admin_name FROM orders o
                     LEFT JOIN admins a ON a.id = o.created_by WHERE o.id = ?');
$s->bind_param('i', $id);
$s->execute();
$o = $s->get_result()->fetch_assoc();
$s->close();
if (!$o) { flash('Order not found.', 'error'); header('Location: index.php'); exit; }

// Where "Back to orders" should return: the list this order belongs to
// (its event, or the offline list), so event context is not lost.
$backEvent = ($o['event_id'] !== null) ? (string)(int)$o['event_id'] : '0';
$backUrl   = 'index.php?event=' . urlencode($backEvent);

$s = $conn->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$s->bind_param('i', $id);
$s->execute();
$items = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

$s = $conn->prepare('SELECT p.*, a.name AS admin_name FROM payments p
                     LEFT JOIN admins a ON a.id = p.taken_by
                     WHERE p.order_id = ? ORDER BY p.id');
$s->bind_param('i', $id);
$s->execute();
$payments = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

$subtotal = (float)$o['subtotal'];
$disc     = (float)$o['discount'];
$extra    = (float)$o['extra_charge'];
$total    = (float)$o['total'];
$paid     = (float)$o['paid_amount'];
$balance  = max($total - $paid, 0);

// The discount cannot push the total below what has already been collected.
// The cap is measured against the whole charged base (goods + extras),
// because the extra charge is part of what the customer owes.
$maxDiscount = max($subtotal + $extra - $paid, 0);
$status  = pay_status($total, $paid);
$pct     = $total > 0 ? min(100, round($paid / $total * 100)) : 0;

// ── WhatsApp links ─────────────────────────────────────────────────
// Build a readable item list from the real line items.
$itemLines = [];
foreach ($items as $it) {
    $itemLines[] = '- ' . $it['item'] . ' x' . (int)$it['quantity']
                 . ' = ' . money($it['line_total']);
}
$itemText = implode("\n", $itemLines);

$waSummary = whatsapp_link($o['phone'], whatsapp_message($o, $itemText));

// A shorter nudge for when money is outstanding.
$waReminder = null;
if ($balance > 0.001) {
    $msg = "Hello " . $o['name'] . ",\n\n"
         . "A gentle reminder about order " . $o['order_no'] . ".\n"
         . "Total: " . money($total) . "\n"
         . "Paid: " . money($paid) . "\n"
         . "Balance due: " . money($balance) . "\n\n"
         . "Thank you!";
    $waReminder = whatsapp_link($o['phone'], $msg);
}

// A pickup nudge for when the order is made but not collected.
$waShipped = null;
if (is_dispatched($o) && (int)$o['is_delivered'] === 0) {
    $waShipped = whatsapp_link($o['phone'], dispatch_message($o));
}

// A couriered parcel is shipped, not collected, so the shipped nudge covers it.
$waReady = null;
if (!is_dispatched($o) && (int)$o['is_ready'] === 1 && (int)$o['is_delivered'] === 0) {
    $msg = "Hello " . $o['name'] . ",\n\n"
         . "Your order " . $o['order_no'] . " is ready for collection.\n"
         . ($balance > 0.001 ? "Balance due on pickup: " . money($balance) . "\n" : "")
         . "\nSee you soon!";
    $waReady = whatsapp_link($o['phone'], $msg);
}

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit <?= e($o['order_no']) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .meta{font-family:monospace;font-size:13px;color:#65676b}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:14px}

  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;
              cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .green{background:#1a7f4b;color:#fff;border-color:#1a7f4b}
  .green:hover{background:#166b3f}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .danger:hover{background:#fdeceb}

  .paybox{background:#f7f8fa;border-radius:10px;padding:16px;margin-bottom:16px}
  .prow{display:flex;justify-content:space-between;align-items:baseline;padding:6px 0;font-size:15px}
  .prow.due{font-size:22px;font-weight:700;border-top:1px solid #dfe1e5;margin-top:8px;padding-top:12px}
  .due-amt{color:#c0392b}
  .due-amt.clear{color:#1a7f4b}
  .bar{height:8px;background:#e4e6eb;border-radius:20px;overflow:hidden;margin:12px 0 6px}
  .bar span{display:block;height:100%;background:#1a7f4b;border-radius:20px}
  .barlbl{font-size:12px;color:#65676b;text-align:right}

  .badge{display:inline-block;padding:4px 11px;border-radius:20px;font-size:13px;font-weight:500}
  .b-paid{background:#e3f5eb;color:#1a7f4b}
  .b-unpaid{background:#fdeceb;color:#c0392b}
  .b-partial{background:#fdf3e0;color:#a06a00}

  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:8px;border-bottom:2px solid #dfe1e5;font-size:12px;
     text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:9px 8px;border-bottom:1px solid #eceef0}
  .r{text-align:right}

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

  .check{display:flex;align-items:center;gap:8px;padding:10px;background:#f7f8fa;border-radius:6px}
  .check input{width:auto}
  .check label{margin:0;font-size:14px;color:#1c1e21}
  .check.dim label{color:#8a8d91}

  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .quick{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
  .quick button{font-size:13px;padding:6px 12px}
  .empty{color:#8a8d91;font-size:14px;padding:12px 0}
  .hint{font-size:12px;color:#8a8d91;margin-top:6px}
  .btn-wa{background:#25d366;color:#fff;border-color:#25d366;display:inline-flex;align-items:center;gap:7px}
  .btn-wa:hover{background:#1fa855;color:#fff}
  .btn-wa-o{background:#fff;color:#1fa855;border-color:#9fe0b8;display:inline-flex;align-items:center;gap:7px}
  .btn-wa-o:hover{background:#e8f7ee;color:#188548}
  .wa-row{display:flex;gap:10px;flex-wrap:wrap}
  .wa-none{color:#8a8d91;font-size:14px}
  .quick-d{display:flex;gap:6px}
  .dispatch{margin-top:18px;padding:14px;border:1px solid #dfe3e8;border-radius:8px;background:#fafbfc}
  .dhint{font-size:12px;color:#8a8d91;margin:6px 0 0}
  .ol-check{display:flex;align-items:center;gap:9px;margin:0;cursor:pointer;
            font-size:14px;font-weight:500;color:#1c1e21}
  .ol-check input{width:17px;height:17px;margin:0;cursor:pointer;accent-color:#e11d48}
  .dispatch .grid{margin-top:14px}
  .btn-dhl{background:#e11d48;color:#fff;border-color:#e11d48;display:inline-flex;
           align-items:center;justify-content:center;gap:6px;width:100%}
  .btn-dhl:hover{background:#be123c;color:#fff}
  .quick-d button{flex:1;padding:9px 4px;font-size:13px}
</style>
</head>
<body>
<?php
  $PAGE  = 'orders';   // highlight Orders in the sidebar
  $TITLE = 'Edit order';
  ob_start(); ?>
    <span class="meta"><?= e($o['order_no']) ?> · <?= e($o['name']) ?> ·
      created <?= date('d M Y, g:i a', strtotime($o['created_at'])) ?><?php if ($o['admin_name']): ?> by <?= e($o['admin_name']) ?><?php endif; ?></span>
    <a class="btn" href="<?= e($backUrl) ?>" style="margin-left:12px">Back to orders</a>
  <?php $TOPBAR_HTML = ob_get_clean();
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Contact <?= e($o['name']) ?></h2>
    <?php if ($waSummary): ?>
      <div class="wa-row">
        <a class="btn btn-wa" href="<?= e($waSummary) ?>" target="_blank" rel="noopener">
          <?= WA_SVG ?> Send order summary
        </a>
        <?php if ($waShipped): ?>
          <a class="btn btn-wa-o" href="<?= e($waShipped) ?>" target="_blank" rel="noopener">
            <?= WA_SVG ?> "Shipped" + tracking
          </a>
        <?php endif; ?>
        <?php if ($waReady): ?>
          <a class="btn btn-wa-o" href="<?= e($waReady) ?>" target="_blank" rel="noopener">
            <?= WA_SVG ?> "Ready for pickup"
          </a>
        <?php endif; ?>
        <?php if ($waReminder): ?>
          <a class="btn btn-wa-o" href="<?= e($waReminder) ?>" target="_blank" rel="noopener">
            <?= WA_SVG ?> Payment reminder
          </a>
        <?php endif; ?>
        <a class="btn" href="tel:<?= e($o['phone']) ?>">Call <?= e($o['phone']) ?></a>
      </div>
      <p class="hint">Opens WhatsApp with the message ready. Nothing is sent until you press send.</p>
    <?php else: ?>
      <p class="wa-none">No valid phone number on this order.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Payment
      <span class="badge b-<?= $status ?>" style="float:right">
        <?= ['paid'=>'Fully paid','partial'=>'Part paid','unpaid'=>'Unpaid'][$status] ?>
      </span>
    </h2>

    <div class="paybox">
      <div class="prow"><span>Subtotal</span><span><?= money($subtotal) ?></span></div>
      <?php if ($extra > 0.001): ?>
        <div class="prow">
          <span><?= $o['extra_charge_reason'] ? e($o['extra_charge_reason']) : 'Additional charges' ?></span>
          <span>+<?= money($extra) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($disc > 0.001): ?>
        <div class="prow">
          <span>Discount<?= $o['discount_reason'] ? ' — ' . e($o['discount_reason']) : '' ?></span>
          <span style="color:#a06a00">-<?= money($disc) ?></span>
        </div>
      <?php endif; ?>
      <div class="prow"><span>Order total</span><span style="font-weight:600"><?= money($total) ?></span></div>
      <div class="prow"><span>Paid so far</span><span style="color:#1a7f4b;font-weight:600"><?= money($paid) ?></span></div>
      <div class="prow due">
        <span>Remaining</span>
        <span class="due-amt <?= $balance <= 0.001 ? 'clear' : '' ?>"><?= money($balance) ?></span>
      </div>
      <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
      <div class="barlbl"><?= $pct ?>% collected</div>
    </div>

    <?php if ($balance > 0.001): ?>
      <form method="post" action="save.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
        <div class="grid">
          <div>
            <label for="amount">Amount received now</label>
            <input id="amount" name="amount" type="number" step="0.01" min="0.01"
                   max="<?= number_format($balance, 2, '.', '') ?>" required
                   placeholder="Max <?= number_format($balance, 2, '.', '') ?>">
          </div>
          <div>
            <label for="pmode">Payment mode</label>
            <select id="pmode" name="payment_mode">
              <?php foreach ($PAYMENT_MODES as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="pnote">Note (optional)</label>
            <input id="pnote" name="note" maxlength="120" placeholder="e.g. balance settled">
          </div>
        </div>
        <div class="quick">
          <button type="button" class="btn" onclick="setAmt(<?= $balance ?>)">Full balance <?= money($balance) ?></button>
          <?php if ($balance >= 2): ?>
            <button type="button" class="btn" onclick="setAmt(<?= round($balance/2, 2) ?>)">Half <?= money($balance/2) ?></button>
          <?php endif; ?>
        </div>
        <button type="submit" class="green" style="margin-top:14px">Record payment</button>
      </form>
    <?php else: ?>
      <p style="color:#1a7f4b;font-weight:500">This order is fully paid.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Payment history</h2>
    <?php if (!$payments): ?>
      <p class="empty">No payments recorded yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Date</th><th>Mode</th><th>Taken by</th><th>Note</th><th class="r">Amount</th><th></th></tr></thead>
        <tbody>
        <?php $run = 0; foreach ($payments as $p): $run += (float)$p['amount']; ?>
          <tr>
            <td style="font-size:13px"><?= date('d M, g:i a', strtotime($p['created_at'])) ?></td>
            <td style="text-transform:uppercase;font-size:12px"><?= e($p['mode']) ?></td>
            <td style="font-size:13px;color:#65676b"><?= e($p['admin_name'] ?? '—') ?></td>
            <td style="font-size:13px;color:#65676b"><?= e($p['note'] ?? '—') ?></td>
            <td class="r" style="font-weight:600;color:#1a7f4b"><?= money($p['amount']) ?></td>
            <td class="r">
              <form method="post" action="save.php" onsubmit="return confirm('Remove this payment?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_payment">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <button class="danger" style="padding:4px 9px;font-size:12px">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" style="font-weight:600">Total collected</td>
            <td class="r" style="font-weight:600;color:#1a7f4b"><?= money($run) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Order details</h2>
    <form method="post" action="save.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">

      <div class="grid" style="margin-bottom:18px">
        <div>
          <label for="name">Customer name</label>
          <input id="name" name="name" required maxlength="120" value="<?= e($o['name']) ?>">
        </div>
        <div>
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" required pattern="[0-9]{10}" maxlength="20"
                 inputmode="numeric" value="<?= e($o['phone']) ?>">
        </div>
        <?php $CHANNELS = channels_all($conn, true);
              $curChan = isset($o['channel_id']) && $o['channel_id'] !== null ? (int)$o['channel_id'] : null;
              if ($CHANNELS): ?>
        <div>
          <label for="channel_id">Channel</label>
          <select id="channel_id" name="channel_id" onchange="calc()">
            <option value="">Not recorded</option>
            <?php foreach ($CHANNELS as $ch): ?>
              <option value="<?= (int)$ch['id'] ?>" <?= $curChan === (int)$ch['id'] ? 'selected' : '' ?>><?= e($ch['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted" style="font-size:12px;margin-top:4px">
            Changing this does not reprice what is already on the order.
          </div>
        </div>
        <?php endif; ?>
        <div>
          <label for="notes">Notes</label>
          <input id="notes" name="notes" maxlength="255" value="<?= e($o['notes']) ?>">
        </div>
      </div>

      <label style="margin-bottom:8px">Products</label>
      <div id="lines"></div>
      <button type="button" class="btn" onclick="addLine()">+ Add product</button>

      <div class="grid" style="margin-top:16px">
        <div>
          <label for="extra_charge">Additional charges (₹)</label>
          <input id="extra_charge" name="extra_charge" type="number" step="0.01" min="0"
                 value="<?= number_format($extra, 2, '.', '') ?>" oninput="calc()">
        </div>
        <div>
          <label for="extra_charge_reason">Charge reason</label>
          <input id="extra_charge_reason" name="extra_charge_reason" maxlength="120"
                 value="<?= e($o['extra_charge_reason'] ?? '') ?>"
                 placeholder="e.g. delivery, rush fee">
        </div>
        <div></div>
      </div>

      <div class="grid" style="margin-top:16px">
        <div>
          <label for="discount">Discount (₹ off)</label>
          <input id="discount" name="discount" type="number" step="0.01" min="0"
                 value="<?= number_format($disc, 2, '.', '') ?>" oninput="calc()">
        </div>
        <div>
          <label for="discount_reason">Discount reason</label>
          <input id="discount_reason" name="discount_reason" maxlength="120"
                 value="<?= e($o['discount_reason']) ?>" placeholder="e.g. bulk order">
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

      <?php if ($o['event_id'] === null): // Stall orders are handed over in person. ?>
      <div class="dispatch">
        <label class="ol-check">
          <input type="checkbox" id="is_online" name="is_online" value="1"
                 <?= is_dispatched($o) ? 'checked' : '' ?> onchange="toggleOnline()">
          <span>Online delivery order (shipped by Delhivery)</span>
        </label>
        <p class="dhint">Tick this for parcels you courier. Any delivery fee you
           charge goes in <strong>Additional charges</strong> above &mdash; never in
           Discount, which subtracts from the total.</p>

        <div id="onlineFields" class="grid" style="display:none">
          <div>
            <label for="awb">Tracking number (AWB)</label>
            <input id="awb" name="awb" maxlength="60" value="<?= e($o['awb'] ?? '') ?>"
                   placeholder="e.g. 1234567890123" autocomplete="off">
          </div>
          <div>
            <label for="dispatch_date">Dispatch date</label>
            <input id="dispatch_date" name="dispatch_date" type="date"
                   value="<?= e($o['dispatch_date'] ?? '') ?>">
            <p class="dhint">Defaults to today if left blank.</p>
          </div>
          <?php if ($link = delhivery_link($o['awb'] ?? null)): ?>
          <div>
            <label>&nbsp;</label>
            <a class="btn btn-dhl" href="<?= e($link) ?>" target="_blank" rel="noopener">
              Track on Delhivery ↗
            </a>
          </div>
          <?php else: ?>
          <div></div>
          <?php endif; ?>
        </div>
        <p class="dhint" id="onlineHint" style="display:none">
          Saving a tracking number marks the order <strong>Shipped</strong> on the
          customer's tracking page. It does not mark it delivered.
        </p>
      </div>
      <?php endif; ?>

      <div style="background:#f7f8fa;border-radius:8px;padding:14px;margin:16px 0">
        <div class="prow"><span>Subtotal</span><span id="s-sub">₹0.00</span></div>
        <div class="prow" id="row-extra" style="display:none">
          <span>Additional charges</span><span id="s-extra">+₹0.00</span>
        </div>
        <div class="prow" id="row-disc" style="display:none">
          <span>Discount</span><span style="color:#a06a00" id="s-disc">-₹0.00</span>
        </div>
        <div class="prow" style="font-size:17px;font-weight:600;border-top:1px solid #dfe1e5;margin-top:6px;padding-top:10px">
          <span>New order total</span><span id="s-total">₹0.00</span>
        </div>
        <p class="hint" id="disc-warn" style="display:none;color:#c0392b"></p>
        <?php if ($paid > 0): ?>
          <p class="hint"><?= money($paid) ?> already collected. The total cannot go below this,
             so the discount is capped at <?= money($maxDiscount) ?>.</p>
        <?php endif; ?>
      </div>

      <label style="margin-bottom:8px">Fulfilment status</label>
      <div class="grid" style="margin-bottom:8px">
        <div class="check" id="wrap_ready">
          <input type="checkbox" id="is_ready" name="is_ready" value="1"
                 <?= $o['is_ready']?'checked':'' ?> onchange="syncStatus('ready')">
          <label for="is_ready">Order ready</label>
        </div>
        <div class="check">
          <input type="checkbox" id="is_delivered" name="is_delivered" value="1"
                 <?= $o['is_delivered']?'checked':'' ?> onchange="syncStatus('delivered')">
          <label for="is_delivered">Handed over</label>
        </div>
      </div>
      <p class="hint" style="margin-bottom:18px">Handed over always implies ready. Unticking ready clears handover.</p>

      <div style="display:flex;gap:10px">
        <button type="submit" class="primary">Save changes</button>
        <a class="btn" href="<?= e($backUrl) ?>">Back to orders</a>
      </div>
    </form>
  </div>

  <div style="margin-bottom:32px">
    <form method="post" action="save.php" onsubmit="return confirm('Delete this order and all its payments?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <button class="danger">Delete order</button>
    </form>
  </div>
<?php require 'layout_end.php'; ?>

<script>

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

const ITEMS    = <?= json_encode($ITEMS) ?>;
/* Per-channel overrides, used ONLY for lines added to this order now. A
   line already on the order keeps what it sold for -- see subtotal(). */
const CHANNEL_PRICES = <?= json_encode(channel_prices_all($conn)) ?>;
const MAXQ     = <?= max($QTY_OPTIONS) ?>;

/* Today's price for a NEW line, through the channel now selected. */
function rateFor(name) {
  const sel = document.getElementById('channel_id');
  const cid = sel ? sel.value : '';
  if (cid && CHANNEL_PRICES[cid] && CHANNEL_PRICES[cid][name] !== undefined) {
    return CHANNEL_PRICES[cid][name];
  }
  return ITEMS[name] !== undefined ? ITEMS[name] : 0;
}
// Each existing line carries the price it was SOLD at. Without it the
// form priced every line from today's catalogue, so raising a product
// from 50 to 52 made every old order on this screen read 52 -- while the
// database still held 50, because save.php keeps the agreed price. The
// figures were never wrong; this screen was.
const EXISTING = <?php
  $seed = [];
  foreach ($items as $i) {
      $seed[] = ['item' => $i['item'], 'qty' => (int)$i['quantity'],
                 'sold' => round((float)$i['unit_price'], 2)];
  }
  echo json_encode($seed);
?>;
let seq = 0;

function setAmt(v) { document.getElementById('amount').value = v.toFixed(2); }

function syncStatus(changed) {
  const r = document.getElementById('is_ready');
  const d = document.getElementById('is_delivered');
  if (changed === 'delivered' && d.checked) r.checked = true;
  if (changed === 'ready' && !r.checked)    d.checked = false;
  document.getElementById('wrap_ready').classList.toggle('dim', d.checked);
}

/**
 * `sold` is the price this line was billed at, for a line that already
 * exists on the order. It wins over the catalogue price until the
 * product on the line is changed, at which point the line is a new
 * agreement and takes today's rate -- the same rule save.php applies
 * when it writes.
 */
function addLine(item = '', qty = 1, sold = null) {
  const id = 'ln' + (seq++);
  const opts = Object.entries(ITEMS).map(([n, p]) =>
    `<option value="${escAttr(n)}" data-price="${p}" ${n === item ? 'selected' : ''}>${esc(n)} — ₹${p}</option>`).join('');

  const div = document.createElement('div');
  div.className = 'line';
  div.id = id;
  if (sold !== null) div.dataset.sold = sold;
  div.innerHTML = `
    <div class="cbx">
      <select name="item[]" required onchange="dropSold(this); calc()" style="display:none">
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
      ? hits.map(([n,p]) =>
          `<div class="cbx-opt" data-val="${escAttr(n)}">${esc(n)}<small>₹${p}</small></div>`).join('')
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

const PAID = <?= json_encode(round($paid, 2)) ?>;

/** Swapping the product makes the line a new agreement at today's price. */
function dropSold(sel) {
  const line = sel.closest('.line');
  if (line) delete line.dataset.sold;
}

function subtotal() {
  let sub = 0;
  document.querySelectorAll('.line').forEach(l => {
    const sel  = l.querySelector('select[name="item[]"]');
    const list = sel.value ? parseFloat(rateFor(sel.value)) || 0 : 0;
    // A line already on this order keeps what it sold for; only a new
    // or swapped line takes the catalogue price. This mirrors save.php
    // exactly, so what is shown is what will be stored.
    const sold = l.dataset.sold !== undefined ? parseFloat(l.dataset.sold) : null;
    const rate = sold !== null ? sold : list;
    const q    = parseInt(l.querySelector('[name="quantity[]"]').value) || 0;
    const lt   = rate * q;

    const cell = l.querySelector('.lt');
    cell.textContent = '₹' + lt.toFixed(2);
    // Say why, when the two differ, rather than leaving someone to
    // wonder why the total is not quantity times the price list.
    if (sold !== null && Math.abs(sold - list) > 0.001) {
      cell.title = 'Sold at ₹' + sold.toFixed(2) + ' each; the catalogue now says ₹'
                 + list.toFixed(2) + '.';
      cell.innerHTML = '₹' + lt.toFixed(2)
        + '<div style="font-size:11px;color:#65676b">at ₹' + sold.toFixed(2)
        + ' each · now ₹' + list.toFixed(2) + '</div>';
    } else {
      cell.title = '';
    }
    sub += lt;
  });
  return sub;
}

function calc() {
  const sub     = subtotal();
  const discEl  = document.getElementById('discount');
  const extraEl = document.getElementById('extra_charge');

  // Additional charges are never negative -- that would be a discount,
  // and it would slip past the "cannot go below paid" guard.
  let extra = parseFloat(extraEl.value) || 0;
  if (extra < 0) { extra = 0; extraEl.value = '0'; }

  // Mirrors the server: the discount ceiling is the whole charged base.
  const base = sub + extra;
  let disc = parseFloat(discEl.value) || 0;
  if (disc < 0)    { disc = 0;    discEl.value = '0'; }
  if (disc > base) { disc = base; discEl.value = base.toFixed(2); }

  const total = base - disc;
  const warn  = document.getElementById('disc-warn');

  // Warn before submitting rather than after the server rejects it.
  if (total < PAID - 0.001) {
    warn.textContent = 'This total is below the ₹' + PAID.toFixed(2)
      + ' already collected. Reduce the discount to ₹'
      + Math.max(base - PAID, 0).toFixed(2) + ' or less.';
    warn.style.display = 'block';
  } else {
    warn.style.display = 'none';
  }

  document.getElementById('s-sub').textContent   = '₹' + sub.toFixed(2);
  document.getElementById('s-extra').textContent = '+₹' + extra.toFixed(2);
  document.getElementById('row-extra').style.display = extra > 0 ? 'flex' : 'none';
  document.getElementById('s-disc').textContent  = '-₹' + disc.toFixed(2);
  document.getElementById('row-disc').style.display = disc > 0 ? 'flex' : 'none';
  document.getElementById('s-total').textContent = '₹' + total.toFixed(2);
}

function setDisc(v) {
  document.getElementById('discount').value = v.toFixed(2);
  calc();
}

function discPct(pct) { setDisc(subtotal() * pct / 100); }

/* Show the AWB fields only when the order is marked as an online delivery.
   Unticking clears the inputs so a stale AWB cannot be resubmitted -- the
   server clears it regardless, but leaving stale text on screen is a lie. */
function toggleOnline() {
  const box = document.getElementById('is_online');
  if (!box) return;                       // stall order: block not rendered
  const on  = box.checked;
  document.getElementById('onlineFields').style.display = on ? '' : 'none';
  document.getElementById('onlineHint').style.display   = on ? '' : 'none';
  if (!on) {
    const awb = document.getElementById('awb');
    const dd  = document.getElementById('dispatch_date');
    if (awb) awb.value = '';
    if (dd)  dd.value  = '';
  }
}

if (EXISTING.length) EXISTING.forEach(r => addLine(r.item, r.qty, r.sold ?? null));
else addLine();
syncStatus('init');
attachPhone('phone');
toggleOnline();
</script>
</body>
</html>