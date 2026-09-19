<?php
require 'config.php';
start_session();

// This page creates the FIRST admin only. Once one exists it locks itself out
// permanently — otherwise anyone could visit it and grant themselves access.
$count = (int)$conn->query('SELECT COUNT(*) c FROM admins')->fetch_assoc()['c'];
if ($count > 0) {
    http_response_code(403);
    die('Setup is already complete. <a href="login.php">Sign in</a>, then add admins from the Admins page.');
}

$error = '';
$name = $phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name  = trim($_POST['name'] ?? '');
    $phone = normalise_phone($_POST['phone'] ?? '');
    $p1    = (string)($_POST['password'] ?? '');
    $p2    = (string)($_POST['password2'] ?? '');

    if ($name === '')            $error = 'Name is required.';
    elseif (strlen($phone) !== 10) $error = 'Phone must be 10 digits.';
    elseif (strlen($p1) < 8)     $error = 'Password must be at least 8 characters.';
    elseif ($p1 !== $p2)         $error = 'Passwords do not match.';
    else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        $s = $conn->prepare('INSERT INTO admins (name, phone, password_hash) VALUES (?,?,?)');
        $s->bind_param('sss', $name, $phone, $hash);
        $s->execute();
        $s->close();

        flash('Admin account created. Sign in to continue.');
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;
       min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;line-height:1.5}
  .box{background:#fff;border:1px solid #dfe1e5;border-radius:12px;padding:28px;width:100%;max-width:420px}
  h1{font-size:21px;font-weight:600;margin-bottom:4px}
  .sub{color:#65676b;font-size:14px;margin-bottom:22px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:5px}
  input{width:100%;padding:11px 12px;border:1px solid #ccd0d5;border-radius:6px;font-size:15px;font-family:inherit}
  input:focus{outline:2px solid #1877f2;outline-offset:-1px;border-color:#1877f2}
  .field{margin-bottom:16px}
  .hint{font-size:12px;color:#8a8d91;margin-top:4px}
  button{width:100%;padding:12px;background:#1877f2;color:#fff;border:none;border-radius:6px;
         font-size:15px;font-weight:500;cursor:pointer;font-family:inherit}
  button:hover{background:#166fe5}
  .err{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2;padding:11px 13px;
       border-radius:8px;font-size:14px;margin-bottom:18px}
  .warn{background:#fdf3e0;color:#a06a00;border:1px solid #f2dcae;padding:11px 13px;
        border-radius:8px;font-size:13px;margin-bottom:18px}
</style>
</head>
<body>
  <div class="box">
    <h1>Create first admin</h1>
    <p class="sub">This page works only once.</p>

    <div class="warn">Delete <code>setup.php</code> from the server after this step.</div>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="name">Your name</label>
        <input id="name" name="name" required maxlength="120" value="<?= e($name) ?>" autofocus>
      </div>
      <div class="field">
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" required pattern="[0-9]{10}" maxlength="10"
               inputmode="numeric" value="<?= e($phone) ?>" placeholder="10-digit number">
        <p class="hint">You will sign in with this.</p>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8"
               autocomplete="new-password">
        <p class="hint">At least 8 characters.</p>
      </div>
      <div class="field">
        <label for="password2">Confirm password</label>
        <input id="password2" name="password2" type="password" required minlength="8"
               autocomplete="new-password">
      </div>
      <button type="submit">Create account</button>
    </form>
  </div>
</body>
</html>
