<?php
// track.php — Public order tracking + promo page. NO login required.
require __DIR__ . '/config.php';

$INSTAGRAM = 'https://www.instagram.com/madeforu_gifts/';
$WEBSITE   = 'https://madeforu.co.in/';
$WHATSAPP  = 'https://wa.me/919381024794';
$UPI_ID    = 'shravanichenna@ybl';   // TODO: replace with the real UPI ID
$UPI_NAME  = 'MadeForU Gifts';

$query    = trim($_GET['q'] ?? '');
$orders   = [];      // list of orders (1 for an ID, all for a phone)
$itemsMap = [];      // order_id => line items
$error    = null;
$searched = ($query !== '');

if ($searched) {
    // Match by order_no OR by phone (normalised to 10 digits).
    $phone = normalise_phone($query);

    if (strlen($phone) === 10) {
        // Phone lookup: return ALL orders for that number, newest first.
        $s = $conn->prepare(
            'SELECT * FROM orders WHERE phone = ? ORDER BY created_at DESC'
        );
        $s->bind_param('s', $phone);
    } else {
        $s = $conn->prepare('SELECT * FROM orders WHERE order_no = ? LIMIT 1');
        $s->bind_param('s', $query);
    }
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $orders[] = $row;
    $s->close();

    if ($orders) {
        $s = $conn->prepare('SELECT item, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id');
        foreach ($orders as $o) {
            $s->bind_param('i', $o['id']);
            $s->execute();
            $r = $s->get_result();
            $rows = [];
            while ($row = $r->fetch_assoc()) $rows[] = $row;
            $itemsMap[$o['id']] = $rows;
        }
        $s->close();
    } else {
        $error = 'No order found. Double-check your Order ID or the mobile number used at booking.';
    }
}

/** Build a UPI intent link so the amount is pre-filled in the UPI app. */
function upi_link(string $vpa, string $name, float $amount, string $note): string {
    return 'upi://pay?pa=' . rawurlencode($vpa)
         . '&pn=' . rawurlencode($name)
         . '&am=' . number_format($amount, 2, '.', '')
         . '&cu=INR&tn=' . rawurlencode($note);
}

// Derive display status for a found order.
function order_stage(array $o): array {
    if ((int)$o['is_delivered'] === 1) return ['Delivered', 'done', 'Your order has been delivered. Thank you!'];
    // A tracking number means it is with the courier -- further along than
    // "ready", so check it first.
    if (is_dispatched($o)) return ['Shipped', 'shipped',
        'Your order is on its way with Delhivery. Use the tracking number below for live updates.'];
    if ((int)$o['is_ready'] === 1)     return ['Ready', 'ready', 'Your order is ready for collection.'];
    return ['In progress', 'progress', 'We are working on your order. We\'ll notify you once it\'s ready.'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Track your order · MadeForU Gifts</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg:#f6f5fa; --card:#ffffff; --accent:#e11d74; --accent2:#7c3aed;
    --text:#1e1b2e; --muted:#6b6480; --line:#e7e3f0; --good:#16a34a;
    --warn:#d97706; --info:#0284c7; --input:#faf9fd;
  }
  body {
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    background:var(--bg);
    color:var(--text); min-height:100vh; line-height:1.5;
    display:flex; flex-direction:column; align-items:center; padding:24px 16px 48px;
  }
  .wrap { width:100%; max-width:520px; }
  .brand { text-align:center; margin:20px 0 28px; }
  .brand h1 { font-size:1.9rem; letter-spacing:.5px;
    background:linear-gradient(90deg,var(--accent),var(--accent2));
    -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
  .brand p { color:var(--muted); font-size:.95rem; margin-top:4px; }

  .card { background:var(--card); border:1px solid var(--line); border-radius:18px;
    padding:22px; margin-bottom:18px; box-shadow:0 4px 20px rgba(30,27,46,.06); }

  label { display:block; font-size:.85rem; color:var(--muted); margin-bottom:8px; }
  .searchrow { display:flex; gap:10px; }
  input[type=text] { flex:1; background:var(--input); border:1px solid var(--line);
    color:var(--text); padding:14px 16px; border-radius:12px; font-size:1rem; outline:none; }
  input[type=text]:focus { border-color:var(--accent); }
  button { background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#fff;
    border:0; padding:0 22px; border-radius:12px; font-size:1rem; font-weight:600; cursor:pointer; }
  .hint { font-size:.78rem; color:var(--muted); margin-top:10px; }

  .err { background:rgba(225,29,116,.12); border:1px solid rgba(225,29,116,.4);
    color:#d13073; padding:14px 16px; border-radius:12px; font-size:.92rem; }

  .badge { display:inline-block; padding:5px 12px; border-radius:999px; font-size:.8rem; font-weight:600; }
  .b-done { background:rgba(34,197,94,.15); color:var(--good); }
  .b-ready { background:rgba(56,189,248,.15); color:var(--info); }
  .b-progress { background:rgba(245,158,11,.15); color:var(--warn); }
  .b-shipped { background:rgba(225,29,72,.12); color:#be123c; }
  .ship { margin-top:16px; border:1px solid var(--line); border-radius:12px;
          padding:14px; background:var(--input); }
  .ship h4 { font-size:.9rem; margin-bottom:10px; color:#be123c; }
  .ship-row { display:flex; justify-content:space-between; gap:12px; font-size:.9rem; padding:4px 0; }
  .ship-lbl { color:var(--muted); }
  .ship-val { font-weight:600; text-align:right; }
  .ship-val.awb { font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
                  letter-spacing:.5px; word-break:break-all; }
  .ship-btn { display:block; margin-top:12px; text-align:center; text-decoration:none;
              background:linear-gradient(90deg,var(--accent),var(--accent2));
              color:#fff; font-weight:600; font-size:.9rem; padding:11px 14px; border-radius:10px; }
  .ship-btn:hover { opacity:.9; }

  .ohead { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
  .ono { font-size:1.25rem; font-weight:700; }
  .oname { color:var(--muted); font-size:.9rem; margin-bottom:16px; }
  .statusmsg { font-size:.9rem; color:var(--text); margin-bottom:18px; }

  .steps { display:flex; gap:6px; margin-bottom:20px; }
  .step { flex:1; text-align:center; }
  .dot { height:8px; border-radius:999px; background:var(--line); }
  .step.on .dot { background:linear-gradient(90deg,var(--accent),var(--accent2)); }
  .step small { display:block; margin-top:6px; font-size:.7rem; color:var(--muted); }
  .step.on small { color:var(--text); }

  table.items { width:100%; border-collapse:collapse; margin-bottom:14px; }
  table.items td { padding:8px 0; border-bottom:1px solid var(--line); font-size:.92rem; }
  table.items td:last-child { text-align:right; color:var(--muted); }
  .totals { font-size:.92rem; }
  .totals div { display:flex; justify-content:space-between; padding:4px 0; }
  .totals .grand { font-weight:700; font-size:1.05rem; border-top:1px solid var(--line); padding-top:10px; margin-top:4px; }
  .paid { color:var(--good); } .due { color:var(--warn); }

  .upi { margin-top:16px; border-top:1px solid var(--line); padding-top:16px; }
  .upi h4 { font-size:.9rem; margin-bottom:12px; color:var(--warn); }
  .upi-row { display:flex; gap:14px; align-items:center; }
  .upi-qr { width:110px; height:110px; flex:none; border:1px solid var(--line);
    border-radius:10px; background:#fff; padding:6px; }
  .upi-qr img { width:100%; height:100%; display:block; }
  .upi-info { font-size:.9rem; }
  .upi-info .vpa { font-weight:700; font-size:1rem; letter-spacing:.3px;
    word-break:break-all; margin:2px 0 6px; }
  .upi-info .amt { color:var(--warn); font-weight:600; }
  .upi-pay { display:inline-block; margin-top:8px; background:linear-gradient(90deg,var(--accent),var(--accent2));
    color:#fff; text-decoration:none; padding:9px 16px; border-radius:10px; font-size:.85rem; font-weight:600; }
  .copy { cursor:pointer; font-size:.75rem; color:var(--accent2); background:none; padding:0; margin-left:6px; }

  .ordercount { text-align:center; color:var(--muted); font-size:.85rem; margin:-4px 0 14px; }
  .promo { text-align:center; }
  .promo h2 { font-size:1.15rem; margin-bottom:6px; }
  .promo p { color:var(--muted); font-size:.9rem; margin-bottom:16px; }
  .links { display:flex; flex-direction:column; gap:10px; }
  .lnk { display:flex; align-items:center; gap:12px; text-decoration:none; color:var(--text);
    background:var(--input); border:1px solid var(--line); border-radius:12px; padding:14px 16px;
    font-weight:600; transition:border-color .15s; }
  .lnk:hover { border-color:var(--accent); }
  .lnk .ico { width:22px; height:22px; flex:none; }
  .lnk .sub { display:block; font-weight:400; font-size:.78rem; color:var(--muted); margin-top:2px; }
  .foot { text-align:center; color:var(--muted); font-size:.78rem; margin-top:22px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <h1>MadeForU Gifts</h1>
    <p>Track your order — magnets, bottles, cups, keychains & more</p>
  </div>

  <form class="card" method="get">
    <label for="q">Order ID or mobile number</label>
    <div class="searchrow">
      <input type="text" id="q" name="q" value="<?= e($query) ?>"
             placeholder="ORD250713001 or 9381024794" autocomplete="off" required>
      <button type="submit">Track</button>
    </div>
    <p class="hint">Enter the Order ID from your receipt, or the mobile number you booked with.</p>
  </form>

  <?php if ($error): ?>
    <div class="card"><div class="err"><?= e($error) ?></div></div>
  <?php endif; ?>

  <?php if (count($orders) > 1): ?>
    <div class="ordercount"><?= count($orders) ?> orders found for this number</div>
  <?php endif; ?>

  <?php foreach ($orders as $order):
    [$stage, $cls, $msg] = order_stage($order);
    $total = (float)$order['total'];
    $paid  = (float)$order['paid_amount'];
    $due   = max($total - $paid, 0);
    $onReady = ((int)$order['is_ready'] === 1 || (int)$order['is_delivered'] === 1);
    $onDone  = ((int)$order['is_delivered'] === 1);
    $items   = $itemsMap[$order['id']] ?? [];
    // Only couriered orders get a Shipped step. Orders collected in person go
    // straight from Ready to Delivered.
    $shipped   = is_dispatched($order);
    $onShipped = ($shipped || $onDone);
    $dhlUrl    = delhivery_link($order['awb'] ?? null);
  ?>
    <div class="card">
      <div class="ohead">
        <span class="ono"><?= e($order['order_no']) ?></span>
        <span class="badge b-<?= $cls ?>"><?= e($stage) ?></span>
      </div>
      <div class="oname"><?= e($order['name']) ?></div>

      <div class="steps">
        <div class="step on"><div class="dot"></div><small>Booked</small></div>
        <div class="step <?= $onReady ? 'on' : '' ?>"><div class="dot"></div><small>Ready</small></div>
        <?php if ($shipped): ?>
          <div class="step <?= $onShipped ? 'on' : '' ?>"><div class="dot"></div><small>Shipped</small></div>
        <?php endif; ?>
        <div class="step <?= $onDone ? 'on' : '' ?>"><div class="dot"></div><small>Delivered</small></div>
      </div>

      <div class="statusmsg"><?= e($msg) ?></div>

      <?php if ($shipped): ?>
      <div class="ship">
        <h4>Shipment details</h4>
        <div class="ship-row"><span class="ship-lbl">Courier</span><span class="ship-val">Delhivery</span></div>
        <div class="ship-row"><span class="ship-lbl">Tracking no.</span><span class="ship-val awb"><?= e($order['awb']) ?></span></div>
        <?php if (!empty($order['dispatch_date'])): ?>
        <div class="ship-row"><span class="ship-lbl">Dispatched</span><span class="ship-val"><?= e(date('d M Y', strtotime($order['dispatch_date']))) ?></span></div>
        <?php endif; ?>
        <?php if ($dhlUrl): ?>
          <a class="ship-btn" href="<?= e($dhlUrl) ?>" target="_blank" rel="noopener">Track on Delhivery ↗</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($items): ?>
      <table class="items">
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['item']) ?> × <?= (int)$it['quantity'] ?></td>
            <td><?= money($it['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>

      <div class="totals">
        <?php if ((float)$order['discount'] > 0.001 || (float)$order['extra_charge'] > 0.001): ?>
          <div><span>Subtotal</span><span><?= money($order['subtotal']) ?></span></div>
          <?php if ((float)$order['extra_charge'] > 0.001): ?>
            <div>
              <span><?= $order['extra_charge_reason'] ? e($order['extra_charge_reason']) : 'Additional charges' ?></span>
              <span>+<?= money($order['extra_charge']) ?></span>
            </div>
          <?php endif; ?>
          <?php if ((float)$order['discount'] > 0.001): ?>
            <div><span>Discount</span><span>−<?= money($order['discount']) ?></span></div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="grand"><span>Total</span><span><?= money($total) ?></span></div>
        <?php if ($due > 0.001): ?>
          <div><span>Paid</span><span class="paid"><?= money($paid) ?></span></div>
          <div><span>Balance due</span><span class="due"><?= money($due) ?></span></div>
        <?php else: ?>
          <div><span>Payment</span><span class="paid">Fully paid ✓</span></div>
        <?php endif; ?>
      </div>

      <?php if ($due > 0.001):
        $note   = 'Order ' . $order['order_no'];
        $upiUrl = upi_link($UPI_ID, $UPI_NAME, $due, $note);
        // QR encodes the same UPI intent so any UPI app can scan & pay.
        $qrSrc  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='
                . rawurlencode($upiUrl);
      ?>
      <div class="upi">
        <h4>Pay balance via UPI</h4>
        <div class="upi-row">
          <div class="upi-qr"><img src="<?= e($qrSrc) ?>" alt="UPI QR code" loading="lazy"></div>
          <div class="upi-info">
            UPI ID
            <div class="vpa">
              <?= e($UPI_ID) ?>
              <button type="button" class="copy" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= e($UPI_ID) ?>').then(()=>{this.textContent='Copied';})">Copy</button>
            </div>
            Amount <span class="amt"><?= money($due) ?></span>
            <div><a class="upi-pay" href="<?= e($upiUrl) ?>">Pay in UPI app</a></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <div class="card promo">
    <h2>Stay connected with us</h2>
    <p>Custom orders, bulk gifting & event stalls — we've got you covered.</p>
    <div class="links">
      <a class="lnk" href="<?= e($WHATSAPP) ?>" target="_blank" rel="noopener">
        <svg class="ico" viewBox="0 0 24 24" fill="#25D366"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 1 1 12 20zm4.4-6c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.8 1-.3.2-.5.1a6.6 6.6 0 0 1-3.3-2.9c-.2-.4.2-.4.6-1.2.1-.1 0-.3 0-.4l-.7-1.7c-.2-.5-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3A2.8 2.8 0 0 0 6.7 10c0 1.6 1.2 3.2 1.4 3.4s2.3 3.6 5.6 5c2 .8 2.8.9 3.8.7.6-.1 1.4-.6 1.6-1.1s.2-1 .1-1.1l-.4-.2z"/></svg>
        <span>Chat on WhatsApp<span class="sub">Custom · bulk · event orders — +91 93810 24794</span></span>
      </a>
      <a class="lnk" href="<?= e($INSTAGRAM) ?>" target="_blank" rel="noopener">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="#e11d74" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="#e11d74" stroke="none"/></svg>
        <span>Follow us on Instagram<span class="sub">@madeforu_gifts</span></span>
      </a>
      <a class="lnk" href="<?= e($WEBSITE) ?>" target="_blank" rel="noopener">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
        <span>Visit our website<span class="sub">madeforu.co.in</span></span>
      </a>
    </div>
  </div>

  <div class="foot">© <?= date('Y') ?> MadeForU Gifts</div>
</div>
</body>
</html>