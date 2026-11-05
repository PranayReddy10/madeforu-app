<?php
/** The "Update database" button on every page (see lib_schema.php). */
require 'config.php';
require_once __DIR__ . '/lib_schema.php';
$me = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$r = schema_update($conn);
if ($r['failed']) {
    flash('Database updated partly. Added: ' . ($r['done'] ? implode(', ', $r['done']) : 'nothing')
        . '. Could not add: ' . implode('; ', $r['failed']), 'error');
} else {
    flash($r['done'] ? 'Database updated — added ' . implode(', ', $r['done']) . '.' : 'Database was already up to date.');
}

// Back to the page the button was pressed on (same site only).
$back = (string)($_POST['back'] ?? '');
header('Location: ' . (preg_match('#^[a-z0-9_\-]+\.php(\?[^\s]*)?$#i', $back) ? $back : 'index.php'));
exit;
