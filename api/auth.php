<?php
/**
 * Sign in / sign out for the Android app.
 *
 * Re-uses the website's credentials exactly: same admins table, same
 * bcrypt hashes, same phone+IP lockout counters. A partner has one
 * password for both the site and the app.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/**
 * Mint a token. 32 random bytes, returned once in plaintext and stored
 * only as a hash — losing the database does not hand anyone a session.
 */
function issue_token(mysqli $conn, int $adminId, string $device): array {
    $raw     = bin2hex(random_bytes(32));
    $hash    = api_hash_token($raw);
    $expires = date('Y-m-d H:i:s', time() + TOKEN_TTL_DAYS * 86400);
    $device  = substr($device, 0, 120);

    $s = $conn->prepare(
        'INSERT INTO api_tokens (admin_id, token_hash, device, expires_at) VALUES (?,?,?,?)'
    );
    $s->bind_param('isss', $adminId, $hash, $device, $expires);
    $s->execute();
    $s->close();

    return ['token' => $raw, 'expires_at' => $expires];
}

api_dispatch([

    // ── POST login {phone, password, device} ───────────────────────
    'login' => function () use ($conn) {
        $phone    = normalise_phone(api_str('phone'));
        $password = (string)api_in('password', '');
        $device   = api_str('device', 'Android');
        $ip       = client_ip();

        if (strlen($phone) !== 10) {
            throw new ApiInputError('Enter the 10-digit phone number you sign in with.');
        }
        if ($password === '') {
            throw new ApiInputError('Enter your password.');
        }

        if (failed_attempts($conn, $phone, $ip) >= MAX_ATTEMPTS) {
            api_fail(429, 'locked_out',
                'Too many failed attempts. Try again in ' . LOCKOUT_MINS . ' minutes.');
        }

        $s = $conn->prepare(
            'SELECT id, name, phone, password_hash, is_active FROM admins WHERE phone = ?'
        );
        $s->bind_param('s', $phone);
        $s->execute();
        $admin = $s->get_result()->fetch_assoc();
        $s->close();

        // Verify against a dummy hash when the account is unknown, so a
        // wrong phone and a wrong password take the same time and give the
        // same message. Otherwise the timing alone enumerates accounts.
        $hash = $admin['password_hash']
            ?? '$2y$10$usesomesillystringfoeasdfghjklqwertyuiopzxcvbnmasdfgh';
        $good = password_verify($password, $hash);

        if (!$admin || !$good) {
            record_attempt($conn, $phone, $ip);
            api_fail(401, 'bad_credentials', 'Wrong phone number or password.');
        }
        if ((int)$admin['is_active'] !== 1) {
            api_fail(403, 'disabled', 'This account has been disabled. Ask an admin to re-enable it.');
        }

        clear_attempts($conn, $phone, $ip);

        $u = $conn->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?');
        $u->bind_param('i', $admin['id']);
        $u->execute();
        $u->close();

        $tok = issue_token($conn, (int)$admin['id'], $device);

        api_ok([
            'token'      => $tok['token'],
            'expires_at' => $tok['expires_at'],
            'admin'      => [
                'id'    => (int)$admin['id'],
                'name'  => $admin['name'],
                'phone' => $admin['phone'],
            ],
            'api_version' => API_VERSION,
        ]);
    },

    // ── GET me ─────────────────────────────────────────────────────
    'me' => function () use ($conn) {
        $me = api_require_auth($conn);
        api_ok(['admin' => ['id' => $me['id'], 'name' => $me['name'], 'phone' => $me['phone']],
                'settings' => app_settings($conn),
                'api_version' => API_VERSION]);
    },

    // ── POST logout — revokes only this device's token ─────────────
    'logout' => function () use ($conn) {
        $me = api_require_auth($conn);
        $s = $conn->prepare('UPDATE api_tokens SET revoked = 1 WHERE id = ?');
        $s->bind_param('i', $me['token_id']);
        $s->execute();
        $s->close();
        api_ok(['message' => 'Signed out.']);
    },

    // ── POST logout_all — every device, e.g. after a lost phone ────
    'logout_all' => function () use ($conn) {
        $me = api_require_auth($conn);
        $s = $conn->prepare('UPDATE api_tokens SET revoked = 1 WHERE admin_id = ?');
        $s->bind_param('i', $me['id']);
        $s->execute();
        $s->close();
        api_ok(['message' => 'Signed out on all devices.']);
    },

    // ── POST change_password {current_password, new_password} ──────
    'change_password' => function () use ($conn) {
        $me      = api_require_auth($conn);
        $current = (string)api_in('current_password', '');
        $new     = (string)api_in('new_password', '');

        if (strlen($new) < 8) throw new ApiInputError('New password must be at least 8 characters.');

        $s = $conn->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $s->bind_param('i', $me['id']);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            api_fail(403, 'bad_password', 'Your current password is not right.');
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $u = $conn->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
        $u->bind_param('si', $hash, $me['id']);
        $u->execute();
        $u->close();

        // Changing a password ends every other session; the one that made
        // the change stays alive so the partner is not locked out mid-task.
        $r = $conn->prepare('UPDATE api_tokens SET revoked = 1 WHERE admin_id = ? AND id <> ?');
        $r->bind_param('ii', $me['id'], $me['token_id']);
        $r->execute();
        $r->close();

        api_ok(['message' => 'Password changed. Other devices were signed out.']);
    },

    // ── GET ping — used by Settings to test a server URL ───────────
    'ping' => function () use ($conn) {
        // `features` is what an app checks before blaming itself: it says
        // which api/ files are actually on this server, so "I updated and
        // nothing changed" has an answer on the Settings screen instead of
        // being guesswork.
        api_ok([
            'api_version' => API_VERSION,
            'features'    => API_FEATURES,
            'server_time' => date('c'),
        ]);
    },

    /**
     * GET app_version — the release channel for the Android app.
     *
     * Deliberately open: a partner whose build is too old to sign in still
     * needs to be told where the new one is. It exposes nothing but the
     * version numbers and the download link.
     */
    'app_version' => function () use ($conn) {
        $s = app_settings($conn);
        api_ok(['release' => [
            'version_code' => (int)($s['apk_version_code'] ?? 0),
            'version_name' => (string)($s['apk_version_name'] ?? ''),
            'apk_url'      => (string)($s['apk_url'] ?? ''),
            'notes'        => (string)($s['apk_notes'] ?? ''),
        ]]);
    },
]);
