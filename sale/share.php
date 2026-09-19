<?php
require 'config.php';
$me = require_login();

// Active products that have a website URL set. Products without a URL can't be
// shared, so we surface them separately as a nudge to fill the URL in.
$withUrl = $conn->query(
    "SELECT id, name, product_url
     FROM products
     WHERE is_active = 1 AND product_url <> ''
     ORDER BY sort_order, name"
)->fetch_all(MYSQLI_ASSOC);

$missing = $conn->query(
    "SELECT name
     FROM products
     WHERE is_active = 1 AND product_url = ''
     ORDER BY sort_order, name"
)->fetch_all(MYSQLI_ASSOC);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Share links · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  input{padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .search{width:100%;margin-bottom:16px;font-size:15px}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .lowbar{background:#fff4e0;border:1px solid #f0d9a8;color:#8a5a00;padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:13px;line-height:1.6}
  .plist{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
  .pcard{border:1px solid #dfe1e5;border-radius:10px;padding:14px;background:#fff;display:flex;flex-direction:column;gap:10px}
  .pcard .pname{font-size:15px;font-weight:600}
  .pcard .purl{font-size:12px;color:#65676b;word-break:break-all;line-height:1.4}
  .pcard .btns{display:flex;gap:8px;margin-top:auto}
  .btn{padding:9px 14px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;
       cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-flex;
       align-items:center;justify-content:center;gap:6px;flex:1}
  .btn:hover{background:#f0f2f5}
  .btn.wa{background:#25d366;border-color:#25d366;color:#fff}.btn.wa:hover{background:#1fb855}
  .btn.copy.done{background:#e3f5eb;border-color:#b8e3ca;color:#1a7f4b}
  .btn .ico{width:16px;height:16px}
  .empty{color:#65676b;font-size:14px;padding:20px 0;text-align:center}
  .hide{display:none !important}
</style>
</head>
<body>
<?php
  $PAGE  = 'share';
  $TITLE = 'Share links';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Send a product on WhatsApp</h2>
    <p class="desc">Search for a product, then tap <strong>Copy</strong> to grab a
       ready message with the product name and link, or <strong>WhatsApp</strong> to open
       a chat with it prefilled. URLs come from the
       <a href="products.php">Products</a> page.</p>

    <?php if ($missing): ?>
      <div class="lowbar">
        <strong><?= count($missing) ?> active product<?= count($missing)===1?'':'s' ?> have no website URL yet</strong>
        and can't be shared:
        <?= e(implode(', ', array_column($missing, 'name'))) ?>.
        Add their links on the <a href="products.php">Products</a> page.
      </div>
    <?php endif; ?>

    <?php if (!$withUrl): ?>
      <p class="empty">No products have a website URL set yet. Add links on the
         <a href="products.php">Products</a> page to start sharing.</p>
    <?php else: ?>
      <input type="text" id="search" class="search" placeholder="Search products…" autocomplete="off">
      <div class="plist" id="plist">
      <?php foreach ($withUrl as $p):
        // The exact message customers receive. Kept in one place so Copy and the
        // WhatsApp button always send identical text. Name + link only, no price.
        $msg = $p['name'] . "\n👉 " . $p['product_url'];
        $waHref = 'https://wa.me/?text=' . rawurlencode($msg);
      ?>
        <div class="pcard" data-name="<?= e(mb_strtolower($p['name'])) ?>">
          <div class="pname"><?= e($p['name']) ?></div>
          <div class="purl"><?= e($p['product_url']) ?></div>
          <div class="btns">
            <button class="btn copy" type="button"
                    data-msg="<?= e($msg) ?>">
              <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Copy
            </button>
            <a class="btn wa" href="<?= e($waHref) ?>" target="_blank" rel="noopener">
              <svg class="ico" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.8 4.9-1.3A10 10 0 1 0 12 2zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.9.8.8-2.8-.2-.3A8 8 0 1 1 12 20zm4.6-6c-.3-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.2-.4.2-.4.6-1.2.1-.1 0-.3 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3A3 3 0 0 0 6.5 9c0 1.7 1.3 3.4 1.4 3.6a11 11 0 0 0 4.5 3.9c1.7.6 1.7.4 2 .4a2.6 2.6 0 0 0 1.7-1.2 2.1 2.1 0 0 0 .2-1.2c-.1-.1-.3-.2-.6-.3z"/></svg>
              WhatsApp
            </a>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <p class="empty hide" id="noResults">No products match that search.</p>
    <?php endif; ?>
  </div>

<script>
// Live filter by product name.
var search = document.getElementById('search');
if (search) {
  var cards = Array.prototype.slice.call(document.querySelectorAll('#plist .pcard'));
  var noResults = document.getElementById('noResults');
  search.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    var shown = 0;
    cards.forEach(function (c) {
      var match = c.getAttribute('data-name').indexOf(q) !== -1;
      c.classList.toggle('hide', !match);
      if (match) shown++;
    });
    noResults.classList.toggle('hide', shown !== 0);
  });
}

// Copy the ready message to the clipboard, with a fallback for older browsers.
document.querySelectorAll('.btn.copy').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var msg = btn.getAttribute('data-msg');
    var done = function () {
      var label = btn.lastChild;
      btn.classList.add('done');
      btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Copied!';
      setTimeout(function () {
        btn.classList.remove('done');
        btn.childNodes[btn.childNodes.length - 1].nodeValue = ' Copy';
      }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(msg).then(done, function () { fallback(msg, done); });
    } else {
      fallback(msg, done);
    }
  });
});
function fallback(text, cb) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); cb(); } catch (e) {}
  document.body.removeChild(ta);
}
</script>

<?php require 'layout_end.php'; ?>

</body>
</html>