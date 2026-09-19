<?php
require 'config.php';
$me = require_login();

/**
 * Append a row to the price history.
 *
 * Never fails the price change itself: the history is a record of what
 * happened, and refusing a legitimate price rise because a log table is
 * missing would be the tail wagging the dog. The table arrives with
 * api/migrations/2026-09-price-history.sql.
 */
function record_price_change(mysqli $conn, int $id, string $name, float $price, ?int $by): void {
    try {
        $cost = 0.0;
        $c = $conn->prepare('SELECT unit_cost FROM product_costs WHERE item = ?');
        $c->bind_param('s', $name);
        $c->execute();
        $cost = (float)($c->get_result()->fetch_assoc()['unit_cost'] ?? 0);
        $c->close();

        $s = $conn->prepare(
            'INSERT INTO product_price_history (product_id, item, price, unit_cost, changed_by)
             VALUES (?,?,?,?,?)'
        );
        $s->bind_param('isddi', $id, $name, $price, $cost, $by);
        $s->execute();
        $s->close();
    } catch (Throwable $e) { /* table arrives with the migration */ }
}

/** How many orders already contain this item — i.e. how many are protected. */
function past_sales_count(mysqli $conn, string $name): int {
    $s = $conn->prepare('SELECT COUNT(DISTINCT order_id) n FROM order_items WHERE item = ?');
    $s->bind_param('s', $name);
    $s->execute();
    $n = (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $inTx = false;
    try {
        if ($action === 'add') {
            $name  = trim($_POST['name'] ?? '');
            $price = round((float)($_POST['price'] ?? 0), 2);
            if ($name === '')  throw new Exception('Product name is required.');
            if ($price < 0)    throw new Exception('Price cannot be negative.');

            $chk = $conn->prepare('SELECT id FROM products WHERE name = ?');
            $chk->bind_param('s', $name);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('A product with that name already exists.');
            $chk->close();

            $ord = (int)$conn->query('SELECT COALESCE(MAX(sort_order),0)+1 n FROM products')->fetch_assoc()['n'];
            $s = $conn->prepare('INSERT INTO products (name, price, sort_order) VALUES (?,?,?)');
            $s->bind_param('sdi', $name, $price, $ord);
            $s->execute(); $s->close();
            flash("Added \"$name\" at " . money($price) . ".");

        } elseif ($action === 'update_prices') {
            // Bulk-save price for every row at once. Stock now lives on the
            // dedicated Stock page (FIFO), so it is no longer edited here.
            //
            // Only rows that actually moved are written, and each one is
            // recorded in product_price_history. The note this page shows
            // -- past orders keep the price they were sold at -- is true
            // because save.php keeps the agreed price on an edit; the
            // history is how you can later prove what changed and when.
            $prices = $_POST['price'] ?? [];
            if (!is_array($prices)) throw new Exception('Bad input.');

            $before = [];
            $r = $conn->query('SELECT id, name, price FROM products');
            while ($r && ($row = $r->fetch_assoc())) {
                $before[(int)$row['id']] = ['name' => $row['name'], 'price' => (float)$row['price']];
            }

            $s = $conn->prepare('UPDATE products SET price = ? WHERE id = ?');
            $changed = [];
            foreach ($prices as $id => $val) {
                $id = (int)$id;
                $p  = round((float)$val, 2);
                if ($id < 1 || $p < 0 || !isset($before[$id])) continue;
                if (abs($p - $before[$id]['price']) < 0.001) continue;   // nothing moved
                $s->bind_param('di', $p, $id);
                $s->execute();
                $changed[$id] = ['name' => $before[$id]['name'],
                                 'was' => $before[$id]['price'], 'now' => $p];
            }
            $s->close();

            foreach ($changed as $id => $c) {
                record_price_change($conn, $id, $c['name'], $c['now'], $me['id'] ?? null);
            }

            if (!$changed) {
                flash('No prices changed.');
            } elseif (count($changed) === 1) {
                $c = reset($changed);
                $past = past_sales_count($conn, $c['name']);
                flash($c['name'] . ' is now ' . money($c['now']) . ' (was ' . money($c['was'])
                    . '). This applies to new sales only'
                    . ($past > 0
                        ? '; the ' . $past . ' order' . ($past === 1 ? '' : 's')
                          . ' already sold keep the price they were sold at.'
                        : '.'));
            } else {
                $bits = [];
                foreach ($changed as $c) {
                    $bits[] = $c['name'] . ' ' . money($c['was']) . ' -> ' . money($c['now']);
                }
                flash(count($changed) . ' prices changed (' . implode(', ', $bits)
                    . '). These apply to new sales only — orders already sold keep '
                    . 'the price they were sold at.');
            }

        } elseif ($action === 'update_links') {
            // Save catalog image URL + product page URL for every row at once.
            // Used by the public menu.php page. Blank is allowed (clears it).
            $imgs = $_POST['image_url']   ?? [];
            $urls = $_POST['product_url'] ?? [];
            if (!is_array($imgs) || !is_array($urls)) throw new Exception('Bad input.');
            $s = $conn->prepare('UPDATE products SET image_url = ?, product_url = ? WHERE id = ?');
            foreach ($imgs as $id => $img) {
                $id  = (int)$id;
                if ($id < 1) continue;
                $img = trim((string)$img);
                $url = trim((string)($urls[$id] ?? ''));
                if (mb_strlen($img) > 500 || mb_strlen($url) > 500)
                    throw new Exception('A URL is too long (max 500 characters).');
                $s->bind_param('ssi', $img, $url, $id);
                $s->execute();
            }
            $s->close();
            flash('Catalog images & links saved.');

        } elseif ($action === 'reorder') {
            // Save new display order. 'order' is a comma-separated list of
            // product ids in the desired top-to-bottom sequence.
            $raw = trim($_POST['order'] ?? '');
            if ($raw === '') throw new Exception('No order received.');
            $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
            if (!$ids) throw new Exception('No valid product ids in order.');
            $s = $conn->prepare('UPDATE products SET sort_order = ? WHERE id = ?');
            $pos = 1;
            foreach ($ids as $id) {
                if ($id < 1) continue;
                $s->bind_param('ii', $pos, $id);
                $s->execute();
                $pos++;
            }
            $s->close();
            flash('Order saved.');

        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $row = $conn->query('SELECT is_active FROM products WHERE id = ' . $id)->fetch_assoc();
            if (!$row) throw new Exception('Product not found.');
            // Don't allow deactivating the last active product -- the order
            // form would have nothing to sell.
            if ((int)$row['is_active'] === 1) {
                $active = (int)$conn->query('SELECT COUNT(*) c FROM products WHERE is_active = 1')->fetch_assoc()['c'];
                if ($active <= 1) throw new Exception('At least one product must stay active.');
            }
            $s = $conn->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Product updated.');

        } elseif ($action === 'rename') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Name cannot be empty.');
            // A rename now carries the name across every table (products, costs,
            // stock, purchases and order history) so nothing is orphaned.
            $chk = $conn->prepare('SELECT id FROM products WHERE name = ? AND id <> ?');
            $chk->bind_param('si', $name, $id);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('Another product already has that name.');
            $chk->close();

            // Get the old name so we can carry its cost row over.
            $old = $conn->prepare('SELECT name FROM products WHERE id = ?');
            $old->bind_param('i', $id); $old->execute();
            $oldRow = $old->get_result()->fetch_assoc();
            $old->close();
            $oldName = $oldRow['name'] ?? null;

            $s = $conn->prepare('UPDATE products SET name = ? WHERE id = ?');
            $s->bind_param('si', $name, $id); $s->execute(); $s->close();

            // Costs join by NAME, so a rename orphans the old cost row and the
            // costs page then creates a fresh one -- the "duplicate". Carry the
            // cost across to the new name instead. Same for per-event costs.
            if ($oldName !== null && $oldName !== $name) {
                // Costs join by name, so a rename leaves the old cost row
                // orphaned. Consolidate onto the new name. If a row already
                // exists under the new name (e.g. you edited it after renaming),
                // that one is authoritative -- keep it and drop the old orphan.
                // Otherwise move the old row across.
                $has = $conn->prepare('SELECT 1 FROM product_costs WHERE item = ?');
                $has->bind_param('s', $name); $has->execute();
                $newExists = (bool)$has->get_result()->fetch_assoc();
                $has->close();

                if ($newExists) {
                    $rm = $conn->prepare('DELETE FROM product_costs WHERE item = ?');
                    $rm->bind_param('s', $oldName); $rm->execute(); $rm->close();
                } else {
                    $mv = $conn->prepare('UPDATE product_costs SET item = ? WHERE item = ?');
                    $mv->bind_param('ss', $name, $oldName); $mv->execute(); $mv->close();
                }

                // Per-event costs: same logic. Table may be absent on older installs.
                try {
                    $h2 = $conn->prepare('SELECT 1 FROM event_item_costs WHERE item = ? LIMIT 1');
                    $h2->bind_param('s', $name); $h2->execute();
                    $newExists2 = (bool)$h2->get_result()->fetch_assoc();
                    $h2->close();
                    if ($newExists2) {
                        $rm2 = $conn->prepare('DELETE FROM event_item_costs WHERE item = ?');
                        $rm2->bind_param('s', $oldName); $rm2->execute(); $rm2->close();
                    } else {
                        $mv2 = $conn->prepare('UPDATE event_item_costs SET item = ? WHERE item = ?');
                        $mv2->bind_param('ss', $name, $oldName); $mv2->execute(); $mv2->close();
                    }
                } catch (mysqli_sql_exception $e) { /* table absent */ }

                // ── Stock module + order history: carry the name across so a
                //    rename never leaves an orphan behind (the dropdown-duplicate
                //    bug). purchases/stock_ledger/order_items have no unique key
                //    on item, so a plain rename is safe. product_stock is keyed
                //    by item, so sum onto the target if it already has a row.
                try {
                    $q = $conn->prepare('SELECT qty_on_hand FROM product_stock WHERE item = ?');
                    $q->bind_param('s', $oldName); $q->execute();
                    $srcRow = $q->get_result()->fetch_assoc(); $q->close();
                    if ($srcRow) {
                        $srcQty = (int)$srcRow['qty_on_hand'];
                        $up = $conn->prepare(
                            'INSERT INTO product_stock (item, qty_on_hand) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE qty_on_hand = product_stock.qty_on_hand + VALUES(qty_on_hand)'
                        );
                        $up->bind_param('si', $name, $srcQty); $up->execute(); $up->close();
                        $dl = $conn->prepare('DELETE FROM product_stock WHERE item = ?');
                        $dl->bind_param('s', $oldName); $dl->execute(); $dl->close();
                    }
                    foreach (['purchases', 'stock_ledger', 'order_items'] as $tbl) {
                        $mv = $conn->prepare("UPDATE {$tbl} SET item = ? WHERE item = ?");
                        $mv->bind_param('ss', $name, $oldName); $mv->execute(); $mv->close();
                    }
                } catch (mysqli_sql_exception $e) { /* stock tables absent on old installs */ }
            }
            flash('Product renamed.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('No product specified.');
            $g = $conn->prepare('SELECT name FROM products WHERE id = ?');
            $g->bind_param('i', $id); $g->execute();
            $row = $g->get_result()->fetch_assoc(); $g->close();
            if (!$row) throw new Exception('Product not found.');
            $name = $row['name'];

            // Delete the catalogue row and its stock-side data. Past order_items
            // are LEFT intact (historical invoices stay accurate); only the
            // product definition, its batches, stock and costs are removed.
            $conn->begin_transaction(); $inTx = true;

            $d = $conn->prepare('DELETE FROM purchases WHERE item = ?');
            $d->bind_param('s', $name); $d->execute(); $d->close();
            $d = $conn->prepare('DELETE FROM product_stock WHERE item = ?');
            $d->bind_param('s', $name); $d->execute(); $d->close();
            $d = $conn->prepare('DELETE FROM stock_ledger WHERE item = ?');
            $d->bind_param('s', $name); $d->execute(); $d->close();
            $d = $conn->prepare('DELETE FROM product_costs WHERE item = ?');
            $d->bind_param('s', $name); $d->execute(); $d->close();
            try {
                $d = $conn->prepare('DELETE FROM event_item_costs WHERE item = ?');
                $d->bind_param('s', $name); $d->execute(); $d->close();
            } catch (mysqli_sql_exception $e) { /* table absent on old installs */ }
            $d = $conn->prepare('DELETE FROM products WHERE id = ?');
            $d->bind_param('i', $id); $d->execute(); $d->close();

            $conn->commit(); $inTx = false;
            flash('Deleted "' . $name . '". Past order history was kept.');
        }
    } catch (Exception $ex) {
        if (!empty($inTx)) $conn->rollback();
        flash($ex->getMessage(), 'error');
    }
    header('Location: products.php');
    exit;
}

$products = $conn->query('SELECT * FROM products ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Products · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .wrap{max-width:900px;margin:0 auto}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .nav h1{font-size:21px;font-weight:600}
  .nav .who{font-size:13px;color:#65676b}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input{padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}.danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:10px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .rupee{position:relative}.rupee span{position:absolute;left:9px;top:8px;color:#8a8d91}
  .rupee input{padding-left:22px;width:120px;text-align:right}
  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:500}
  .b-on{background:#e3f5eb;color:#1a7f4b}.b-off{background:#f0f2f5;color:#65676b}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}.acts button{padding:5px 10px;font-size:13px}
  .dim{opacity:.55}
  .order-list{list-style:none;margin:0;padding:0;border:1px solid #dfe1e5;border-radius:8px;overflow:hidden}
  .order-item{display:flex;align-items:center;gap:10px;padding:11px 12px;background:#fff;border-bottom:1px solid #eceef0;cursor:grab;user-select:none;touch-action:none}
  .order-item:last-child{border-bottom:none}
  .order-item:active{cursor:grabbing}
  .order-item.dragging{opacity:.5;background:#eef4ff}
  .order-item .grip{color:#b0b4ba;font-size:16px;line-height:1;letter-spacing:-1px}
  .order-item .oi-name{flex:1;font-size:14px}
  details summary{cursor:pointer;font-size:12px;color:#1877f2;margin-top:6px}

</style>
</head>
<body>
<?php
  $PAGE  = 'products';
  $TITLE = 'Products & prices';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add a product</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <div><label for="name">Name</label><input id="name" name="name" required maxlength="60" style="width:100%"></div>
        <div class="rupee"><label for="price">Price</label><span style="top:31px">₹</span>
          <input id="price" name="price" type="number" step="0.01" min="0" value="0" required style="padding-left:22px;width:100%">
        </div>
        <div><button type="submit" class="primary">Add</button></div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Current products</h2>
    <p class="desc">Editing a price changes it for <strong>new</strong> orders only.
       Past orders keep the price they were sold at. Deactivating hides a product
       from the order form without deleting its history.
       Stock on hand is managed on the <a href="stock.php">Stock</a> page now.</p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_prices">
      <div class="scroll">
      <table>
        <thead><tr><th>Product</th><th>Price</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($products as $pr): ?>
          <tr class="<?= $pr['is_active'] ? '' : 'dim' ?>">
            <td><?= e($pr['name']) ?></td>
            <td>
              <div class="rupee"><span>₹</span>
                <input name="price[<?= (int)$pr['id'] ?>]" type="number" step="0.01" min="0"
                       value="<?= number_format($pr['price'], 2, '.', '') ?>">
              </div>
            </td>
            <td><span class="badge <?= $pr['is_active'] ? 'b-on' : 'b-off' ?>">
              <?= $pr['is_active'] ? 'Active' : 'Hidden' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button type="submit" class="primary" style="margin-top:16px">Save all changes</button>
    </form>
  </div>

  <div class="card">
    <h2>Catalog images &amp; links</h2>
    <p class="desc">These power the public menu at
      <a href="menu.php" target="_blank">menu.php</a> — the page you share with
      customers. Paste the image URL from WordPress (open the image in the media
      library and copy its address) and, optionally, the product page URL. Leave
      blank to show a placeholder.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_links">
      <div class="scroll">
      <table>
        <thead><tr><th>Product</th><th>Image URL</th><th>Product URL (optional)</th></tr></thead>
        <tbody>
        <?php foreach ($products as $pr): ?>
          <tr class="<?= $pr['is_active'] ? '' : 'dim' ?>">
            <td><?= e($pr['name']) ?></td>
            <td><input name="image_url[<?= (int)$pr['id'] ?>]" type="url" maxlength="500"
                       placeholder="https://madeforu.co.in/wp-content/…"
                       value="<?= e($pr['image_url'] ?? '') ?>" style="width:100%;min-width:200px"></td>
            <td><input name="product_url[<?= (int)$pr['id'] ?>]" type="url" maxlength="500"
                       placeholder="https://madeforu.co.in/product/…"
                       value="<?= e($pr['product_url'] ?? '') ?>" style="width:100%;min-width:200px"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button type="submit" class="primary" style="margin-top:16px">Save images &amp; links</button>
    </form>
  </div>

  <div class="card">
    <h2>Arrange order</h2>
    <p class="desc">Drag products to set the order they appear in — top of the
      list shows first on the order form. On a phone, press and hold a row, then
      drag. Click <strong>Save order</strong> when done.</p>
    <ul id="orderList" class="order-list">
      <?php foreach ($products as $pr): ?>
        <li class="order-item <?= $pr['is_active'] ? '' : 'dim' ?>" draggable="true" data-id="<?= (int)$pr['id'] ?>">
          <span class="grip" aria-hidden="true">⠿</span>
          <span class="oi-name"><?= e($pr['name']) ?></span>
          <?php if (!$pr['is_active']): ?><span class="badge b-off">Hidden</span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" id="orderForm" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reorder">
      <input type="hidden" name="order" id="orderInput">
      <button type="submit" class="primary">Save order</button>
    </form>
  </div>

  <script>
  (function(){
    var list = document.getElementById('orderList');
    if (!list) return;
    var dragEl = null;

    function rowFromPoint(y){
      var items = [].slice.call(list.querySelectorAll('.order-item:not(.dragging)'));
      return items.reduce(function(closest, child){
        var box = child.getBoundingClientRect();
        var offset = y - box.top - box.height/2;
        if (offset < 0 && offset > closest.offset) return {offset: offset, el: child};
        return closest;
      }, {offset: -Infinity, el: null}).el;
    }

    // Desktop drag-and-drop.
    list.addEventListener('dragstart', function(e){
      var li = e.target.closest('.order-item'); if(!li) return;
      dragEl = li; li.classList.add('dragging');
    });
    list.addEventListener('dragend', function(){
      if(dragEl){ dragEl.classList.remove('dragging'); dragEl = null; }
    });
    list.addEventListener('dragover', function(e){
      e.preventDefault();
      if(!dragEl) return;
      var after = rowFromPoint(e.clientY);
      if(after == null) list.appendChild(dragEl);
      else list.insertBefore(dragEl, after);
    });

    // Touch support for phones.
    var touchEl = null;
    list.addEventListener('touchstart', function(e){
      var li = e.target.closest('.order-item'); if(!li) return;
      touchEl = li; li.classList.add('dragging');
    }, {passive:true});
    list.addEventListener('touchmove', function(e){
      if(!touchEl) return;
      e.preventDefault();
      var y = e.touches[0].clientY;
      var after = rowFromPoint(y);
      if(after == null) list.appendChild(touchEl);
      else list.insertBefore(touchEl, after);
    }, {passive:false});
    list.addEventListener('touchend', function(){
      if(touchEl){ touchEl.classList.remove('dragging'); touchEl = null; }
    });

    // On submit, serialise the current order into the hidden field.
    document.getElementById('orderForm').addEventListener('submit', function(){
      var ids = [].slice.call(list.querySelectorAll('.order-item')).map(function(li){ return li.getAttribute('data-id'); });
      document.getElementById('orderInput').value = ids.join(',');
    });
  })();
  </script>

  <div class="card">
    <h2>Show / hide &amp; rename</h2>
    <p class="desc">Hiding removes a product from the order form but keeps its
       order history. Renaming updates the name everywhere — future and past
       orders, purchases and stock — so no duplicates are left behind.</p>
    <table>
      <thead><tr><th>Product</th><th>Rename</th><th>Visibility</th><th>Delete</th></tr></thead>
      <tbody>
      <?php foreach ($products as $pr): ?>
        <tr class="<?= $pr['is_active'] ? '' : 'dim' ?>">
          <td><?= e($pr['name']) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
              <input name="name" value="<?= e($pr['name']) ?>" maxlength="60" style="width:150px">
              <button type="submit">Save</button>
            </form>
          </td>
          <td>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
              <button class="<?= $pr['is_active'] ? 'danger' : '' ?>">
                <?= $pr['is_active'] ? 'Hide' : 'Show' ?>
              </button>
            </form>
          </td>
          <td>
            <form method="post"
                  onsubmit="return confirm('Delete &quot;<?= e($pr['name']) ?>&quot;? Its stock, batches and costs are removed. Past order history is kept. This cannot be undone.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
              <button class="danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php require 'layout_end.php'; ?>

</body>
</html>