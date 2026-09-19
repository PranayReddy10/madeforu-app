<?php
// ── Database ───────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'u291217659_sale');
define('DB_PASS', 'YOUR_DB_PASSWORD_HERE');
define('DB_NAME', 'u291217659_sale');

// Flip to true while debugging, then set it back to false.
define('SHOW_ERRORS', false);

// ── WhatsApp ───────────────────────────────────────────────────────
// wa.me requires the country code. A bare 10-digit number will not resolve.
define('COUNTRY_CODE', '91');   // India

// ── Login throttle ─────────────────────────────────────────────────
define('MAX_ATTEMPTS',  5);   // failures allowed
define('LOCKOUT_MINS', 15);   // ...within this window

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Database connection failed. Check DB_USER / DB_PASS / DB_NAME in config.php.');
}

// Catch anything that escapes a page and show it, rather than a bare 500.
set_exception_handler(function (Throwable $t) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    if (SHOW_ERRORS) {
        echo "Error: {$t->getMessage()}\n";
        echo "File : {$t->getFile()} line {$t->getLine()}\n";
    } else {
        echo "Something went wrong. Set SHOW_ERRORS to true in config.php to see details.";
    }
});

// ── Catalog ────────────────────────────────────────────────────────
// The original hardcoded list. Now used only as a seed and as a fallback
// if the products table does not exist yet (e.g. before the migration runs).
$DEFAULT_ITEMS = [
    'Square Magnet'   => 50,
    'Round Magnet'    => 50,
    'MDF Magnet'      => 100,
    'Acrylic Magnet'  => 100,
    'Bottle'          => 350,
    'Cup'             => 250,
    'Key Chain'       => 50,
];

/**
 * Live catalogue: active products from the DB as name => price.
 * Falls back to the hardcoded defaults if the table is missing, so the app
 * keeps working in the gap between uploading this file and running the
 * migration. Prices are ALWAYS read here server-side, never from a form.
 */
function load_items(mysqli $conn, array $fallback): array {
    try {
        $res = $conn->query('SELECT name, price FROM products WHERE is_active = 1 ORDER BY sort_order, name');
        if (!$res) return $fallback;
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[$row['name']] = (float)$row['price'];
        }
        return $items ?: $fallback;
    } catch (mysqli_sql_exception $e) {
        return $fallback;   // table not created yet
    }
}

$ITEMS = load_items($conn, $DEFAULT_ITEMS);

$QTY_OPTIONS   = range(1, 20);
$PAYMENT_MODES = ['cash'=>'Cash', 'upi'=>'UPI', 'card'=>'Card', 'other'=>'Other'];

// ── Session ────────────────────────────────────────────────────────
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// ── Helpers ────────────────────────────────────────────────────────
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Normalise an Indian mobile to 10 digits.
 * Strips non-digits, drops a leading country code (91) or 0, keeps the
 * last 10. A genuine 10-digit number is returned unchanged. Mirrors the
 * JavaScript in the forms, so a value that bypasses the browser is still
 * cleaned before validation.
 */
function normalise_phone(string $raw): string {
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) > 10 && strpos($d, '91') === 0) $d = substr($d, 2);
    $d = ltrim($d, '0');
    if (strlen($d) > 10) $d = substr($d, -10);
    return $d;
}
/**
 * ₹1,98,014.16 — Indian grouping, minus before the symbol, matching the
 * Android app and the web app exactly. The old one-liner was
 *
 *     function money($v) { return '₹' . number_format((float)$v, 2); }
 *
 * which gives ₹198,014.16 and ₹-157,378.29: the same figures, grouped
 * the Western way and with the sign on the wrong side, so the website and
 * the apps read differently. Replace the line in your config.php with
 * this. Do not delete it — every page uses money().
 */
function money($v) {
    $n   = (float)$v;
    $abs = number_format(abs($n), 2, '.', '');
    [$whole, $frac] = explode('.', $abs);
    if (strlen($whole) > 3) {
        $last3 = substr($whole, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3));
        $whole = $rest . ',' . $last3;
    }
    return ($n < 0 ? '-₹' : '₹') . $whole . '.' . $frac;
}

/** Fetch item => unit_cost, seeding any missing catalogue items at 0. */
function product_costs(mysqli $conn, array $items): array {
    $costs = [];
    $res = $conn->query('SELECT item, unit_cost FROM product_costs');
    while ($row = $res->fetch_assoc()) {
        $costs[$row['item']] = (float)$row['unit_cost'];
    }
    // Any catalogue item without a cost row defaults to 0.
    foreach ($items as $name => $_price) {
        if (!isset($costs[$name])) $costs[$name] = 0.0;
    }
    return $costs;
}

/**
 * Build a wa.me link for a phone number.
 * Strips non-digits, then prefixes the country code unless one is present.
 * Returns null when the number is unusable, so callers can hide the button.
 */
function whatsapp_link(string $phone, string $message = ''): ?string {
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return null;

    // Strip a leading 0 (common in locally-written Indian numbers).
    $digits = ltrim($digits, '0');

    // 10 digits means no country code yet. Longer means it probably has one.
    if (strlen($digits) === 10) $digits = COUNTRY_CODE . $digits;
    if (strlen($digits) < 11)   return null;

    $url = 'https://wa.me/' . $digits;
    if ($message !== '') $url .= '?text=' . rawurlencode($message);
    return $url;
}

/**
 * Public Delhivery tracking URL for an AWB.
 * Returns null when there is no usable AWB, so callers can hide the button
 * rather than link to a broken page.
 */
function delhivery_link(?string $awb): ?string {
    $awb = trim((string)$awb);
    if ($awb === '') return null;
    return 'https://www.delhivery.com/track/package/' . rawurlencode($awb);
}

/**
 * Is this order shipped? Having an AWB is the single source of truth for
 * "this is an online delivery order" -- there is no separate flag to keep
 * in sync. Only direct orders (event_id IS NULL) ever get one.
 */
function is_dispatched(array $order): bool {
    return trim((string)($order['awb'] ?? '')) !== '';
}

/** Prefilled WhatsApp message telling a customer their parcel has shipped. */
function dispatch_message(array $order): string {
    $link  = delhivery_link($order['awb'] ?? null);
    $lines = [];
    $lines[] = 'Hi ' . $order['name'] . ', your MadeForU order '
             . $order['order_no'] . ' has been shipped via Delhivery.';
    $lines[] = '';
    $lines[] = 'Tracking number: ' . $order['awb'];
    if ($link) $lines[] = 'Track here: ' . $link;

    $balance = max((float)$order['total'] - (float)$order['paid_amount'], 0);
    if ($balance > 0.001) {
        $lines[] = '';
        $lines[] = 'Balance due on delivery: ' . money($balance);
    }
    $lines[] = '';
    $lines[] = 'Thank you for shopping with us!';
    return implode("\n", $lines);
}

/** Prefilled message summarising an order. */
function whatsapp_message(array $order, string $items = ''): string {
    $total   = (float)$order['total'];
    $paid    = (float)$order['paid_amount'];
    $balance = max($total - $paid, 0);

    $lines = [];
    $lines[] = 'Hello ' . $order['name'] . ',';
    $lines[] = '';
    $lines[] = 'Order ' . $order['order_no'];
    if ($items !== '') $lines[] = $items;

    $disc  = (float)($order['discount'] ?? 0);
    $extra = (float)($order['extra_charge'] ?? 0);
    // Only break the total down when something modifies it, otherwise the
    // subtotal line just repeats the total.
    if ($disc > 0.001 || $extra > 0.001) {
        $lines[] = 'Subtotal: ' . money($order['subtotal']);
        if ($extra > 0.001) {
            $label = trim((string)($order['extra_charge_reason'] ?? ''));
            $lines[] = ($label !== '' ? $label : 'Additional charges') . ': +' . money($extra);
        }
        if ($disc > 0.001) $lines[] = 'Discount: -' . money($disc);
    }
    $lines[] = 'Total: ' . money($total);

    if ($balance > 0.001) {
        $lines[] = 'Paid: ' . money($paid);
        $lines[] = 'Balance due: ' . money($balance);
    } else {
        $lines[] = 'Fully paid.';
    }

    $lines[] = '';
    if ((int)$order['is_delivered'] === 1)  $lines[] = 'Your order has been handed over. Thank you!';
    elseif ((int)$order['is_ready'] === 1)  $lines[] = 'Your order is ready for collection.';
    else                                    $lines[] = 'We will let you know once it is ready.';

    // wa.me needs real newlines; rawurlencode handles the escaping.
    return implode("\n", $lines);
}

/** Status is derived from the numbers, never stored. */
function pay_status(float $total, float $paid): string {
    // A fully-discounted order owes nothing. Check this first, or it would
    // fall through to 'unpaid' and sit in the unpaid list forever.
    if ($total <= 0.001)         return 'paid';
    if ($paid  <= 0.001)         return 'unpaid';
    if ($paid  >= $total - 0.001) return 'paid';
    return 'partial';
}

/**
 * Clamp a discount to the charged base (subtotal + extra charge). A discount
 * larger than the order would make the total negative, and a negative total
 * breaks every comparison downstream.
 *
 * The ceiling includes the extra charge, not just the subtotal: a delivery
 * fee is money the customer owes, so it must be discountable like any other
 * part of the bill. $extra defaults to 0 so existing two-arg callers keep
 * their old behaviour.
 */
function clamp_discount(float $subtotal, float $discount, float $extra = 0.0): float {
    if ($discount < 0) return 0.0;
    return min($discount, max($subtotal + $extra, 0.0));
}

/** Additional charges are never negative. That would be a discount. */
function clamp_extra_charge(float $extra): float {
    return $extra < 0 ? 0.0 : round($extra, 2);
}

/**
 * Delivered implies ready. Unticking ready cancels delivery.
 * The DB has a CHECK constraint too, but older MySQL ignores CHECK,
 * so this is the guarantee we actually rely on.
 */
function normalise_status(int $ready, int $delivered): array {
    if ($delivered === 1) $ready = 1;
    if ($ready === 0)     $delivered = 0;
    return [$ready, $delivered];
}

function generate_order_no(mysqli $conn): string {
    // Derive the next number from the HIGHEST order_no used today, not COUNT(*).
    // COUNT+1 breaks two ways: two orders created at once get the same number
    // (a race), and deleting an order makes the count reuse an existing number.
    // Reading MAX(order_no) avoids both. The caller still retries on the rare
    // race where two requests read the same MAX before either inserts.
    $prefix = 'ORD' . date('ymd');
    $stmt = $conn->prepare(
        "SELECT MAX(CAST(SUBSTRING(order_no, ?) AS UNSIGNED)) mx
         FROM orders WHERE order_no LIKE ?"
    );
    $start = strlen($prefix) + 1;          // 1-based position after the prefix
    $like  = $prefix . '%';
    $stmt->bind_param('is', $start, $like);
    $stmt->execute();
    $mx = (int)($stmt->get_result()->fetch_assoc()['mx'] ?? 0);
    $stmt->close();

    return $prefix . str_pad((string)($mx + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * Recompute subtotal from the line items, then
 * total = subtotal + extra_charge - discount.
 *
 * extra_charge is money the customer owes on top of the goods (delivery,
 * rush fee, packing), so it is added before the discount comes off. That
 * ordering matters: it lets a discount cancel a delivery fee, which is the
 * common real case ("free delivery over 1000").
 *
 * GREATEST(...,0) guards against a stored discount that somehow exceeds the
 * new base, e.g. after products are removed from an existing order.
 */
function recalc_total(mysqli $conn, int $orderId): void {
    $s = $conn->prepare(
        'UPDATE orders o SET
           o.subtotal = (SELECT COALESCE(SUM(line_total),0) FROM order_items WHERE order_id = ?)
         WHERE o.id = ?'
    );
    $s->bind_param('ii', $orderId, $orderId);
    $s->execute();
    $s->close();

    // A negative charge would be a back-door discount that skips the
    // "cannot go below amount paid" guard. Floor it here as a backstop.
    $s = $conn->prepare('UPDATE orders SET extra_charge = GREATEST(extra_charge, 0) WHERE id = ?');
    $s->bind_param('i', $orderId);
    $s->execute();
    $s->close();

    // Clamp the discount to the charged base, then derive the total. Separate
    // steps, because MySQL cannot read a column it is writing in the same
    // statement.
    $s = $conn->prepare(
        'UPDATE orders SET discount = LEAST(discount, subtotal + extra_charge) WHERE id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $s->close();

    $s = $conn->prepare(
        'UPDATE orders SET total = GREATEST(subtotal + extra_charge - discount, 0) WHERE id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $s->close();
}

function recalc_paid(mysqli $conn, int $orderId): void {
    $s = $conn->prepare('UPDATE orders o SET o.paid_amount =
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ?) WHERE o.id = ?');
    $s->bind_param('ii', $orderId, $orderId);
    $s->execute();
    $s->close();
}

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; return null; }
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}

// ── CSRF ───────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    // hash_equals is timing-safe; == is not.
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(403);
        die('Invalid security token. Go back, refresh the page and try again.');
    }
}

// ── Auth ───────────────────────────────────────────────────────────
function current_admin(): ?array {
    return $_SESSION['admin'] ?? null;
}

/** Put this at the top of every protected page. */
function require_login(): array {
    start_session();
    $a = current_admin();
    if (!$a) {
        $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php');
        exit;
    }
    return $a;
}

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/** Recent failures for this phone+IP inside the lockout window. */
function failed_attempts(mysqli $conn, string $phone, string $ip): int {
    $s = $conn->prepare(
        'SELECT COUNT(*) c FROM login_attempts
         WHERE phone = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $mins = LOCKOUT_MINS;
    $s->bind_param('ssi', $phone, $ip, $mins);
    $s->execute();
    $c = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();
    return $c;
}

function record_attempt(mysqli $conn, string $phone, string $ip): void {
    $s = $conn->prepare('INSERT INTO login_attempts (phone, ip) VALUES (?,?)');
    $s->bind_param('ss', $phone, $ip);
    $s->execute();
    $s->close();
}

function clear_attempts(mysqli $conn, string $phone, string $ip): void {
    $s = $conn->prepare('DELETE FROM login_attempts WHERE phone = ? AND ip = ?');
    $s->bind_param('ss', $phone, $ip);
    $s->execute();
    $s->close();
}