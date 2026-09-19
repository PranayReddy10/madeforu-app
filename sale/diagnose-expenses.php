<?php
// ── diagnose-expenses.php ──────────────────────────────────────────
// Temporary. Pinpoints the 500 on expenses.php. DELETE AFTER USE.
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php';
echo "config loaded, DB connected\n";
echo str_repeat('-', 60) . "\n";

function check(mysqli $conn, string $label, string $sql): void {
    echo $label . ": ";
    if ($res = $conn->query($sql)) {
        echo "OK";
        if ($res instanceof mysqli_result) echo " (" . $res->num_rows . " rows)";
        echo "\n";
    } else {
        echo "FAILED -> " . $conn->error . "\n";
    }
}

// 1. Do the new tables exist?
echo "[tables]\n";
foreach (['expenses','expense_items','expense_payments','partners','expense_categories','account_movements'] as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    echo "  $t: " . ($r && $r->num_rows ? "exists\n" : "MISSING\n");
}
echo "\n";

// 2. Does expenses.discount column exist?
echo "[columns on expenses]\n";
$cols = [];
if ($r = $conn->query("SHOW COLUMNS FROM expenses")) {
    while ($c = $r->fetch_assoc()) { $cols[] = $c['Field']; echo "  " . $c['Field'] . "\n"; }
}
echo "  -> discount present: " . (in_array('discount', $cols, true) ? "YES\n" : "NO  <-- likely the problem\n");
echo "\n";

// 3. Columns on account_movements (kind/counterparty/transfer_id)
echo "[columns on account_movements]\n";
$mc = [];
if ($r = $conn->query("SHOW COLUMNS FROM account_movements")) {
    while ($c = $r->fetch_assoc()) $mc[] = $c['Field'];
}
foreach (['kind','counterparty_id','transfer_id','event_id'] as $need) {
    echo "  $need: " . (in_array($need, $mc, true) ? "present\n" : "MISSING\n");
}
echo "\n";

// 4. Run the exact load query expenses.php uses.
echo "[main load query]\n";
$sql = 'SELECT x.*,
               (SELECT COUNT(*) FROM expense_items WHERE expense_id = x.id) AS line_count,
               (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_id = x.id) AS paid_sum
        FROM expenses x ORDER BY x.exp_date DESC, x.id DESC';
check($conn, "  query", $sql);

// 5. Payment shares join
check($conn, "  payments join", "SELECT ep.*, p.name AS partner_name FROM expense_payments ep LEFT JOIN partners p ON p.id = ep.partner_id LIMIT 1");

echo "\nDONE. If everything says OK/present, tell me and I'll look deeper.\n";
echo "If discount says NO or a table is MISSING, the migration did not fully apply.\n";
