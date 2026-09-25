<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Name is required.');

            $s = $conn->prepare('SELECT id FROM partners WHERE name = ?');
            $s->bind_param('s', $name);
            $s->execute();
            if ($s->get_result()->fetch_assoc()) throw new Exception('A partner with that name already exists.');
            $s->close();

            $s = $conn->prepare('INSERT INTO partners (name) VALUES (?)');
            $s->bind_param('s', $name);
            $s->execute();
            $s->close();
            flash("Partner $name added.");

        } elseif ($action === 'rename') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Name is required.');

            $s = $conn->prepare('SELECT id FROM partners WHERE name = ? AND id <> ?');
            $s->bind_param('si', $name, $id);
            $s->execute();
            if ($s->get_result()->fetch_assoc()) throw new Exception('Another partner already uses that name.');
            $s->close();

            $s = $conn->prepare('UPDATE partners SET name = ? WHERE id = ?');
            $s->bind_param('si', $name, $id);
            $s->execute();
            $s->close();
            flash('Partner renamed.');

        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('UPDATE partners SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            flash('Partner updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            // RESTRICT in the DB would throw a raw error. Check first and
            // give a clear message instead.
            $s = $conn->prepare(
                'SELECT (SELECT COUNT(*) FROM expenses WHERE paid_by = ?)
                      + (SELECT COUNT(*) FROM account_movements WHERE partner_id = ?) AS n'
            );
            $s->bind_param('ii', $id, $id);
            $s->execute();
            $n = (int)$s->get_result()->fetch_assoc()['n'];
            $s->close();
            if ($n > 0) throw new Exception('Cannot delete: this partner has ' . $n . ' record(s). Deactivate instead.');

            $s = $conn->prepare('DELETE FROM partners WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            flash('Partner deleted.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }

    header('Location: partners.php');
    exit;
}

// Correlated subqueries: safe under ONLY_FULL_GROUP_BY.
$partners = $conn->query(
    'SELECT p.*,
       (SELECT COUNT(*) FROM expenses WHERE paid_by = p.id)          AS exp_count,
       (SELECT COUNT(*) FROM account_movements WHERE partner_id = p.id) AS mov_count
     FROM partners p ORDER BY p.name'
)->fetch_all(MYSQLI_ASSOC);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Partners · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:14px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;
              cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;
     text-transform:uppercase;color:#65676b;letter-spacing:.4px}
  td{padding:11px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .scroll{overflow-x:auto}
  .badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:500}
  .b-on{background:#e3f5eb;color:#1a7f4b}
  .b-off{background:#f0f2f5;color:#65676b}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}
  .acts button{padding:5px 10px;font-size:13px}
  details{margin-top:10px}
  summary{cursor:pointer;font-size:13px;color:#1877f2}
</style>
</head>
<body>
<?php
  $PAGE  = 'partners';
  $TITLE = 'Partners';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add a partner</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <div>
          <label for="name">Name</label>
          <input id="name" name="name" required maxlength="120">
        </div>
      </div>
      <button type="submit" class="primary">Add partner</button>
    </form>
  </div>

  <div class="card">
    <h2>All partners (<?= count($partners) ?>)</h2>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Name</th><th>Status</th><th>Expenses</th><th>Movements</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($partners as $p): $recs = (int)$p['exp_count'] + (int)$p['mov_count']; ?>
          <tr>
            <td><?= e($p['name']) ?></td>
            <td><span class="badge <?= $p['is_active'] ? 'b-on' : 'b-off' ?>">
              <?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><?= (int)$p['exp_count'] ?></td>
            <td><?= (int)$p['mov_count'] ?></td>
            <td>
              <div class="acts">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button><?= $p['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <?php if ($recs === 0): ?>
                  <form method="post" onsubmit="return confirm('Delete <?= e($p['name']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="danger">Delete</button>
                  </form>
                <?php endif; ?>
              </div>

              <details>
                <summary>Rename</summary>
                <form method="post" style="margin-top:10px;max-width:280px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="rename">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input name="name" required maxlength="120" value="<?= e($p['name']) ?>" style="margin-bottom:8px">
                  <button class="primary">Save</button>
                </form>
              </details>
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
