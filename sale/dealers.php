<?php
require 'config.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name    = trim($_POST['name'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $insta   = trim($_POST['instagram'] ?? '');
            $notes   = trim($_POST['notes'] ?? '');
            if ($name === '') throw new Exception('Dealer name is required.');
            $chk = $conn->prepare('SELECT id FROM dealers WHERE name = ?');
            $chk->bind_param('s', $name); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('A dealer with that name already exists.');
            $chk->close();
            $s = $conn->prepare('INSERT INTO dealers (name, phone, address, instagram, notes) VALUES (?, ?, ?, ?, ?)');
            $s->bind_param('sssss', $name, $phone, $address, $insta, $notes); $s->execute(); $s->close();
            flash('Dealer added.');

        } elseif ($action === 'edit') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = trim($_POST['name'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $insta   = trim($_POST['instagram'] ?? '');
            $notes   = trim($_POST['notes'] ?? '');
            if ($name === '') throw new Exception('Dealer name is required.');
            $chk = $conn->prepare('SELECT id FROM dealers WHERE name = ? AND id <> ?');
            $chk->bind_param('si', $name, $id); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) throw new Exception('Another dealer already has that name.');
            $chk->close();
            $s = $conn->prepare('UPDATE dealers SET name = ?, phone = ?, address = ?, instagram = ?, notes = ? WHERE id = ?');
            $s->bind_param('sssssi', $name, $phone, $address, $insta, $notes, $id); $s->execute(); $s->close();
            flash('Dealer updated.');

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE dealers SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            flash('Dealer updated.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: dealers.php');
    exit;
}

// List with purchase counts.
$dealers = [];
$res = $conn->query(
    'SELECT d.*,
            (SELECT COUNT(*) FROM purchases p WHERE p.dealer_id = d.id) AS buy_count,
            (SELECT COALESCE(SUM(p.unit_cost * p.qty_bought),0) FROM purchases p WHERE p.dealer_id = d.id) AS spent
       FROM dealers d
      ORDER BY d.is_active DESC, d.name ASC'
);
while ($r = $res->fetch_assoc()) $dealers[] = $r;

$PAGE = 'dealers';
$TITLE = 'Stock dealers';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock dealers · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;line-height:1.5}
  input,select{font-family:inherit}
  input:focus,select:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
</style>
</head>
<body>
<?php require 'layout.php'; ?>
<?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
<style>
  .card{background:#fff;border:1px solid #e4e6eb;border-radius:12px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;margin-bottom:4px}
  .desc{font-size:13px;color:#65676b;margin-bottom:14px}
  .grid{display:grid;grid-template-columns:1.4fr 1fr 1.4fr auto;gap:12px;align-items:end}
  .grid.add-more{grid-template-columns:1fr 1fr;margin-top:12px}
  .grid.edit{grid-template-columns:1fr 1fr 1fr;align-items:end}
  label{display:block;font-size:12px;color:#65676b;margin-bottom:4px}
  input[type=text]{width:100%;padding:9px 11px;border:1px solid #ccd0d5;border-radius:8px;font-size:14px}
  button.primary{background:#1877f2;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}
  button.link{background:none;border:none;color:#1877f2;cursor:pointer;font-size:13px;padding:0}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #eef0f2}
  th{font-size:12px;color:#65676b;text-transform:uppercase;letter-spacing:.03em}
  tr.dim{opacity:.5}
  .badge{font-size:11px;padding:2px 8px;border-radius:999px}
  .b-on{background:#e6f4ea;color:#1a7f37}.b-off{background:#f0f0f0;color:#666}
  .edit-row{display:none;background:#f7f8fa}
  .edit-row.show{display:table-row}
  @media(max-width:700px){
    .grid,.grid.add-more,.grid.edit{grid-template-columns:1fr}
  }
</style>

<div class="card">
  <h2>Add a dealer</h2>
  <p class="desc">Suppliers you buy stock from. You'll pick one when recording a purchase. Address and Instagram are optional.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="grid">
      <div><label>Name</label><input type="text" name="name" required maxlength="120" placeholder="e.g. Print Valley"></div>
      <div><label>Phone (optional)</label><input type="text" name="phone" maxlength="20"></div>
      <div><label>Instagram (optional)</label><input type="text" name="instagram" maxlength="120" placeholder="@handle"></div>
      <div><button class="primary" type="submit">Add</button></div>
    </div>
    <div class="grid add-more">
      <div><label>Address (optional)</label><input type="text" name="address" maxlength="255"></div>
      <div><label>Notes (optional)</label><input type="text" name="notes" maxlength="255"></div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Dealers</h2>
  <div class="tscroll">
  <table>
    <thead><tr><th>Name</th><th>Phone</th><th>Instagram</th><th>Purchases</th><th>Total spent</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$dealers): ?>
      <tr><td colspan="7" style="color:#65676b">No dealers yet. Add one above.</td></tr>
    <?php endif; ?>
    <?php foreach ($dealers as $d): ?>
      <tr class="<?= $d['is_active'] ? '' : 'dim' ?>">
        <td><?= e($d['name']) ?></td>
        <td><?= e($d['phone'] ?: '—') ?></td>
        <td>
          <?php
            $ig = trim((string)($d['instagram'] ?? ''));
            if ($ig === '') { echo '—'; }
            else {
                $handle = ltrim($ig, '@');
                $url = 'https://instagram.com/' . rawurlencode($handle);
                echo '<a href="' . e($url) . '" target="_blank" rel="noopener">@' . e($handle) . '</a>';
            }
          ?>
        </td>
        <td><?= (int)$d['buy_count'] ?></td>
        <td><?= money($d['spent']) ?></td>
        <td><span class="badge <?= $d['is_active'] ? 'b-on' : 'b-off' ?>"><?= $d['is_active'] ? 'Active' : 'Hidden' ?></span></td>
        <td style="white-space:nowrap">
          <button class="link" type="button" onclick="document.getElementById('edit-<?= (int)$d['id'] ?>').classList.toggle('show')">Edit</button>
          &nbsp;·&nbsp;
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <button class="link" type="submit"><?= $d['is_active'] ? 'Hide' : 'Show' ?></button>
          </form>
        </td>
      </tr>
      <tr class="edit-row" id="edit-<?= (int)$d['id'] ?>">
        <td colspan="7">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <div class="grid edit">
              <div><label>Name</label><input type="text" name="name" required maxlength="120" value="<?= e($d['name']) ?>"></div>
              <div><label>Phone</label><input type="text" name="phone" maxlength="20" value="<?= e($d['phone']) ?>"></div>
              <div><label>Instagram</label><input type="text" name="instagram" maxlength="120" placeholder="@handle" value="<?= e($d['instagram'] ?? '') ?>"></div>
              <div><label>Address</label><input type="text" name="address" maxlength="255" value="<?= e($d['address'] ?? '') ?>"></div>
              <div><label>Notes</label><input type="text" name="notes" maxlength="255" value="<?= e($d['notes']) ?>"></div>
              <div><button class="primary" type="submit">Save</button></div>
            </div>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require 'layout_end.php'; ?>

</body>
</html>