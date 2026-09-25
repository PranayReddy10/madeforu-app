<?php
/**
 * MadeForU JSON API — shared bootstrap.
 *
 * Drop this whole `api/` folder next to the existing PHP pages on
 * sale.madeforu.co.in. It deliberately re-uses ../config.php so the order
 * math (recalc_total, recalc_paid, pay_status, clamp_discount,
 * normalise_status, generate_order_no) has exactly one implementation.
 * If config.php changes, the API follows automatically.
 *
 * Differences from the web pages:
 *   - No sessions, no CSRF: the app authenticates with a bearer token
 *     (see api_tokens). CSRF does not apply to a token the browser never
 *     stores and never sends automatically.
 *   - Never emits HTML. Every response is JSON, including failures.
 */

declare(strict_types=1);

// Warnings are never part of an answer.
//
// On shared hosting display_errors is often on, and PHP 8 is generous
// with deprecation notices. One of those printed anywhere in a request
// lands in the response body, and the phone then has JSON with prose
// stuck to it -- which is not JSON, so the whole screen fails with
// nothing to show for it. Silencing the DISPLAY only: error_reporting
// is left alone, so the host's error log still receives everything.
ini_set('display_errors', '0');
ini_set('html_errors', '0');

// Buffer everything: a stray notice from an included file would otherwise
// land in front of the JSON and break the parser on the phone.
ob_start();

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib_money.php';   // revenue_sources(), shared with the website

// config.php installs a text/plain exception handler meant for HTML pages.
// Replace it so an escaped throwable still reaches the app as JSON.
set_exception_handler(function (Throwable $t) {
    api_fail(500, 'server_error',
        SHOW_ERRORS ? $t->getMessage() . ' @ ' . basename($t->getFile()) . ':' . $t->getLine()
                    : 'Something went wrong on the server.');
});

// ── API-wide constants ─────────────────────────────────────────────
define('API_VERSION',      '1.8.0');

/**
 * What this build of the API can do, for the apps to check against.
 *
 * An app is upgraded by rebuilding it; the API is upgraded by uploading
 * files to the server, and the two happen days apart. When a screen needs
 * something the server does not have yet, the app has to be able to say
 * so — silently rendering nothing is what makes a stale upload look like
 * a broken app. Add a name here when you add a route or a payload field
 * an app depends on.
 */
define('API_FEATURES', [
    'revenue_breakdown',   // finance.php: the working behind "Revenue (all sales)"
    'expense_create',      // expenses.php: create/update/delete with a payment split
    'expense_items',       // expenses.php: line items on `get`
    'bills',               // bills.php: issue/get/list/html
    'app_version',         // auth.php: the Android release channel
    'price_history',       // catalog.php: price history, and prices frozen onto sold lines
    'movements_ledger',    // finance.php: filtered totals and delete_movement
    'all_channel_revenue', // finance.php: revenue from orders AND other credits, per-partner detail
    'movement_edit',       // finance.php: update_movement, and the pocket/revenue split basis
    'reprice_open',        // catalog.php: carry a new price onto orders that are still open
    'wholesale',           // wholesale.php: the wholesale buyers notebook (no revenue, no stock)
    'activity_feed',       // activity.php: what changed since a cursor, for notifications
]);
define('TOKEN_TTL_DAYS',   90);     // a partner phone stays signed in for a quarter
define('MAX_PAGE_SIZE',    200);

// ── Response helpers ───────────────────────────────────────────────

function api_headers(): void {
    if (headers_sent()) return;
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    // The Android client is not a browser, so CORS is only here for the
    // occasional debugging fetch from a dev machine.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}

/** Success envelope. Always {"ok":true, ...payload}. */
function api_ok(array $payload = []): void {
    api_send(['ok' => true] + $payload);
}

/**
 * Put one JSON document on the wire and nothing else, ever.
 *
 * Three ways stray output used to reach the phone, all of which produced
 * the same useless "the server sent a reply the app could not read":
 *
 *   printed BEFORE the response -- ob_clean() already handled that, but
 *   only the innermost buffer, and a host with output_buffering set in
 *   php.ini gives us an outer one as well;
 *
 *   printed AFTER it -- a notice raised while PHP shuts down lands past
 *   the closing brace, and no amount of cleaning beforehand helps. The
 *   throwaway buffer opened below swallows it;
 *
 *   printed by something that ALSO sent headers first, so the JSON
 *   content type never took.
 *
 * Whatever was caught is handed back in `notice` rather than binned.
 * A warning nobody can see is one nobody fixes, and this API is behind a
 * bearer token, so there is no stranger to leak it to.
 */
function api_send(array $payload, ?int $status = null): void {
    // Collect and close every buffer, innermost first.
    $stray = '';
    while (ob_get_level() > 0) {
        $stray .= (string)ob_get_clean();
    }
    $stray = trim($stray);

    // Output that escaped before our buffer even opened cannot be caught
    // -- text sitting in front of a file's <?php is already on the wire
    // by the time this file is included. It CAN be named, though:
    // headers_sent() reports the file and line that printed first, which
    // is the one thing worth knowing and the one thing the phone could
    // never work out on its own.
    $where = '';
    $f = ''; $l = 0;
    if (headers_sent($f, $l) && $f !== '') {
        $where = 'output started at ' . $f . ':' . $l;
    }

    $parts = array_filter([
        $where,
        $stray === '' ? '' : preg_replace('/\s+/', ' ', $stray),
    ]);
    if ($parts) {
        $payload['notice'] = mb_substr(implode(' — ', $parts), 0, 400);
    }

    if ($status !== null) http_response_code($status);
    api_headers();
    echo json_encode($payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    // Anything raised from here on -- a destructor, a shutdown notice --
    // goes in here and is dropped, instead of landing after the JSON.
    ob_start();
    register_shutdown_function(static function (): void {
        while (ob_get_level() > 0) ob_end_clean();
    });
    exit;
}

/**
 * Failure envelope. `code` is a stable machine string the app switches on;
 * `message` is what a partner reads on screen, so it must be plain English.
 */
function api_fail(int $status, string $code, string $message, array $extra = []): void {
    api_send(['ok' => false, 'error' => ['code' => $code, 'message' => $message] + $extra], $status);
}

/** Thrown by handlers for anything the user can fix by changing their input. */
class ApiInputError extends Exception {}

// ── Request helpers ────────────────────────────────────────────────

/** Decoded JSON body, falling back to a regular form post. */
function api_body(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $cache = $_POST;

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        // A form post still works, which keeps curl debugging easy.
        return $cache = ($_POST ?: []);
    }
    return $cache = $decoded;
}

/** Read from the JSON body first, then the query string. */
function api_in(string $key, $default = null) {
    $b = api_body();
    if (array_key_exists($key, $b))  return $b[$key];
    if (array_key_exists($key, $_GET)) return $_GET[$key];
    return $default;
}

function api_str(string $key, string $default = ''): string {
    $v = api_in($key, $default);
    return is_scalar($v) ? trim((string)$v) : $default;
}

function api_int(string $key, int $default = 0): int {
    $v = api_in($key, $default);
    return is_numeric($v) ? (int)$v : $default;
}

function api_float(string $key, float $default = 0.0): float {
    $v = api_in($key, $default);
    return is_numeric($v) ? round((float)$v, 2) : $default;
}

/** Checkboxes arrive as true/1/"1"/"true"/"on" depending on the client. */
function api_bool(string $key, bool $default = false): bool {
    $v = api_in($key, null);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function api_action(): string {
    return api_str('action', 'index');
}

/** ISO date or '' — used by every date-range filter. */
function api_date(string $key, string $default = ''): string {
    $v = api_str($key, $default);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
}

/**
 * Route table dispatcher. Handlers are `fn(array $me): void` and end by
 * calling api_ok(). Input errors become a 422 the app shows inline.
 */
function api_dispatch(array $routes, ?array $me = null): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { api_headers(); exit; }

    $action = api_action();
    if (!isset($routes[$action])) {
        api_fail(404, 'unknown_action', 'Unknown action: ' . $action,
            ['available' => array_keys($routes)]);
    }
    try {
        $routes[$action]($me);
    } catch (ApiInputError $e) {
        api_fail(422, 'invalid_input', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        api_fail(500, 'db_error',
            SHOW_ERRORS ? $e->getMessage() : 'The database rejected that. Nothing was saved.');
    } catch (Exception $e) {
        api_fail(400, 'failed', $e->getMessage());
    }
}

// ── Auth (bearer tokens) ───────────────────────────────────────────

/**
 * Raw token the client sent, from the Authorization header or, as a
 * fallback for hosts that strip it, a `token` parameter.
 */
function api_raw_token(): string {
    $hdr = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        // Hostinger's CGI setup drops Authorization unless .htaccess
        // re-adds it; this is the re-added copy.
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));

    // `auth` is the query-string fallback, for the one case that cannot
    // send a header: a WebView loading the printable bill. It is separate
    // from `token`, which means a bill's own public token — sending a
    // session token as `token` made every print look up a bill that does
    // not exist and answer {"ok":false}.
    $q = api_str('auth');
    return $q !== '' ? $q : api_str('token');
}

function api_hash_token(string $raw): string {
    return hash('sha256', $raw);
}

/**
 * Resolve the caller, or stop with 401. Returns the admin row.
 * The token is stored hashed, so a leaked database dump cannot be replayed
 * as a login — the same reason passwords are hashed.
 */
function api_require_auth(mysqli $conn): array {
    $raw = api_raw_token();
    if ($raw === '') {
        api_fail(401, 'no_token', 'Sign in to continue.');
    }
    $hash = api_hash_token($raw);

    $s = $conn->prepare(
        'SELECT t.id token_id, t.expires_at, a.id, a.name, a.phone, a.is_active
           FROM api_tokens t
           JOIN admins a ON a.id = t.admin_id
          WHERE t.token_hash = ? AND t.revoked = 0'
    );
    $s->bind_param('s', $hash);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$row)                        api_fail(401, 'bad_token',  'Your session has ended. Please sign in again.');
    if ((int)$row['is_active'] !== 1) api_fail(403, 'disabled',   'This account has been disabled.');
    if (strtotime((string)$row['expires_at']) < time()) {
        api_fail(401, 'expired_token', 'Your session has expired. Please sign in again.');
    }

    // Touch last_used_at at most once a minute: every request writing a row
    // would be pure write amplification on shared hosting.
    $u = $conn->prepare(
        'UPDATE api_tokens SET last_used_at = NOW()
          WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)'
    );
    $u->bind_param('i', $row['token_id']);
    $u->execute();
    $u->close();

    return ['id' => (int)$row['id'], 'name' => $row['name'], 'phone' => $row['phone'],
            'token_id' => (int)$row['token_id']];
}

// ── Settings (business identity used on bills) ─────────────────────

const APP_SETTING_DEFAULTS = [
    'business_name'  => 'MadeForU',
    'business_tag'   => 'Handmade personalised gifts',
    'business_addr'  => 'Hyderabad, Telangana',
    'business_phone' => '+91 93810 24794',
    'business_email' => '',
    'business_site'  => 'madeforu.co.in',
    'gstin'          => '',
    'upi_id'         => '',
    'upi_name'       => 'MadeForU',
    'bill_prefix'    => 'MFU',
    'bill_footer'    => 'Thank you for shopping with MadeForU!',
    'bill_terms'     => 'Custom-made items are not returnable. Damage on arrival must be reported within 48 hours with photos.',
    // Printed at the top of every bill. Any public image URL works; the
    // WordPress media library is the easy place to host it.
    'logo_url'       => '',
    // Release channel for the Android app. The app compares apk_version_code
    // against its own and offers the download when this one is higher.
    'apk_url'          => '',
    'apk_version_name' => '',
    'apk_version_code' => '0',
    'apk_notes'        => '',
];

function app_settings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = APP_SETTING_DEFAULTS;
    try {
        $res = $conn->query('SELECT skey, sval FROM app_settings');
        while ($res && ($r = $res->fetch_assoc())) {
            $out[$r['skey']] = (string)$r['sval'];
        }
    } catch (mysqli_sql_exception $e) {
        // Table not migrated yet — defaults keep bills printable.
    }
    return $cache = $out;
}

function app_setting_put(mysqli $conn, string $key, string $value): void {
    $s = $conn->prepare(
        'INSERT INTO app_settings (skey, sval) VALUES (?,?)
         ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
    );
    $s->bind_param('ss', $key, $value);
    $s->execute();
    $s->close();
}

// ── Shared shaping helpers ─────────────────────────────────────────

/**
 * One order row as the app sees it. Money is sent as a number (the app
 * formats it), plus `*_text` strings only where the server owns the wording.
 */
function api_order_row(array $o): array {
    $total = (float)$o['total'];
    $paid  = (float)$o['paid_amount'];
    return [
        'id'            => (int)$o['id'],
        'order_no'      => (string)$o['order_no'],
        'name'          => (string)$o['name'],
        'phone'         => (string)($o['phone'] ?? ''),
        'is_walk_in'    => trim((string)($o['phone'] ?? '')) === '',
        'event_id'      => isset($o['event_id']) && $o['event_id'] !== null ? (int)$o['event_id'] : null,
        'event_name'    => $o['event_name'] ?? null,
        'subtotal'      => (float)$o['subtotal'],
        'extra_charge'  => (float)($o['extra_charge'] ?? 0),
        'extra_charge_reason' => $o['extra_charge_reason'] ?? null,
        'discount'      => (float)$o['discount'],
        'discount_reason' => $o['discount_reason'] ?? null,
        'total'         => $total,
        'paid_amount'   => $paid,
        'balance'       => round(max($total - $paid, 0), 2),
        'pay_status'    => pay_status($total, $paid),
        'is_ready'      => (int)$o['is_ready'] === 1,
        'is_delivered'  => (int)$o['is_delivered'] === 1,
        'awb'           => ($o['awb'] ?? '') !== '' ? (string)$o['awb'] : null,
        'track_url'     => delhivery_link($o['awb'] ?? null),
        'dispatch_date' => $o['dispatch_date'] ?? null,
        'notes'         => $o['notes'] ?? null,
        'items_text'    => $o['items_text'] ?? null,
        'created_at'    => (string)$o['created_at'],
        'updated_at'    => (string)($o['updated_at'] ?? $o['created_at']),
        'created_by'    => $o['created_by_name'] ?? null,
    ];
}

/**
 * The item summary used in lists. A correlated subquery, not JOIN+GROUP BY:
 * production runs with ONLY_FULL_GROUP_BY, which rejects the grouped form.
 */
const ORDER_ITEMS_SUBQUERY = "(SELECT GROUP_CONCAT(CONCAT(oi.item, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ', ')
                               FROM order_items oi WHERE oi.order_id = o.id)";

/** Rupees in Indian words, for the bill. 1,23,456 -> lakh/crore, not million. */
/**
 * Indian financial year label for a date: 2026-09-18 -> "26-27".
 * Bills restart their numbering each April and the Money screen labels
 * the year the same way, so the boundary is defined once, here.
 */
function fy_label(string $date): string {
    $ts = strtotime($date) ?: time();
    $y  = (int)date('Y', $ts);
    $m  = (int)date('n', $ts);
    $start = $m >= 4 ? $y : $y - 1;
    return substr((string)$start, 2) . '-' . substr((string)($start + 1), 2);
}

/**
 * Does this table have this column?
 *
 * Shared hosting means the PHP files and the SQL migration are uploaded
 * by hand, minutes or days apart, in whichever order. Code that assumes
 * the migration ran first turns "I uploaded the new api/" into "nobody
 * can take an order", which is the worst possible failure for a shop.
 * Asking is cheap and cached for the request.
 */
function db_has_column(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $s = $conn->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $s->bind_param('ss', $table, $column);
        $s->execute();
        $found = (bool)$s->get_result()->fetch_row();
        $s->close();
    } catch (mysqli_sql_exception $e) {
        $found = false;
    }
    return $cache[$key] = $found;
}

function amount_in_words(float $amount): string {
    $amount = round($amount, 2);
    $rupees = (int)floor($amount);
    $paise  = (int)round(($amount - $rupees) * 100);

    $words = indian_number_words($rupees);
    $out = $words === '' ? 'Zero Rupees' : $words . ' Rupees';
    if ($paise > 0) $out .= ' and ' . indian_number_words($paise) . ' Paise';
    return $out . ' Only';
}

function indian_number_words(int $n): string {
    if ($n === 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $two = function (int $v) use ($ones, $tens): string {
        if ($v === 0)  return '';
        if ($v < 20)   return $ones[$v];
        return trim($tens[intdiv($v, 10)] . ' ' . $ones[$v % 10]);
    };
    $three = function (int $v) use ($two, $ones): string {
        $h = intdiv($v, 100); $r = $v % 100;
        $s = $h > 0 ? $ones[$h] . ' Hundred' : '';
        if ($r > 0) $s = trim($s . ($s !== '' ? ' and ' : '') . $two($r));
        return $s;
    };

    $parts = [];
    $crore = intdiv($n, 10000000); $n %= 10000000;
    $lakh  = intdiv($n, 100000);   $n %= 100000;
    $thou  = intdiv($n, 1000);     $n %= 1000;

    if ($crore > 0) $parts[] = $three($crore) . ' Crore';
    if ($lakh  > 0) $parts[] = $three($lakh)  . ' Lakh';
    if ($thou  > 0) $parts[] = $three($thou)  . ' Thousand';
    if ($n     > 0) $parts[] = $three($n);
    return implode(' ', $parts);
}

/**
 * UPI intent string for the bill QR. Returns null when no UPI id is
 * configured, so the app hides the QR rather than showing a dead one.
 */
function upi_intent(array $settings, string $ref, float $amount): ?string {
    $vpa = trim($settings['upi_id'] ?? '');
    if ($vpa === '') return null;
    $q = [
        'pa' => $vpa,
        'pn' => $settings['upi_name'] ?: $settings['business_name'],
        'tn' => 'MadeForU ' . $ref,
        'cu' => 'INR',
    ];
    if ($amount > 0.001) $q['am'] = number_format($amount, 2, '.', '');
    return 'upi://pay?' . http_build_query($q);
}