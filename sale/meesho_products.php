<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $cost = round((float)($_POST['unit_cost'] ?? 0), 2);
            if ($name === '') throw new Exception('Product name is required.');
            if ($cost < 0)    throw new Exception('Cost cannot be negative.');

            $chk = $conn->prepare('SELECT id FROM meesho_products WHERE name = ?');
            $chk->bind_param('s', $name);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('A product with that name already exists.');
            $chk->close();

            $ord = (int)$conn->query('SELECT COALESCE(MAX(sort_order),0)+10 n FROM meesho_products')->fetch_assoc()['n'];
            $s = $conn->prepare('INSERT INTO meesho_products (name, unit_cost, sort_order) VALUES (?,?,?)');
            $s->bind_param('sdi', $name, $cost, $ord);
            $s->execute(); $s->close();
            flash("Added \"$name\" at cost " . money($cost) . ".");

        } elseif ($action === 'update_costs') {
            $costs = $_POST['unit_cost'] ?? [];
            $skus  = $_POST['sku'] ?? [];
            $sizes = $_POST['set_size'] ?? [];
            if (!is_array($costs)) throw new Exception('Bad input.');
            $s = $conn->prepare(
                'UPDATE meesho_products SET unit_cost = ?, sku = ?, set_size = ? WHERE id = ?'
            );
            // Two products sharing a SKU would make the importer ambiguous,
            // so catch it here rather than surfacing a raw MySQL #1062.
            $seenSku = [];
            foreach ($skus as $sid => $sval) {
                $sv = strtoupper(trim((string)$sval));
                if ($sv === '') continue;
                if (isset($seenSku[$sv])) {
                    throw new Exception('The SKU "' . $sv . '" is used by more than one product. '
                        . 'Each product needs its own SKU, or the importer cannot tell them apart.');
                }
                $seenSku[$sv] = (int)$sid;
            }

            foreach ($costs as $id => $val) {
                $id = (int)$id;
                $c  = round((float)$val, 2);
                if ($id < 1 || $c < 0) continue;
                $sku  = strtoupper(trim((string)($skus[$id] ?? '')));
                $skuV = ($sku !== '') ? $sku : null;
                $size = (int)($sizes[$id] ?? 1);
                if ($size < 1) $size = 1;
                $s->bind_param('dsii', $c, $skuV, $size, $id);
                if (!$s->execute()) {
                    if ($conn->errno === 1062) {
                        throw new Exception('That SKU is already assigned to another product.');
                    }
                    throw new Exception('Could not save: ' . $conn->error);
                }
            }
            $s->close();
            flash('Products updated.');

        } elseif ($action === 'toggle_active') {
            $id  = (int)($_POST['id'] ?? 0);
            $row = $conn->query('SELECT is_active FROM meesho_products WHERE id = ' . $id)->fetch_assoc();
            if (!$row) throw new Exception('Product not found.');
            $s = $conn->prepare('UPDATE meesho_products SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Product updated.');

        } elseif ($action === 'rename') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Name cannot be empty.');
            $chk = $conn->prepare('SELECT id FROM meesho_products WHERE name = ? AND id <> ?');
            $chk->bind_param('si', $name, $id);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('Another product already has that name.');
            $chk->close();
            // Past orders keep product_name as a snapshot, so a rename here
            // only affects future picks. We leave existing meesho_orders alone.
            $s = $conn->prepare('UPDATE meesho_products SET name = ? WHERE id = ?');
            $s->bind_param('si', $name, $id); $s->execute(); $s->close();
            flash('Product renamed.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: meesho_products.php');
    exit;
}

$products = $conn->query('SELECT * FROM meesho_products ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meesho products · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
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
  .dim{opacity:.55}
</style>
</head>
<body>
<?php
  $PAGE  = 'meesho_products';
  $TITLE = 'Meesho products';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add a Meesho product</h2>
    <p class="desc">These are separate from your stall products. The cost is your
       manufacturing cost per unit, used to work out profit on each Meesho order.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <div><label for="name">Name</label><input id="name" name="name" required maxlength="80" placeholder="e.g. Square Magnet 3" style="width:100%"></div>
        <div class="rupee"><label for="unit_cost">Unit cost</label><span style="top:31px">₹</span>
          <input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" value="0" required style="padding-left:22px;width:100%">
        </div>
        <div><button type="submit" class="primary">Add</button></div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Costs &amp; SKU mapping</h2>
    <p class="desc">
      <strong>Unit cost</strong> is what it costs you to make one pack. Profit per order =
      settlement − (cost × quantity).<br>
      <strong>Supplier SKU</strong> is how the payment-file importer matches a panel row to
      this product. It must match the panel exactly — Meesho renames listings, but the SKU
      is stable, so it's the reliable key. Without it, imported orders won't link here.<br>
      <strong>Set size</strong> is how many magnets are in the pack (the number in "Set of 3").
    </p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_costs">
      <table>
        <thead><tr>
          <th>Product</th><th>Supplier SKU</th><th>Set size</th><th>Unit cost</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php if (!$products): ?>
          <tr><td colspan="5" style="color:#65676b">No products yet. Add one above, or run the schema to seed them.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $pr): ?>
          <tr class="<?= $pr['is_active'] ? '' : 'dim' ?>">
            <td><?= e($pr['name']) ?></td>
            <td>
              <input name="sku[<?= (int)$pr['id'] ?>]" value="<?= e($pr['sku'] ?? '') ?>"
                     placeholder="e.g. MFU-SQ-003" maxlength="40"
                     style="width:150px<?= empty($pr['sku']) ? ';border-color:#e6c065;background:#fff8e8' : '' ?>">
            </td>
            <td>
              <input name="set_size[<?= (int)$pr['id'] ?>]" type="number" min="1" max="99"
                     value="<?= (int)($pr['set_size'] ?? 1) ?>" style="width:70px">
            </td>
            <td>
              <div class="rupee"><span>₹</span>
                <input name="unit_cost[<?= (int)$pr['id'] ?>]" type="number" step="0.01" min="0"
                       value="<?= number_format($pr['unit_cost'], 2, '.', '') ?>">
              </div>
            </td>
            <td><span class="badge <?= $pr['is_active'] ? 'b-on' : 'b-off' ?>">
              <?= $pr['is_active'] ? 'Active' : 'Hidden' ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($products): ?>
        <button type="submit" class="primary" style="margin-top:16px">Save all</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>Show / hide &amp; rename</h2>
    <p class="desc">Hiding removes a product from the new-order dropdown but keeps
       its order history. Renaming affects future orders only.</p>
    <table>
      <thead><tr><th>Product</th><th>Rename</th><th>Visibility</th></tr></thead>
      <tbody>
      <?php foreach ($products as $pr): ?>
        <tr class="<?= $pr['is_active'] ? '' : 'dim' ?>">
          <td><?= e($pr['name']) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
              <input name="name" value="<?= e($pr['name']) ?>" maxlength="80" style="width:150px">
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
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php require 'layout_end.php'; ?>

</body>
</html>