<?php
/**
 * Real-time push: the moment a sale, payment, order change, expense or
 * movement is saved, every other partner's phone and web app is told,
 * through Firebase Cloud Messaging.
 *
 * How it fires
 * ------------
 * Every request that can write (a POST to the API, or to a website page
 * that includes this file) registers a shutdown hook. After the response
 * has gone back to whoever saved, the hook reads what changed since the
 * last push (lib_activity.php, the same reading the apps' feed uses) and
 * sends it. Nothing in any save handler had to change, and nothing that
 * saves waits for Google.
 *
 * Setup is the website's Notifications page (push_settings.php): paste
 * the Firebase settings, upload the service-account key. Until a key is
 * saved, this file does nothing at all.
 *
 * Who hears what
 * --------------
 * Everyone except the person whose request made the change. Devices are
 * tied to the sign-in (api_tokens row) that registered them, so signing
 * out, or "sign out all devices", stops pushes to that phone as well.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib_activity.php';
if (is_file(__DIR__ . '/firebase-config.php')) require_once __DIR__ . '/firebase-config.php';

/** Set by whoever authenticated this request: the API, or the website session. */
function push_set_actor(int $adminId): void { $GLOBALS['PUSH_ACTOR'] = $adminId; }

function push_actor(): ?int {
    if (!empty($GLOBALS['PUSH_ACTOR'])) return (int)$GLOBALS['PUSH_ACTOR'];
    // The website's session may already be written and closed by now; the
    // array is still in memory either way.
    if (!empty($_SESSION['admin']['id'])) {
        return (int)$_SESSION['admin']['id'];
    }
    return null;
}

/**
 * The Firebase settings: the service-account key, and the public web and
 * Android settings the apps register with.
 *
 * Normally entered on the website's Notifications page (push_settings.php)
 * and kept in app_settings; the key is stored there, never under
 * public_html where a URL could serve it. A firebase-config.php, if one
 * exists, still wins, for anyone who prefers a file.
 */
function push_settings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $sa = null;
    if (defined('FIREBASE_SERVICE_ACCOUNT') && is_readable((string)FIREBASE_SERVICE_ACCOUNT)) {
        $sa = json_decode((string)file_get_contents((string)FIREBASE_SERVICE_ACCOUNT), true);
    }
    if (!is_array($sa)) $sa = json_decode((string)push_state_get($conn, 'push_service_account'), true);
    $web = defined('FIREBASE_WEB') ? (array)FIREBASE_WEB
        : (json_decode((string)push_state_get($conn, 'push_web'), true) ?: []);
    $android = defined('FIREBASE_ANDROID') ? (array)FIREBASE_ANDROID
        : (json_decode((string)push_state_get($conn, 'push_android'), true) ?: []);

    return $cache = [
        'sa'      => is_array($sa) && !empty($sa['private_key']) && !empty($sa['client_email']) ? $sa : null,
        'web'     => $web,
        'android' => $android,
    ];
}

/** True once a service-account key is in place. */
function push_configured(mysqli $conn): bool {
    return defined('PUSH_DRY_RUN') || push_settings($conn)['sa'] !== null;
}

/** What the apps need to register: the public halves of the Firebase setup. */
function push_client_config(mysqli $conn): array {
    if (!push_configured($conn)) return ['enabled' => false];
    $settings = push_settings($conn);
    $web = $settings['web'];
    $android = $settings['android'];
    if (($android['appId'] ?? '') !== '') {
        // The sender is the project number written into the App ID; sent
        // from there so it can never disagree with it (Firebase answers
        // that with INVALID_SENDER). Settings saved before the page knew
        // about debug builds get their one App ID as the release one.
        if (preg_match('/^1:(\d+):android:/i', (string)$android['appId'], $m)) $android['messagingSenderId'] = $m[1];
        if (empty($android['apps'])) $android['apps'] = ['com.madeforu.sales' => $android['appId']];
    }
    return [
        'enabled' => true,
        'web'     => ($web['apiKey'] ?? '') !== '' && ($web['vapidKey'] ?? '') !== '' ? $web : null,
        'android' => ($android['appId'] ?? '') !== '' ? $android : null,
    ];
}

function push_ensure_table(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $conn->query(
        'CREATE TABLE IF NOT EXISTS push_devices (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            api_token_id INT NULL,
            platform VARCHAR(10) NOT NULL,
            token VARCHAR(255) NOT NULL,
            device VARCHAR(120) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_push_token (token),
            KEY ix_push_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Remember this device for pushes, for the sign-in that is registering it. */
function push_register(mysqli $conn, int $adminId, int $apiTokenId, string $platform, string $token, string $device): void {
    push_ensure_table($conn);
    $s = $conn->prepare(
        'INSERT INTO push_devices (admin_id, api_token_id, platform, token, device)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), api_token_id = VALUES(api_token_id),
                                 platform = VALUES(platform), device = VALUES(device), last_seen = NOW()'
    );
    $s->bind_param('iisss', $adminId, $apiTokenId, $platform, $token, $device);
    $s->execute();
    $s->close();
}

function push_unregister(mysqli $conn, string $token): void {
    push_ensure_table($conn);
    $s = $conn->prepare('DELETE FROM push_devices WHERE token = ?');
    $s->bind_param('s', $token);
    $s->execute();
    $s->close();
}

// ── Small state kept in app_settings ───────────────────────────────

function push_state_get(mysqli $conn, string $key): ?string {
    $s = $conn->prepare('SELECT sval FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ? (string)$row['sval'] : null;
}

/**
 * Replace the row outright, rather than INSERT … ON DUPLICATE KEY. That
 * relies on skey being the primary key; on a copy of the table that lost
 * it in an import, it quietly adds a second row and the old value keeps
 * being read back, so a saved setting looks as if it never changed.
 */
function push_state_set(mysqli $conn, string $key, string $value): void {
    $s = $conn->prepare('DELETE FROM app_settings WHERE skey = ?');
    $s->bind_param('s', $key);
    $s->execute();
    $s->close();
    $s = $conn->prepare('INSERT INTO app_settings (skey, sval) VALUES (?, ?)');
    $s->bind_param('ss', $key, $value);
    $s->execute();
    $s->close();
}

// ── The flush ──────────────────────────────────────────────────────

/**
 * Send everything changed since the last push. Returns how many
 * messages went out (for push_cron.php and the tests).
 *
 * The cursor is {at, seen}: the second it has read up to, and which
 * changes in exactly that second were already sent. Reading from `at`
 * inclusively and skipping `seen` is what lets a sale be pushed in the
 * same second it was saved without a second sale in that same second
 * being lost. A MySQL lock makes two requests finishing together take
 * turns rather than send the same change twice.
 */
function push_flush(mysqli $conn, ?int $actor): int {
    if (!push_configured($conn)) return 0;
    $got = $conn->query("SELECT GET_LOCK('madeforu_push', 10) l")->fetch_assoc();
    if ((int)($got['l'] ?? 0) !== 1) return 0;
    try {
        $now = (string)$conn->query('SELECT NOW() n')->fetch_assoc()['n'];
        $state = json_decode((string)push_state_get($conn, 'push_cursor'), true);
        if (!is_array($state) || empty($state['at'])) {
            // First run: nothing before this moment is news.
            push_state_set($conn, 'push_cursor', json_encode(['at' => $now, 'seen' => []]));
            return 0;
        }
        $at = (string)$state['at'];
        $seen = array_flip((array)($state['seen'] ?? []));

        $items = activity_items($conn, $at, $now, (int)$actor, '>=', $capped);
        $key = fn(array $i) => $i['kind'] . ':' . $i['id'];
        $items = array_values(array_filter($items,
            fn($i) => !((string)$i['at'] === $at && isset($seen[$key($i)]))));

        if ($capped !== null) {
            // A kind came back full: send what is older than its cut-off
            // and resume from that second.
            $items = array_values(array_filter($items, fn($i) => (string)$i['at'] < $capped));
            $next = ['at' => $capped, 'seen' => []];
        } else {
            $sentNow = array_map($key, array_filter($items, fn($i) => (string)$i['at'] === $now));
            $carry = $at === $now ? array_keys($seen) : [];
            $next = ['at' => $now, 'seen' => array_values(array_unique(array_merge($carry, $sentNow)))];
        }

        $sent = $items ? push_send($conn, $items, $actor) : 0;
        push_state_set($conn, 'push_cursor', json_encode($next));
        return $sent;
    } finally {
        $conn->query("SELECT RELEASE_LOCK('madeforu_push')");
    }
}

/** Build the messages and send each to every device but the actor's. */
function push_send(mysqli $conn, array $items, ?int $actor): int {
    push_ensure_table($conn);
    $s = $conn->prepare(
        'SELECT d.id, d.token, d.platform FROM push_devices d
           JOIN api_tokens t ON t.id = d.api_token_id
          WHERE t.revoked = 0 AND t.expires_at > NOW() AND d.admin_id <> ?'
    );
    $skip = (int)$actor;   // 0 matches nobody: a cron flush tells everyone
    $s->bind_param('i', $skip);
    $s->execute();
    $devices = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    if (!$devices) return 0;

    // A few changes: one message each. A burst (crediting an event
    // touches every order in it): one summary, not a wall of alerts.
    if (count($items) > 3) {
        $last = array_slice($items, -4);
        $messages = [[
            'title' => count($items) . ' updates',
            'body'  => implode("\n", array_map(fn($i) => $i['title'], $last)),
            'kind'  => 'summary', 'order_id' => '', 'tag' => 'summary',
        ]];
    } else {
        $messages = array_map(fn($i) => [
            'title'    => (string)$i['title'],
            'body'     => (string)$i['body'],
            'kind'     => (string)$i['kind'],
            'order_id' => $i['order_id'] ? (string)$i['order_id'] : '',
            'tag'      => $i['kind'] . ':' . $i['id'],
        ], $items);
    }

    $jobs = [];
    foreach ($devices as $d) {
        foreach ($messages as $m) $jobs[] = ['device' => $d, 'data' => $m];
    }
    return push_fcm($conn, $jobs);
}

// ── Firebase Cloud Messaging (HTTP v1) ─────────────────────────────

/** The Android notification channel pushes go to; the app creates it (Notifier.CHANNEL_ID). */
const PUSH_ANDROID_CHANNEL = 'updates';

/**
 * Every call to Google: IPv4, because a shared host with a half-working
 * IPv6 route hangs for the whole timeout on every request; and short
 * timeouts, because nobody saving a sale should wait on Google.
 */
const PUSH_CURL = [
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
];

/**
 * The last thing that went wrong, with when, for the Notifications page.
 * A push fails after the response has gone, where nobody sees it; this
 * is where it becomes visible.
 */
function push_note_error(mysqli $conn, string $why): void {
    error_log('MadeForU push: ' . $why);
    try {
        push_state_set($conn, 'push_last_error', json_encode(['at' => date('Y-m-d H:i:s'), 'why' => $why]));
    } catch (Throwable $e) { /* the log line above still has it */ }
}

/** Firebase's own sentence out of an error body, not the whole JSON. */
function push_fcm_reason(string $body): string {
    $j = json_decode($body, true);
    $msg = $j['error']['message'] ?? substr($body, 0, 200);
    $status = $j['error']['status'] ?? '';
    return trim($status . ' ' . $msg);
}

/**
 * Check the whole server side against Google, now: the key signs, Google
 * accepts it, and FCM is reachable. Returns null when all is well, or the
 * reason it is not.
 */
function push_check(mysqli $conn): ?string {
    $settings = push_settings($conn);
    if ($settings['sa'] === null) return 'No service-account key is saved yet.';
    try {
        push_access_token($conn, $settings['sa'], true);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

function push_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** An OAuth access token for FCM, from the service account, cached for its hour. */
function push_access_token(mysqli $conn, array $sa, bool $fresh = false): string {
    $cached = json_decode((string)push_state_get($conn, 'push_oauth'), true);
    if (!$fresh && is_array($cached) && ($cached['exp'] ?? 0) > time() + 120) return (string)$cached['token'];

    $iat = time();
    $jwt = push_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
         . push_b64url(json_encode([
             'iss'   => $sa['client_email'],
             'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
             'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
             'iat'   => $iat,
             'exp'   => $iat + 3600,
         ]));
    if (!openssl_sign($jwt, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign with the Firebase service account key.');
    }
    $jwt .= '.' . push_b64url($signature);

    $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ] + PUSH_CURL);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('The server could not reach Google (' . $curlError . '). '
            . 'The hosting may be blocking outgoing connections.');
    }
    $reply = json_decode((string)$raw, true);
    if (empty($reply['access_token'])) {
        throw new RuntimeException('Google refused the service-account key: '
            . ($reply['error_description'] ?? $reply['error'] ?? substr((string)$raw, 0, 200))
            . '. Upload a freshly generated key.');
    }
    push_state_set($conn, 'push_oauth', json_encode([
        'token' => $reply['access_token'],
        'exp'   => $iat + (int)($reply['expires_in'] ?? 3600),
    ]));
    return (string)$reply['access_token'];
}

/**
 * Send every job in parallel. Tokens Firebase says are dead (the app was
 * uninstalled, the browser's permission revoked) are forgotten.
 */
function push_fcm(mysqli $conn, array $jobs): int {
    if (defined('PUSH_DRY_RUN')) {
        foreach ($jobs as $j) {
            file_put_contents((string)PUSH_DRY_RUN, json_encode([
                'to' => $j['device']['platform'] . ':' . $j['device']['token'],
            ] + $j['data'], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        }
        return count($jobs);
    }

    $sa = push_settings($conn)['sa'];
    if (!is_array($sa) || empty($sa['project_id'])) {
        throw new RuntimeException('No Firebase service-account key is saved. Upload one on the Notifications page.');
    }
    $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
    $auth = 'Authorization: Bearer ' . push_access_token($conn, $sa);

    $multi = curl_multi_init();
    $handles = [];
    foreach ($jobs as $n => $j) {
        $d = $j['data'];
        if ($j['device']['platform'] === 'android') {
            // A notification Android draws itself. A data-only message
            // needs the app started to show anything, and Xiaomi, Oppo,
            // Vivo and others block that for an app that is not running,
            // so the push arrived and nothing appeared. The data rides
            // along, and a tap opens the order (MainActivity reads it).
            $message = [
                'token'        => $j['device']['token'],
                'notification' => ['title' => $d['title'], 'body' => $d['body']],
                'data'         => $d,
                'android'      => [
                    'priority'     => 'HIGH',
                    'ttl'          => '86400s',
                    'notification' => [
                        'channel_id'            => PUSH_ANDROID_CHANNEL,
                        'tag'                   => $d['tag'],
                        'icon'                  => 'ic_stat_notify',
                        'color'                 => '#F54A77',
                        'default_sound'         => true,
                        'notification_priority' => 'PRIORITY_HIGH',
                        'visibility'            => 'PRIVATE',
                    ],
                ],
            ];
        } else {
            // Data-only: the service worker draws it, so a tap can open
            // the order it is about.
            $message = [
                'token'   => $j['device']['token'],
                'data'    => $d,
                'webpush' => ['headers' => ['Urgency' => 'high', 'TTL' => '86400']],
            ];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [$auth, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
        ] + PUSH_CURL);
        curl_multi_add_handle($multi, $ch);
        $handles[$n] = $ch;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $status === CURLM_OK);

    $sent = 0;
    $dead = [];
    foreach ($handles as $n => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = (string)curl_multi_getcontent($ch);
        if ($code === 200) {
            $sent++;
        } elseif ($code === 404 || strpos($body, 'UNREGISTERED') !== false
                  || ($code === 400 && strpos($body, 'registration token') !== false)) {
            $dead[(int)$jobs[$n]['device']['id']] = true;
        } else {
            $why = $code === 0 ? 'no answer from Firebase (' . curl_error($ch) . ')'
                : 'Firebase answered ' . $code . ': ' . push_fcm_reason($body);
            push_note_error($conn, $why);
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);

    foreach (array_keys($dead) as $id) {
        $s = $conn->prepare('DELETE FROM push_devices WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $s->close();
    }
    return $sent;
}

// ── The hook ───────────────────────────────────────────────────────

/**
 * After a write request has answered, push what it changed. A fresh
 * connection, so a page that closed its own (or left a transaction
 * open) cannot get in the way. A failure is logged, never shown: the
 * sale is saved either way.
 */
function push_after_request(): void {
    if (!defined('DB_HOST')) return;
    $actor = push_actor();
    // Answer the person who saved first, then talk to Google. Hostinger
    // runs LiteSpeed, which has its own name for this; without it, every
    // save would wait for the push to go out.
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        push_flush($conn, $actor);   // does nothing until a key is saved
        $conn->close();
    } catch (Throwable $e) {
        if (isset($conn) && $conn instanceof mysqli) push_note_error($conn, $e->getMessage());
        else error_log('MadeForU push: ' . $e->getMessage());
    }
}

if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    register_shutdown_function('push_after_request');
}
