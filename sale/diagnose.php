<?php
// ── diagnose.php ───────────────────────────────────────────────────
// Temporary. Shows the real error behind a 500. DELETE AFTER USE.
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

echo "PHP version : " . PHP_VERSION . "\n";
echo "Required    : 7.4 or newer\n";
echo str_repeat('-', 60) . "\n\n";

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    echo "!! PHP IS TOO OLD. Raise it in hPanel > Advanced > PHP Configuration.\n\n";
}

// 1. Extensions
echo "[1] mysqli extension: " . (extension_loaded('mysqli') ? "OK\n" : "MISSING\n");
echo "    session support : " . (function_exists('session_start') ? "OK\n" : "MISSING\n");
echo "    password_hash   : " . (function_exists('password_hash') ? "OK\n" : "MISSING\n");
echo "\n";

// 2. Config file
echo "[2] config.php: ";
if (!file_exists(__DIR__ . '/config.php')) { echo "NOT FOUND\n"; exit; }
echo "found\n";

try {
    require __DIR__ . '/config.php';
    echo "    loaded OK\n";
} catch (Throwable $t) {
    echo "    FATAL: " . $t->getMessage() . "\n";
    echo "    at " . $t->getFile() . " line " . $t->getLine() . "\n";
    exit;
}
echo "\n";

// 3. Connection
echo "[3] Database connection: ";
echo isset($conn) && $conn instanceof mysqli ? "OK\n" : "FAILED\n";
echo "    server: " . ($conn->server_info ?? '?') . "\n\n";

// 4. Tables
echo "[4] Tables:\n";
foreach (['admins','login_attempts','orders','order_items','payments'] as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    printf("    %-16s %s\n", $t, $r->num_rows ? 'present' : 'MISSING');
}
echo "\n";

// 5. The stats query — the most likely culprit.
echo "[5] Stats query:\n";
try {
    $q = $conn->query("
      SELECT COUNT(*) AS total_orders,
        COALESCE(SUM(total),0)                                   AS total_value,
        COALESCE(SUM(paid_amount),0)                             AS collected,
        COALESCE(SUM(GREATEST(total - paid_amount,0)),0)         AS outstanding,
        COALESCE(SUM(paid_amount <= 0),0)                        AS unpaid_count,
        COALESCE(SUM(paid_amount > 0 AND paid_amount < total),0) AS partial_count,
        COALESCE(SUM(paid_amount >= total AND total > 0),0)      AS paid_count,
        COALESCE(SUM(is_ready = 0),0)                            AS not_ready,
        COALESCE(SUM(is_ready = 1 AND is_delivered = 0),0)       AS ready_undelivered
      FROM orders");
    echo "    OK\n";
    print_r($q->fetch_assoc());
} catch (Throwable $t) {
    echo "    FAILED: " . $t->getMessage() . "\n";
}
echo "\n";

// 6. The main orders query, with GROUP_CONCAT.
echo "[6] Orders query:\n";
try {
    $sql = 'SELECT o.*, a.name AS admin_name,
                   GROUP_CONCAT(CONCAT(i.item, " x", i.quantity) SEPARATOR ", ") AS item_list
            FROM orders o
            LEFT JOIN order_items i ON i.order_id = o.id
            LEFT JOIN admins a      ON a.id = o.created_by
            GROUP BY o.id ORDER BY o.id DESC';
    $st = $conn->prepare($sql);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    echo "    OK — " . count($rows) . " row(s)\n";
    $st->close();
} catch (Throwable $t) {
    echo "    FAILED: " . $t->getMessage() . "\n";
    echo "    (If this mentions ONLY_FULL_GROUP_BY, that is the bug.)\n";
}
echo "\n";

// 7. sql_mode — ONLY_FULL_GROUP_BY breaks `SELECT o.* ... GROUP BY o.id`
echo "[7] sql_mode:\n";
$m = $conn->query("SELECT @@sql_mode AS m")->fetch_assoc()['m'];
echo "    $m\n";
echo "    ONLY_FULL_GROUP_BY: " . (strpos($m, 'ONLY_FULL_GROUP_BY') !== false
    ? "ENABLED  <-- this is very likely your 500\n" : "off\n");
echo "\n";

// 8. Admin count
echo "[8] Admins: ";
echo (int)$conn->query('SELECT COUNT(*) c FROM admins')->fetch_assoc()['c'] . "\n\n";

echo str_repeat('-', 60) . "\n";
echo "Done. Send this output back, then DELETE this file.\n";
