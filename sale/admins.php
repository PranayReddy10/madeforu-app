<?php
require 'config.php';
require_once __DIR__ . '/lib_push.php';   // tells the other partners' phones
$me = require_login();

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        /** Number of admins who can still sign in. */
        $activeCount = function() use ($conn) {
            $r = $conn->query('SELECT COUNT(*) c FROM admins WHERE is_active = 1');
            return (int)$r->fetch_assoc()['c'];
        };

        if ($action === 'add') {
            $name  = trim($_POST['name'] ?? '');
            $phone = normalise_phone($_POST['phone'] ?? '');
            $p1    = (string)($_POST['password'] ?? '');

            if ($name === '')              throw new Exception('Name is required.');
            if (strlen($phone) !== 10)     throw new Exception('Phone must be 10 digits.');
            if (strlen($p1) < 8)           throw new Exception('Password must be at least 8 characters.');

            $s = $conn->prepare('SELECT id FROM admins WHERE phone = ?');
            $s->bind_param('s', $phone);
            $s->execute();
            if ($s->get_result()->fetch_assoc()) throw new Exception('That phone number is already registered.');
            $s->close();

            $hash = password_hash($p1, PASSWORD_DEFAULT);
            $s = $conn->prepare('INSERT INTO admins (name, phone, password_hash) VALUES (?,?,?)');
            $s->bind_param('sss', $name, $phone, $hash);
            $s->execute();
            $s->close();
            flash("Admin $name added.");

        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $me['id']) throw new Exception('You cannot deactivate your own account.');

            $s = $conn->prepare('SELECT is_active FROM admins WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row) throw new Exception('Admin not found.');

            // Deactivating the last active admin would lock everyone out.
            if ((int)$row['is_active'] === 1 && $activeCount() <= 1) {
                throw new Exception('This is the only active admin. Add another first.');
            }

            $s = $conn->prepare('UPDATE admins SET is_active = 1 - is_active WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            flash('Admin updated.');

        } elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $p1 = (string)($_POST['password'] ?? '');
            if (strlen($p1) < 8) throw new Exception('Password must be at least 8 characters.');

            // Changing your own password requires the current one.
            if ($id === $me['id']) {
                $cur = (string)($_POST['current_password'] ?? '');
                $s = $conn->prepare('SELECT password_hash FROM admins WHERE id = ?');
                $s->bind_param('i', $id);
                $s->execute();
                $h = $s->get_result()->fetch_assoc()['password_hash'] ?? '';
                $s->close();
                if (!password_verify($cur, $h)) throw new Exception('Current password is incorrect.');
            }

            $hash = password_hash($p1, PASSWORD_DEFAULT);
            $s = $conn->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $s->bind_param('si', $hash, $id);
            $s->execute();
            $s->close();
            flash('Password updated.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $me['id'])  throw new Exception('You cannot delete your own account.');
            if ($activeCount() <= 1) throw new Exception('Cannot delete the only active admin.');

            $s = $conn->prepare('DELETE FROM admins WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            flash('Admin deleted. Their orders were kept.');
        }
    } catch (Exception $ex) {
        flash($ex->getMessage(), 'error');
    }

    header('Location: admins.php');
    exit;
}

$admins = $conn->query(
    'SELECT a.*, (SELECT COUNT(*) FROM orders WHERE created_by = a.id) AS order_count
     FROM admins a ORDER BY a.id'
)->fetch_all(MYSQLI_ASSOC);

$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admins · Event Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .wrap{max-width:900px;margin:0 auto}
  .nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
  .nav h1{font-size:21px;font-weight:600}
  .nav .who{font-size:13px;color:#65676b}
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
  .b-me{background:#e7f0fd;color:#1451a8}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}
  .acts button{padding:5px 10px;font-size:13px}
  details{margin-top:10px}
  summary{cursor:pointer;font-size:13px;color:#1877f2}
  .hint{font-size:12px;color:#8a8d91;margin-top:4px}
</style>
</head>
<body>
<?php
  $PAGE  = 'admins';
  $TITLE = 'Admins';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add an admin</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <div>
          <label for="name">Name</label>
          <input id="name" name="name" required maxlength="120">
        </div>
        <div>
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" required pattern="[0-9]{10}" maxlength="10"
                 inputmode="numeric" placeholder="10-digit number">
        </div>
        <div>
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required minlength="8"
                 autocomplete="new-password">
          <p class="hint">At least 8 characters.</p>
        </div>
      </div>
      <button type="submit" class="primary">Add admin</button>
    </form>
  </div>

  <div class="card">
    <h2>All admins (<?= count($admins) ?>)</h2>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Name</th><th>Phone</th><th>Status</th><th>Orders</th><th>Last login</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): $isMe = (int)$a['id'] === $me['id']; ?>
          <tr>
            <td>
              <?= e($a['name']) ?>
              <?php if ($isMe): ?><span class="badge b-me" style="margin-left:6px">You</span><?php endif; ?>
            </td>
            <td><?= e($a['phone']) ?></td>
            <td><span class="badge <?= $a['is_active'] ? 'b-on' : 'b-off' ?>">
              <?= $a['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            <td><?= (int)$a['order_count'] ?></td>
            <td style="font-size:13px;color:#65676b">
              <?= $a['last_login'] ? date('d M, g:i a', strtotime($a['last_login'])) : 'Never' ?>
            </td>
            <td>
              <div class="acts">
                <?php if (!$isMe): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button><?= $a['is_active'] ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete <?= e($a['name']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="danger">Delete</button>
                  </form>
                <?php endif; ?>
              </div>

              <details>
                <summary><?= $isMe ? 'Change my password' : 'Reset password' ?></summary>
                <form method="post" style="margin-top:10px;max-width:280px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <?php if ($isMe): ?>
                    <label>Current password</label>
                    <input name="current_password" type="password" required
                           autocomplete="current-password" style="margin-bottom:8px">
                  <?php endif; ?>
                  <label>New password</label>
                  <input name="password" type="password" required minlength="8"
                         autocomplete="new-password" style="margin-bottom:8px">
                  <button class="primary">Update</button>
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