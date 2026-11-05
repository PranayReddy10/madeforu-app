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
    }
    header('Location: website_sync.php');
    exit;
}

$key = (string)website_setting($conn, 'website_sync_key');
$last = (string)website_setting($conn, 'website_sync_last');
$count = website_synced_count($conn);
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
        <li>On the website open <b>Admin → Sales</b> and press <b>Test connection</b>. To bring in orders delivered
          before today, press <b>Send delivered orders now</b> there.</li>
      </ol>
      <div class="row" style="margin-top:12px">
        <form method="post" onsubmit="return confirm('Create a new key? The website stops sending until you paste the new one there.')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="new_key"><button>New key</button></form>
        <form method="post" onsubmit="return confirm('Switch website sync off?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_key"><button class="danger">Switch off</button></form>
      </div>
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
