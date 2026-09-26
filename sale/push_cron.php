<?php
/**
 * A backstop for real-time push, for changes that do not come through
 * the website or the apps (an edit in phpMyAdmin, say).
 *
 * Optional. Everything saved through the website, the PWA or the Android
 * app is pushed the moment it is saved without this. To catch the rest,
 * add a cron job in hPanel → Advanced → Cron Jobs, every minute:
 *
 *     php /home/<you>/public_html/push_cron.php
 *
 * Refuses to run from the web.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/config.php';
require_once __DIR__ . '/lib_push.php';

$sent = push_flush($conn, null, null, 'cron');
echo date('c') . " sent $sent\n";
