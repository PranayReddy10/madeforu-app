<?php
/**
 * Shared by the website's Expenses page and api/expenses.php, so a
 * receipt or a line item is handled the same way whichever one saved it.
 */

// ── Receipts ───────────────────────────────────────────────────────
// Bills are stored OUTSIDE anything the browser can reach directly. The dir
// carries a .htaccess that turns off script execution, and files are only
// ever served back through receipt.php, which is behind require_login().
if (!defined('RECEIPT_DIR')) define('RECEIPT_DIR', __DIR__ . '/uploads/receipts');
const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
// Real content types we accept, mapped to the extension we save under. We
// trust finfo (which reads the file's bytes), never the uploaded filename.
const RECEIPT_ALLOWED = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

/** Make sure the upload dir exists and cannot execute scripts. */
function ensure_receipt_dir(): void {
    if (!is_dir(RECEIPT_DIR)) {
        @mkdir(RECEIPT_DIR, 0755, true);
    }
    $ht = RECEIPT_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "php_flag engine off\n" .
            "<FilesMatch \"\\.(php|phtml|phar|phps|cgi|pl|py|sh)$\">\n" .
            "  Require all denied\n" .
            "</FilesMatch>\n"
        );
    }
}

/**
 * Validate and store one uploaded receipt. Returns the stored basename.
 * Throws on anything suspicious. The caller records the basename in the DB;
 * the file itself never keeps the user's original name.
 */
function store_receipt(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        // Translate the common upload errors into something readable.
        $map = [
            UPLOAD_ERR_INI_SIZE  => 'The file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was chosen.',
        ];
        throw new Exception($map[$file['error']] ?? 'The upload failed. Try again.');
    }
    if (($file['size'] ?? 0) > RECEIPT_MAX_BYTES) {
        throw new Exception('Receipt is larger than 5 MB. Please upload a smaller image or PDF.');
    }
    // is_uploaded_file guards against a path being smuggled in via the array.
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Invalid upload.');
    }
    // Determine the REAL type from the bytes, not the sent name or type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: '';
    if (!isset(RECEIPT_ALLOWED[$mime])) {
        throw new Exception('Only JPEG, PNG, WebP images or PDF files are accepted.');
    }
    $ext = RECEIPT_ALLOWED[$mime];

    ensure_receipt_dir();
    // Random, unguessable name. Never derived from user input.
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = RECEIPT_DIR . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new Exception('Could not save the receipt. Try again.');
    }
    @chmod($dest, 0644);
    return $name;
}

/** Delete a stored receipt file. Safe against path tricks in the stored value. */
function delete_receipt_file(?string $name): void {
    if (!$name) return;
    // basename() strips any directory component, so a tampered DB value can
    // never point unlink() outside the receipts dir.
    $path = RECEIPT_DIR . '/' . basename($name);
    if (is_file($path)) @unlink($path);
}

/** Fetch the current stored receipt name for an expense, or null. */
function receipt_of(mysqli $conn, int $expenseId): ?string {
    $s = $conn->prepare('SELECT receipt_path FROM expenses WHERE id = ?');
    $s->bind_param('i', $expenseId); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    return $row && $row['receipt_path'] !== null && $row['receipt_path'] !== ''
        ? $row['receipt_path'] : null;
}

// ── Line items ─────────────────────────────────────────────────────

/**
 * GST on a line is optional: a percentage (5, 12, 18, 28…) on top of
 * qty × unit cost. Added to expense_items the first time this page runs
 * on a database that does not have it yet, so nothing has to be run by
 * hand (api/migrations/2026-10-expense-gst.sql has the same statement).
 */
function ensure_gst_column(mysqli $conn): void {
    $has = $conn->query("SHOW COLUMNS FROM expense_items LIKE 'gst_pct'");
    if ($has && $has->num_rows === 0) {
        $conn->query('ALTER TABLE expense_items ADD COLUMN gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_cost');
    }
}

/** qty × unit cost, plus GST % on top; GST left blank is 0. */
function expense_line_total(float $qty, float $unit, float $gstPct): float {
    return round($qty * $unit * (1 + $gstPct / 100), 2);
}
