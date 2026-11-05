<?php
/**
 * Is the live database missing anything the code needs?
 *
 * db_schema.sql lists every table and column the panel uses. On every page
 * layout.php asks schema_missing(); when a table or column is absent (a new
 * feature uploaded, a migration never run) it shows an "Update database"
 * button, and db_update.php adds exactly what is missing. Nothing is ever
 * dropped or changed, and no data is touched.
 */
declare(strict_types=1);

/**
 * Tables from db_schema.sql: name => ['create' => SQL, 'columns' => [name => definition]].
 */
function schema_expected(): array {
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    $sql = @file_get_contents(__DIR__ . '/db_schema.sql');
    if ($sql === false) return $out;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\n\)[^;]*;/s', $sql, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        $cols = [];
        foreach (explode("\n", $t[2]) as $line) {
            if (preg_match('/^\s*`(\w+)`\s+(.+?),?\s*$/', $line, $c)) $cols[$c[1]] = $c[2];
        }
        $out[$t[1]] = ['create' => trim($t[0]), 'columns' => $cols];
    }
    return $out;
}

/** ['tables' => [names], 'columns' => [[table, column, definition]]] — empty lists when all is well. */
function schema_missing(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $have = [];
    try {
        $r = $conn->query('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        while ($row = $r->fetch_assoc()) $have[strtolower($row['t'])][strtolower($row['c'])] = true;
    } catch (mysqli_sql_exception $e) {
        return $cache = ['tables' => [], 'columns' => []];   // can't tell — don't nag
    }
    $tables = $columns = [];
    foreach (schema_expected() as $name => $t) {
        if (!isset($have[strtolower($name)])) { $tables[] = $name; continue; }
        foreach ($t['columns'] as $col => $def) {
            if (!isset($have[strtolower($name)][strtolower($col)])) $columns[] = [$name, $col, $def];
        }
    }
    return $cache = ['tables' => $tables, 'columns' => $columns];
}

function schema_needs_update(mysqli $conn): bool {
    $m = schema_missing($conn);
    return $m['tables'] || $m['columns'];
}

/** Add what is missing. Returns ['done' => [..], 'failed' => [..]] in plain words. */
function schema_update(mysqli $conn): array {
    $missing = schema_missing($conn);
    $expected = schema_expected();
    $done = $failed = [];
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($missing['tables'] as $name) {
            $sql = $expected[$name]['create'];
            try {
                $conn->query($sql);
                $done[] = 'table ' . $name;
            } catch (mysqli_sql_exception $e) {
                // A link to another table can fail on an older copy; the table itself matters more.
                $plain = preg_replace('/,\s*\n\s*CONSTRAINT [^\n]*(?=\n)/', '', $sql);
                try {
                    $conn->query($plain);
                    $done[] = 'table ' . $name . ' (without its links)';
                } catch (mysqli_sql_exception $e2) {
                    $failed[] = 'table ' . $name . ': ' . $e2->getMessage();
                }
            }
        }
        foreach ($missing['columns'] as [$table, $col, $def]) {
            // An existing table already has its id; never add a second auto-number.
            $def = preg_replace('/\s+AUTO_INCREMENT\b/i', '', $def);
            try {
                $conn->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $def);
                $done[] = 'column ' . $table . '.' . $col;
            } catch (mysqli_sql_exception $e) {
                $failed[] = 'column ' . $table . '.' . $col . ': ' . $e->getMessage();
            }
        }
    } finally {
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
    return ['done' => $done, 'failed' => $failed];
}
