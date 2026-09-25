<?php
/**
 * Notifications: set up real-time push from the website.
 *
 * Paste the Firebase settings, upload the service-account key, send a
 * test. Everything is kept in app_settings, so nothing has to be edited
 * on the server by hand. The key is shown back only as the account it
 * belongs to; the private key itself never leaves the server again.
 */
require 'config.php';
require_once __DIR__ . '/lib_push.php';
$me = require_login();

const PUSH_WEB_KEYS = ['apiKey', 'authDomain', 'projectId', 'storageBucket', 'messagingSenderId', 'appId', 'measurementId'];

/**
 * The values out of Firebase's "SDK setup and configuration" snippet,
 * pasted as-is: `const firebaseConfig = { apiKey: "…", … };` or JSON.
 */
function push_parse_snippet(string $text): array {
    $out = [];
    foreach (PUSH_WEB_KEYS as $k) {
        if (preg_match('/["\']?' . $k . '["\']?\s*:\s*["\']([^"\']+)["\']/', $text, $m)) $out[$k] = trim($m[1]);
    }
    return $out;
}

/** The Android app's values out of a google-services.json, for this app's package. */
function push_parse_google_services(string $json): array {
    $g = json_decode($json, true);
    if (!is_array($g) || empty($g['client'])) throw new Exception('That is not a google-services.json file.');
    foreach ($g['client'] as $client) {
        $pkg = $client['client_info']['android_client_info']['package_name'] ?? '';
        if ($pkg !== 'com.madeforu.sales') continue;
        return [
            'apiKey'            => (string)($client['api_key'][0]['current_key'] ?? ''),
            'appId'             => (string)($client['client_info']['mobilesdk_app_id'] ?? ''),
            'projectId'         => (string)($g['project_info']['project_id'] ?? ''),
            'messagingSenderId' => (string)($g['project_info']['project_number'] ?? ''),
        ];
    }
    throw new Exception('That google-services.json has no app with package com.madeforu.sales. Add the Android app in Firebase with that package name.');
}

function push_uploaded(string $field): ?string {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('The upload failed (error ' . (int)$f['error'] . '). Try again.');
    if ($f['size'] > 50000) throw new Exception('That file is too large to be a Firebase JSON file.');
    return (string)file_get_contents($f['tmp_name']);
}

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'key') {
            $raw = push_uploaded('key');
            if ($raw === null) throw new Exception('Choose the service-account .json file first.');
            $sa = json_decode($raw, true);
            if (!is_array($sa) || ($sa['type'] ?? '') !== 'service_account'
                || empty($sa['private_key']) || empty($sa['client_email']) || empty($sa['project_id'])) {
                throw new Exception('That is not a service-account key. In Firebase: Project settings → Service accounts → Generate new private key.');
            }
            if (!openssl_pkey_get_private($sa['private_key'])) {
                throw new Exception('The private key in that file could not be read. Download a fresh one.');
            }
            push_state_set($conn, 'push_service_account', json_encode($sa));
            push_state_set($conn, 'push_oauth', '');   // an old access token belongs to the old key
            flash('Key saved for ' . $sa['client_email'] . '.');

        } elseif ($action === 'remove_key') {
            push_state_set($conn, 'push_service_account', '');
            push_state_set($conn, 'push_oauth', '');
            flash('Key removed. Nothing is pushed until a new one is uploaded.');

        } elseif ($action === 'web') {
            $web = [];
            foreach (PUSH_WEB_KEYS as $k) $web[$k] = trim((string)($_POST[$k] ?? ''));
            $pasted = trim((string)($_POST['snippet'] ?? ''));
            if ($pasted !== '') {
                $found = push_parse_snippet($pasted);
                if (!$found) throw new Exception('No Firebase settings found in what was pasted. Paste the whole firebaseConfig block.');
                $web = array_merge($web, $found);
            }
            $web['vapidKey'] = trim((string)($_POST['vapidKey'] ?? ''));
            foreach (['apiKey', 'projectId', 'messagingSenderId', 'appId'] as $need) {
                if ($web[$need] === '') throw new Exception("Web app: $need is missing.");
            }
            if ($web['vapidKey'] === '') throw new Exception('Web app: the Web Push key (vapidKey) is missing. Cloud Messaging → Web Push certificates.');
            push_state_set($conn, 'push_web', json_encode(array_filter($web, fn($v) => $v !== '')));
            flash('Web app settings saved.');

        } elseif ($action === 'android') {
            $raw = push_uploaded('google_services');
            $android = $raw !== null ? push_parse_google_services($raw) : [
                'apiKey'            => trim((string)($_POST['a_apiKey'] ?? '')),
                'appId'             => trim((string)($_POST['a_appId'] ?? '')),
                'projectId'         => trim((string)($_POST['a_projectId'] ?? '')),
                'messagingSenderId' => trim((string)($_POST['a_messagingSenderId'] ?? '')),
            ];
            // The project-wide values are the same as the web app's; fill
            // any left empty from there.
            $web = push_settings($conn)['web'];
            foreach (['apiKey', 'projectId', 'messagingSenderId'] as $k) {
                if (($android[$k] ?? '') === '') $android[$k] = (string)($web[$k] ?? '');
            }
            if (!preg_match('/^1:\d+:android:[0-9a-f]+$/i', $android['appId'])) {
                throw new Exception('Android: the App ID looks like 1:1234567890:android:abc123…');
            }
            foreach (['apiKey', 'projectId', 'messagingSenderId'] as $need) {
                if ($android[$need] === '') throw new Exception("Android: $need is missing. Save the web app first, or upload google-services.json.");
            }
            push_state_set($conn, 'push_android', json_encode($android));
            flash('Android app settings saved.');

        } elseif ($action === 'test') {
            if (!push_configured($conn)) throw new Exception('Upload the service-account key first.');
            push_ensure_table($conn);
            $s = $conn->prepare(
                'SELECT d.id, d.token, d.platform FROM push_devices d
                   JOIN api_tokens t ON t.id = d.api_token_id
                  WHERE d.admin_id = ? AND t.revoked = 0'
            );
            $id = (int)$me['id'];
            $s->bind_param('i', $id);
            $s->execute();
            $devices = $s->get_result()->fetch_all(MYSQLI_ASSOC);
            $s->close();
            if (!$devices) {
                throw new Exception('None of your devices is registered yet. Sign in to the web app or the Android app as '
                    . $me['name'] . ' and allow notifications there first.');
            }
            $jobs = array_map(fn($d) => ['device' => $d, 'data' => [
                'title' => 'Notifications are working', 'body' => 'Sent from the website. New sales will arrive like this.',
                'kind' => 'test', 'order_id' => '', 'tag' => 'test',
            ]], $devices);
            $sent = push_fcm($conn, $jobs);
            if ($sent === 0) throw new Exception('Firebase did not accept the message for any device. See the PHP error log for its answer.');
            flash("Test sent to $sent of your " . count($devices) . ' device(s). It should arrive within seconds.');

        } elseif ($action === 'check') {
            $problem = push_check($conn);
            if ($problem !== null) throw new Exception('Not working: ' . $problem);
            push_state_set($conn, 'push_last_error', '');
            flash('Google accepted the key and the server can reach Firebase. The server side is working.');

        } elseif ($action === 'remove_device') {
            push_ensure_table($conn);
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare('DELETE FROM push_devices WHERE id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            flash('Device removed. It is registered again the next time its app opens.');
        }
    } catch (Throwable $ex) {
        flash($ex->getMessage(), 'error');
    }
    header('Location: push_settings.php');
    exit;
}

// ── Page ───────────────────────────────────────────────────────────
$settings = push_settings($conn);
$sa = $settings['sa'];
$web = $settings['web'];
$android = $settings['android'];
$fromFile = defined('FIREBASE_SERVICE_ACCOUNT') || defined('FIREBASE_WEB') || defined('FIREBASE_ANDROID');

push_ensure_table($conn);
$devices = $conn->query(
    'SELECT d.id, d.platform, d.device, d.last_seen, a.name admin, (t.revoked = 0 AND t.expires_at > NOW()) live
       FROM push_devices d
       LEFT JOIN admins a ON a.id = d.admin_id
       LEFT JOIN api_tokens t ON t.id = d.api_token_id
      ORDER BY d.last_seen DESC'
)->fetch_all(MYSQLI_ASSOC);

$webReady = ($web['apiKey'] ?? '') !== '' && ($web['vapidKey'] ?? '') !== '';
$lastError = json_decode((string)push_state_get($conn, 'push_last_error'), true);
$androidReady = ($android['appId'] ?? '') !== '';
$flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:6px}
  .card p.hint{font-size:13px;color:#65676b;margin-bottom:12px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,textarea{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit}
  textarea{font-family:ui-monospace,Menlo,monospace;font-size:12px;min-height:120px}
  input[type=file]{padding:7px;background:#fafbfc}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px}
  button{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit}
  button:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}
  .primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
  .step{border:1px solid #dfe1e5;border-radius:8px;padding:10px 12px;font-size:14px;overflow-wrap:anywhere;min-width:0}
  .ok{color:#1a7f4b;font-weight:600} .no{color:#9a6300;font-weight:600}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b}
  td{padding:9px 8px;border-bottom:1px solid #eceef0;vertical-align:middle}
  .scroll{overflow-x:auto}
  .or{font-size:12px;color:#65676b;text-align:center;margin:10px 0}
  code{background:#f0f2f5;padding:1px 5px;border-radius:4px;font-size:12px}
  details summary{cursor:pointer;color:#1877f2;font-size:13px;margin-top:4px}
</style>
</head>
<body>
<?php
  $PAGE  = 'push_settings';
  $TITLE = 'Notifications';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Real-time notifications</h2>
    <p class="hint">When anyone saves a sale, payment, order change, expense or movement, here or in either
      app, every other partner's phone is told at once through Firebase. Free; about ten minutes to set up.</p>
    <div class="steps">
      <div class="step">1. Service-account key<br>
        <?= $sa ? '<span class="ok">✓ ' . e($sa['client_email']) . '</span>' : '<span class="no">Not uploaded</span>' ?></div>
      <div class="step">2. Web app (PWA)<br>
        <?= $webReady ? '<span class="ok">✓ ' . e($web['projectId'] ?? '') . '</span>' : '<span class="no">Not set</span>' ?></div>
      <div class="step">3. Android app<br>
        <?= $androidReady ? '<span class="ok">✓ ' . e($android['appId']) . '</span>' : '<span class="no">Not set</span>' ?></div>
      <div class="step">4. Devices registered<br>
        <span class="<?= $devices ? 'ok' : 'no' ?>"><?= count($devices) ?></span></div>
    </div>
    <?php if ($fromFile): ?>
      <p class="hint" style="margin-top:12px">A <code>firebase-config.php</code> file is on the server; its values take
        priority over what is saved here.</p>
    <?php endif; ?>
    <?php if ($sa): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="check">
          <button>Check connection to Google</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test">
          <button class="primary">Send a test notification to my devices</button></form>
      </div>
    <?php endif; ?>
    <?php if (is_array($lastError) && !empty($lastError['why'])): ?>
      <div class="flash f-error" style="margin:14px 0 0">Last problem sending
        (<?= e(date('j M, g:i a', strtotime((string)$lastError['at']))) ?>): <?= e($lastError['why']) ?></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>1. Service-account key</h2>
    <p class="hint">Firebase console → Project settings (gear) → <b>Service accounts</b> → <b>Generate new private key</b>.
      Upload the .json file it downloads. It is kept in the database, never in a folder a URL can reach, and is
      not shown here again.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="key">
      <div class="grid"><div><label for="key">Service-account .json</label>
        <input id="key" type="file" name="key" accept=".json,application/json" required></div></div>
      <button class="primary"><?= $sa ? 'Replace key' : 'Upload key' ?></button>
    </form>
    <?php if ($sa): ?>
      <p class="hint" style="margin-top:12px">Saved: <b><?= e($sa['client_email']) ?></b> · project <b><?= e($sa['project_id']) ?></b></p>
      <form method="post" onsubmit="return confirm('Remove the key? Notifications stop until a new one is uploaded.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_key">
        <button class="danger">Remove key</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>2. Web app (PWA)</h2>
    <p class="hint">Project settings → General → Your apps → add a <b>Web</b> app (&lt;/&gt;), then copy its
      <b>firebaseConfig</b> block and paste it below. The Web Push key is under Project settings →
      <b>Cloud Messaging</b> → Web Push certificates → <b>Generate key pair</b>.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="web">
      <label for="snippet">Paste the firebaseConfig block</label>
      <textarea id="snippet" name="snippet" placeholder='const firebaseConfig = {
  apiKey: "AIza…",
  authDomain: "madeforu.firebaseapp.com",
  projectId: "madeforu",
  messagingSenderId: "1234567890",
  appId: "1:1234567890:web:abc123"
};'></textarea>
      <details <?= $webReady ? 'open' : '' ?>><summary>…or check and edit each value</summary>
        <div class="grid" style="margin-top:10px">
          <?php foreach (PUSH_WEB_KEYS as $k): ?>
            <div><label for="w_<?= $k ?>"><?= $k ?></label>
              <input id="w_<?= $k ?>" name="<?= $k ?>" value="<?= e($web[$k] ?? '') ?>"></div>
          <?php endforeach; ?>
        </div>
      </details>
      <div class="grid" style="margin-top:12px"><div><label for="vapidKey">Web Push key (vapidKey)</label>
        <input id="vapidKey" name="vapidKey" value="<?= e($web['vapidKey'] ?? '') ?>" placeholder="B…" required></div></div>
      <button class="primary">Save web app</button>
    </form>
  </div>

  <div class="card">
    <h2>3. Android app</h2>
    <p class="hint">Project settings → General → Your apps → add an <b>Android</b> app with package name
      <code>com.madeforu.sales</code>. Upload the <b>google-services.json</b> it offers, or just type its App ID.
      The app itself does not need the file; the settings reach it from here.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="android">
      <div class="grid"><div><label for="gs">google-services.json</label>
        <input id="gs" type="file" name="google_services" accept=".json,application/json"></div></div>
      <div class="or">— or —</div>
      <div class="grid">
        <div><label for="a_appId">App ID</label>
          <input id="a_appId" name="a_appId" value="<?= e($android['appId'] ?? '') ?>" placeholder="1:1234567890:android:abc123"></div>
        <div><label for="a_apiKey">apiKey <small>(empty = same as web)</small></label>
          <input id="a_apiKey" name="a_apiKey" value="<?= e($android['apiKey'] ?? '') ?>"></div>
        <div><label for="a_projectId">projectId <small>(empty = same as web)</small></label>
          <input id="a_projectId" name="a_projectId" value="<?= e($android['projectId'] ?? '') ?>"></div>
        <div><label for="a_sender">messagingSenderId <small>(empty = same as web)</small></label>
          <input id="a_sender" name="a_messagingSenderId" value="<?= e($android['messagingSenderId'] ?? '') ?>"></div>
      </div>
      <button class="primary">Save Android app</button>
    </form>
  </div>

  <div class="card">
    <h2>4. Devices</h2>
    <p class="hint">Each phone or browser appears here once its app is opened and notifications are allowed.
      Signing out of an app stops its notifications on its own.</p>
    <?php if (!$devices): ?>
      <p class="hint">None yet.</p>
    <?php else: ?>
      <div class="scroll"><table>
        <thead><tr><th>Partner</th><th>App</th><th>Device</th><th>Last seen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
          <tr>
            <td><?= e($d['admin'] ?? '—') ?></td>
            <td><?= $d['platform'] === 'android' ? 'Android' : 'Web app' ?>
              <?= $d['live'] ? '' : '<br><small class="no">signed out</small>' ?></td>
            <td><small><?= e(mb_strimwidth((string)$d['device'], 0, 60, '…')) ?></small></td>
            <td><?= e(date('j M, g:i a', strtotime((string)$d['last_seen']))) ?></td>
            <td><form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_device">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="danger">Remove</button></form></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php require 'layout_end.php'; ?>
</body>
</html>
