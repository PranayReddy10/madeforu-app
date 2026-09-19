<?php
// menu.php — Public product catalog / menu. NO login required.
// Share the URL directly (sale.madeforu.co.in/menu.php). Each card shows the
// product image, name, price and a WhatsApp button that opens the customer's
// WhatsApp pre-filled with an enquiry to the business number.
require __DIR__ . '/config.php';

$BIZ_WA    = '919381024794';                           // business WhatsApp
$INSTAGRAM = 'https://www.instagram.com/madeforu_gifts/';
$WEBSITE   = 'https://madeforu.co.in/';

// Only active products, in the display order set on the Products page.
$products = $conn->query(
    'SELECT name, price, image_url, product_url
       FROM products
      WHERE is_active = 1
      ORDER BY sort_order, name'
)->fetch_all(MYSQLI_ASSOC);

function wa_enquiry(string $phone, string $product): string {
    $msg = "Hi, I'm interested in {$product}";
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MadeForU · Catalog</title>
<meta property="og:title" content="MadeForU — Handmade Personalised Gifts">
<meta property="og:description" content="Browse our custom magnets, cups, keychains & more. Tap to order on WhatsApp.">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
  .top{background:linear-gradient(135deg,#7b3fe4,#b14ae0);color:#fff;padding:26px 16px 30px;text-align:center}
  .top h1{font-size:24px;font-weight:700;letter-spacing:.3px}
  .top p{font-size:14px;opacity:.92;margin-top:5px}
  .top .links{margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .top .links a{color:#fff;text-decoration:none;font-size:13px;border:1px solid rgba(255,255,255,.55);
    padding:6px 14px;border-radius:20px}
  .wrap{max-width:900px;margin:0 auto;padding:18px 14px 40px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:14px}
  .card{background:#fff;border:1px solid #e4e6ea;border-radius:14px;overflow:hidden;display:flex;
    flex-direction:column;max-width:100%}
  .ph{aspect-ratio:1/1;background:#eef0f3;display:flex;align-items:center;justify-content:center;overflow:hidden}
  .ph img{width:100%;height:100%;object-fit:cover;display:block}
  .ph .noimg{color:#b0b4ba;font-size:32px}
  .body{padding:11px 12px 13px;display:flex;flex-direction:column;gap:8px;flex:1}
  .name{font-size:14px;font-weight:600;line-height:1.3}
  .price{font-size:16px;font-weight:700;color:#7b3fe4}
  .btns{margin-top:auto;display:flex;flex-direction:column;gap:7px}
  a.wa{background:#25d366;color:#fff;text-decoration:none;text-align:center;padding:9px;border-radius:8px;
    font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px}
  a.wa:active{background:#1eb457}
  a.view{border:1px solid #d5d8dd;color:#1c1e21;text-decoration:none;text-align:center;padding:8px;
    border-radius:8px;font-size:13px}
  .foot{text-align:center;color:#8a8d91;font-size:12px;padding:24px 16px 10px}
  .empty{text-align:center;color:#65676b;padding:50px 16px}
</style>
</head>
<body>
  <div class="top">
    <h1>MadeForU</h1>
    <p>Handmade personalised gifts</p>
    <div class="links">
      <a href="<?= e($WEBSITE) ?>" target="_blank" rel="noopener">Website</a>
      <a href="<?= e($INSTAGRAM) ?>" target="_blank" rel="noopener">Instagram</a>
      <a href="https://wa.me/<?= e($BIZ_WA) ?>" target="_blank" rel="noopener">WhatsApp</a>
    </div>
  </div>

  <div class="wrap">
    <?php if (!$products): ?>
      <div class="empty">Our catalog is being updated. Please check back soon.</div>
    <?php else: ?>
    <div class="grid">
      <?php foreach ($products as $p): ?>
        <?php
          $name = $p['name'];
          $img  = trim($p['image_url'] ?? '');
          $url  = trim($p['product_url'] ?? '');
        ?>
        <div class="card">
          <div class="ph">
            <?php if ($img !== ''): ?>
              <img src="<?= e($img) ?>" alt="<?= e($name) ?>" loading="lazy">
            <?php else: ?>
              <span class="noimg">🎁</span>
            <?php endif; ?>
          </div>
          <div class="body">
            <div class="name"><?= e($name) ?></div>
            <div class="price"><?= money($p['price']) ?></div>
            <div class="btns">
              <a class="wa" href="<?= e(wa_enquiry($BIZ_WA, $name)) ?>" target="_blank" rel="noopener">
                <span>💬</span> Order on WhatsApp
              </a>
              <?php if ($url !== ''): ?>
                <a class="view" href="<?= e($url) ?>" target="_blank" rel="noopener">View details</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="foot">MadeForU · Tap any product to order on WhatsApp</div>
</body>
</html>
