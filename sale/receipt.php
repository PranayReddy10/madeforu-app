<?php
/**
 * receipt.php — serves an expense's receipt file, but ONLY to a logged-in
 * admin. Files live outside anything the browser can fetch directly; this is
 * the only route to them. The stored name is run through basename() so a
 * tampered DB value can never escape the receipts directory.
 */
require 'config.php';
require_login();

define('RECEIPT_DIR', __DIR__ . '/uploads/receipts');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(400); exit('Bad request.'); }

$s = $conn->prepare('SELECT receipt_path FROM expenses WHERE id = ?');
$s->bind_param('i', $id);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();

$name = $row['receipt_path'] ?? '';
if ($name === '' || $name === null) { http_response_code(404); exit('No receipt on this expense.'); }

// basename() strips any directory component -> cannot traverse out of the dir.
$path = RECEIPT_DIR . '/' . basename($name);
if (!is_file($path)) { http_response_code(404); exit('Receipt file missing.'); }

// Derive a safe content type from the extension we saved under.
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];
$mime = $types[$ext] ?? 'application/octet-stream';

// Inline so images/PDFs open in the tab; never as an attachment surprise.
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="receipt-' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($path);
exit;