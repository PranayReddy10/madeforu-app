<?php
/**
 * Push registration for the apps.
 *
 *   GET  config                    the public Firebase settings the app
 *                                  needs, or {enabled:false}
 *   POST register {token, platform, device}
 *                                  this device now hears about changes
 *   POST unregister {token}        it stops
 *   POST test                      push a test message to the caller's
 *                                  own devices, to check the setup
 *
 * The sending itself happens in lib_push.php, after every write.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

api_dispatch([
    'config' => function () {
        api_ok(['push' => push_client_config()]);
    },

    'register' => function () use ($conn, $me) {
        $token = api_str('token');
        $platform = api_str('platform');
        if ($token === '' || strlen($token) > 255) throw new ApiInputError('A push token is needed.');
        if (!in_array($platform, ['web', 'android'], true)) throw new ApiInputError('platform is web or android.');
        push_register($conn, (int)$me['id'], (int)$me['token_id'], $platform, $token,
            substr(api_str('device'), 0, 120));
        api_ok(['message' => 'This device will be notified.']);
    },

    'unregister' => function () use ($conn) {
        $token = api_str('token');
        if ($token !== '') push_unregister($conn, $token);
        api_ok(['message' => 'This device will not be notified.']);
    },

    'test' => function () use ($conn, $me) {
        if (!push_configured()) throw new ApiInputError('Push is not set up on the server yet (firebase-config.php).');
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
        if (!$devices) throw new ApiInputError('None of your devices is registered for notifications yet.');
        $jobs = array_map(fn($d) => ['device' => $d, 'data' => [
            'title' => 'Notifications are working', 'body' => 'This is how new sales will arrive.',
            'kind' => 'test', 'order_id' => '', 'tag' => 'test',
        ]], $devices);
        $sent = push_fcm($conn, $jobs);
        api_ok(['message' => "Sent to $sent of " . count($devices) . ' device(s).']);
    },
]);
