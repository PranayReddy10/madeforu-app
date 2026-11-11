<?php
/**
 * Website sync: delivered orders from madeforu.co.in land in this panel.
 *
 * Create the key here, paste it into the website (Admin → Settings → Sale
 * panel sync) together with this panel's address, and every order the
 * website marks Delivered is added under Viewing → Website orders.
 */
require 'config.php';
require_once __DIR__ . '/lib_website.php';
require_once __DIR__ . '/lib_push.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'new_key') {
        $had = (string)website_setting($conn, 'website_sync_key') !== '';
        website_setting_set($conn, 'website_sync_key', bin2hex(random_bytes(24)));
        flash($had ? 'New key created. The old one stops working — paste the new one into the website.' : 'Key created. Copy it into the website settings.');
    } elseif ($action === 'remove_key') {
        website_setting_set($conn, 'website_sync_key', '');
        flash('Website sync switched off. The website can no longer send orders here.');
    } elseif ($action === 'credit_partner') {
        $pid = (int)($_POST['partner_id'] ?? 0);
        $name = '';
        if ($pid > 0) {
            $s = $conn->prepare('SELECT name FROM partners WHERE id = ? AND is_active = 1');
            $s->bind_param('i', $pid);
            $s->execute();
            $name = (string)($s->get_result()->fetch_assoc()['name'] ?? '');
            $s->close();
            if ($name === '') { flash('Choose an active partner.', 'error'); header('Location: website_sync.php'); exit; }
        }
        website_setting_set($conn, 'website_credit_partner', $pid > 0 ? (string)$pid : '');
        flash($pid > 0 ? 'Website orders are now credited to ' . $name . ' as they are delivered.'
                       : 'Automatic credit switched off. Credit website orders from Events instead.');
    } elseif ($action === 'credit_backlog') {
        // Orders that arrived before automatic credit: one credit for what
        // the Website orders event has not been credited yet.
        $pid = website_credit_partner($conn);
        $eventId = website_event_id($conn);
        $gap = website_event_gap($conn, $eventId);
        if ($pid < 1) {
            flash('Choose the partner first.', 'error');
        } elseif ($gap <= 0.001) {
            flash('Nothing left to credit: every website order is already credited.', 'error');
        } else {
            $today = date('Y-m-d');
            $src = 'Event sales: ' . WEBSITE_EVENT_NAME;
            $note = 'Website orders delivered before automatic credit was switched on.';
            $s = $conn->prepare("INSERT INTO account_movements (mov_date, partner_id, event_id, direction, kind, amount, source, note)
                                 VALUES (?,?,?,'credit','normal',?,?,?)");
            $s->bind_param('siidss', $today, $pid, $eventId, $gap, $src, $note);
            $s->execute();
            $s->close();
            flash('Credited ' . money($gap) . ' of earlier website orders.');
        }
    }
    header('Location: website_sync.php');
    exit;
}

$key = (string)website_setting($conn, 'website_sync_key');
$last = (string)website_setting($conn, 'website_sync_last');
$count = website_synced_count($conn);
$creditPid = website_credit_partner($conn);
$partners = $conn->query('SELECT id, name FROM partners WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
// What the website orders came to and what is not credited yet. Only looked
// up once some exist, so an unused panel does not get the event created.
$backlog = 0.0;
if ($count > 0) $backlog = website_event_gap($conn, website_event_id($conn));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$panelUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'sale.madeforu.co.in') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Website sync · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card p.hint{font-size:13px;color:#65676b;margin-bottom:12px}
  .card ol{padding-left:20px;font-size:14px}
  .card ol li{margin-bottom:6px}
  .field{display:flex;gap:8px;align-items:center;margin:8px 0 12px;flex-wrap:wrap}
  .field code{flex:1;min-width:0;overflow-wrap:anywhere;background:#f0f2f5;padding:9px 10px;border-radius:6px;font-size:13px}
  button{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit}
  button:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .row{display:flex;gap:8px;flex-wrap:wrap}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
  .stat{border:1px solid #dfe1e5;border-radius:8px;padding:10px 12px;font-size:14px;min-width:0;overflow-wrap:anywhere}
  .ok{color:#1a7f4b;font-weight:600} .no{color:#9a6300;font-weight:600}
</style>
</head>
<body>
<?php
  $PAGE  = 'website_sync';
  $TITLE = 'Website sync';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Orders from madeforu.co.in</h2>
    <p class="hint">Every order the website marks <b>Delivered</b> — paid online or cash on delivery — is added here
      automatically with its items, prices, charges, payment and Delhivery AWB. Find them under
      <b>Viewing → <?= e(WEBSITE_EVENT_NAME) ?></b>; they are kept out of “Offline only”.</p>
    <div class="stats">
      <div class="stat">Status<br><?= $key !== '' ? '<span class="ok">✓ Ready</span>' : '<span class="no">Not set up</span>' ?></div>
      <div class="stat">Website orders here<br><b><?= (int)$count ?></b></div>
      <div class="stat">Last received<br><?= $last !== '' ? e($last) : '<span class="no">Nothing yet</span>' ?></div>
    </div>
  </div>

  <div class="card">
    <h2>Connect the website</h2>
    <?php if ($key === ''): ?>
      <p class="hint">Create a key, then paste it into the website.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new_key">
        <button class="primary">Create key</button></form>
    <?php else: ?>
      <ol>
        <li>On the website open <b>Admin → Settings → Sale panel sync</b>.</li>
        <li>Paste this panel’s address:
          <div class="field"><code id="u"><?= e($panelUrl) ?></code><button type="button" onclick="copyIt('u', this)">Copy</button></div></li>
        <li>Paste the key:
          <div class="field"><code id="k"><?= e($key) ?></code><button type="button" onclick="copyIt('k', this)">Copy</button></div></li>
        <li>Tick <b>Send delivered orders to the sale panel</b> and save.</li>
        <li>On the website open <b>Admin → Sales</b> and press <b>Test connection</b>. Orders delivered before
          today are listed there: tick the ones you <b>already added here by hand</b> and press <b>Already in panel</b>,
          then send the rest. New deliveries come in automatically.</li>
      </ol>
      <div class="row" style="margin-top:12px">
        <form method="post" onsubmit="return confirm('Create a new key? The website stops sending until you paste the new one there.')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="new_key"><button>New key</button></form>
        <form method="post" onsubmit="return confirm('Switch website sync off?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_key"><button class="danger">Switch off</button></form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Credit to a partner automatically</h2>
    <p class="hint">Choose whose account website money goes into. Each order is credited to that partner the
      moment it arrives here as delivered — one line per order under <b>Account movements</b>, named
      “Website order …”. If the website sends the order again its credit follows the new total, and a cancelled
      or returned order takes its credit with it. Leave it off to credit website sales by hand from
      <b>Events → <?= e(WEBSITE_EVENT_NAME) ?></b>.</p>
    <form method="post" class="row" style="align-items:center"><?= csrf_field() ?>
      <input type="hidden" name="action" value="credit_partner">
      <select name="partner_id" style="padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit">
        <option value="0">Off — credit by hand</option>
        <?php foreach ($partners as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $creditPid ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="primary">Save</button>
    </form>
    <?php if ($creditPid > 0 && $backlog > 0.001): ?>
      <p class="hint" style="margin:14px 0 8px"><b><?= money($backlog) ?></b> of website orders that came in earlier
        is not credited to anyone yet.</p>
      <form method="post" onsubmit="return confirm('Credit <?= e(money($backlog)) ?> of earlier website orders to the chosen partner?')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="credit_backlog"><button>Credit <?= money($backlog) ?> now</button></form>
    <?php elseif ($count > 0 && $backlog > 0.001): ?>
      <p class="hint" style="margin:14px 0 0"><?= money($backlog) ?> of website orders is not credited to anyone yet.</p>
    <?php endif; ?>
  </div>

<script>
function copyIt(id, btn) {
  var t = document.getElementById(id).textContent.trim();
  var done = function () { var o = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = o; }, 1500); };
  if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, done); else done();
}
</script>
<?php require 'layout_end.php'; ?>
