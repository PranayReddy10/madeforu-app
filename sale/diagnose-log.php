<?php
// ── diagnose-log.php ───────────────────────────────────────────────
// Finds and prints the most recent PHP errors. DELETE AFTER USE.
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP version: " . PHP_VERSION . "\n";
echo str_repeat('-', 60) . "\n";

// 1. Where does PHP log errors?
echo "error_log setting: " . (ini_get('error_log') ?: "(none set)") . "\n\n";

// 2. Look for common Hostinger error-log locations.
$candidates = [
    __DIR__ . '/error_log',
    __DIR__ . '/../error_log',
    ini_get('error_log'),
    $_SERVER['DOCUMENT_ROOT'] . '/error_log',
];
$found = false;
foreach (array_unique(array_filter($candidates)) as $path) {
    if (@is_file($path) && @is_readable($path)) {
        $found = true;
        echo "=== Last 40 lines of: $path ===\n";
        $lines = @file($path);
        if ($lines) {
            foreach (array_slice($lines, -40) as $l) echo $l;
        }
        echo "\n";
    }
}
if (!$found) echo "No readable error_log file found in the usual spots.\n\n";

// 3. Directly trigger the same includes expenses.php uses and trap output,
//    so if the fatal is at include time we see it here with a message.
echo str_repeat('-', 60) . "\n";
echo "Attempting a controlled load of the page's core (no login redirect)...\n\n";

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n>>> FATAL <<<\n" . $e['message'] . "\nat " . $e['file'] . " line " . $e['line'] . "\n";
    } else {
        echo "\n(No fatal captured in this controlled load.)\n";
    }
});

// Prevent the login redirect from ending our script: define a flag some
// setups check, and start a session so require_login has what it needs.
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/config.php';

// Re-run the exact heavy logic from expenses.php's load path in isolation,
// so a data-dependent error surfaces with a line reference.
$sql = 'SELECT x.*,
               (SELECT COUNT(*) FROM expense_items WHERE expense_id = x.id) AS line_count,
               (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_id = x.id) AS paid_sum
        FROM expenses x ORDER BY x.exp_date DESC, x.id DESC';
$stmt = $conn->prepare($sql);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
echo "loaded " . count($rows) . " expense rows\n";

$ids = array_map(fn($r)=>(int)$r['id'], $rows);
$in  = implode(',', array_fill(0, count($ids), '?'));
$tp  = str_repeat('i', count($ids));
$ps = $conn->prepare("SELECT ep.*, p.name AS partner_name FROM expense_payments ep
                      LEFT JOIN partners p ON p.id = ep.partner_id
                      WHERE ep.expense_id IN ($in) ORDER BY ep.expense_id, ep.id");
$ps->bind_param($tp, ...$ids);
$ps->execute();
$pr = $ps->get_result();
$payItems = [];
while ($row = $pr->fetch_assoc()) $payItems[(int)$row['expense_id']][] = $row;
$ps->close();
echo "loaded payment shares for " . count($payItems) . " expenses\n";

// Now exercise the exact per-row computations the template does.
function pay_status(float $netOwed, float $paid): array {
    if ($netOwed <= 0.001) return ['—', ''];
    if ($paid <= 0.001) return ['Unpaid', 'st-unpaid'];
    if ($netOwed - $paid > 0.01) return ['Partial', 'st-partial'];
    return ['Paid', 'st-paid'];
}
foreach ($rows as $r) {
    $gross = (float)$r['amount'];
    $disc  = (float)($r['discount'] ?? 0);
    $net   = round($gross - $disc, 2);
    $paid  = (float)$r['paid_sum'];
    [$a, $b] = pay_status($net, $paid);
    $d = date('d M Y', strtotime($r['exp_date']));
}
echo "per-row computations completed with no error\n";
echo "\nIf you see this line, the load path is clean — the error is elsewhere.\n";
