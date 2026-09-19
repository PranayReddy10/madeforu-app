<?php
require 'config.php';
start_session();

if (current_admin()) { header('Location: index.php'); exit; }

// If no admins exist yet, send them to setup.
$hasAdmins = (int)$conn->query('SELECT COUNT(*) c FROM admins')->fetch_assoc()['c'] > 0;
if (!$hasAdmins) { header('Location: setup.php'); exit; }

$error = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $phone = normalise_phone($_POST['phone'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $ip    = client_ip();

    if (strlen($phone) !== 10) {
        $error = 'Enter a valid 10-digit phone number.';
    } elseif (failed_attempts($conn, $phone, $ip) >= MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Try again in ' . LOCKOUT_MINS . ' minutes.';
    } else {
        $s = $conn->prepare('SELECT * FROM admins WHERE phone = ? AND is_active = 1');
        $s->bind_param('s', $phone);
        $s->execute();
        $admin = $s->get_result()->fetch_assoc();
        $s->close();

        // Always run a hash comparison, even when the account does not exist.
        // Returning early would make a missing account measurably faster to
        // reject than a wrong password, leaking which phones are registered.
        $hash = $admin['password_hash']
            ?? '$2y$10$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $ok = password_verify($pass, $hash);

        if ($admin && $ok) {
            clear_attempts($conn, $phone, $ip);

            // New session id on privilege change, to defeat session fixation.
            session_regenerate_id(true);

            $_SESSION['admin'] = [
                'id'    => (int)$admin['id'],
                'name'  => $admin['name'],
                'phone' => $admin['phone'],
            ];

            $s = $conn->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?');
            $s->bind_param('i', $admin['id']);
            $s->execute();
            $s->close();

            $dest = $_SESSION['redirect_to'] ?? 'index.php';
            unset($_SESSION['redirect_to']);
            // Only allow relative paths, never an attacker-supplied absolute URL.
            if (!preg_match('#^[a-z0-9_\-]+\.php#i', $dest)) $dest = 'index.php';

            header('Location: ' . $dest);
            exit;
        }

        record_attempt($conn, $phone, $ip);
        $left = MAX_ATTEMPTS - failed_attempts($conn, $phone, $ip);
        // Same message for both failure modes.
        $error = 'Incorrect phone number or password.'
               . ($left > 0 && $left <= 2 ? " $left attempt" . ($left === 1 ? '' : 's') . ' left.' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Madeforu Orders Management</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;line-height:1.5}
  .box{background:#fff;border:1px solid #dfe1e5;border-radius:12px;padding:28px;width:100%;max-width:390px}
  h1{font-size:21px;font-weight:600;margin-bottom:4px}
  .sub{color:#65676b;font-size:14px;margin-bottom:22px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:5px}
  input{width:100%;padding:11px 12px;border:1px solid #ccd0d5;border-radius:6px;font-size:15px;font-family:inherit}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px;border-color:#1877f2}
  .field{margin-bottom:16px}
  button{width:100%;padding:12px;background:#1877f2;color:#fff;border:none;border-radius:6px;
         font-size:15px;font-weight:500;cursor:pointer;font-family:inherit}
  button:hover{background:#166fe5}
  .err{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2;padding:11px 13px;
       border-radius:8px;font-size:14px;margin-bottom:18px}
  .pw{position:relative}
  .pw button{position:absolute;right:6px;top:6px;width:auto;padding:5px 10px;background:none;
             color:#65676b;font-size:12px;border-radius:4px}
  .pw button:hover{background:#f0f2f5}
</style>
</head>
<body>
  <div class="box">
    <h1>Madeforu Orders Management</h1>
    <p class="sub">Sign in to manage orders.</p>

    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" required pattern="[0-9]{10}" maxlength="10"
               inputmode="numeric" autocomplete="username"
               value="<?= e($phone) ?>" placeholder="10-digit number" autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="pw">
          <input id="password" name="password" type="password" required
                 autocomplete="current-password" placeholder="Your password">
          <button type="button" onclick="tog()">Show</button>
        </div>
      </div>
      <button type="submit">Sign in</button>
    </form>
  </div>

<script>
function tog() {
  const p = document.getElementById('password');
  const b = event.target;
  if (p.type === 'password') { p.type = 'text'; b.textContent = 'Hide'; }
  else { p.type = 'password'; b.textContent = 'Show'; }
}
</script>
</body>
</html>
