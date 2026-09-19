<?php
require 'config.php';
$me = require_login();

// ── Receipts ───────────────────────────────────────────────────────
// Bills are stored OUTSIDE anything the browser can reach directly. The dir
// carries a .htaccess that turns off script execution, and files are only
// ever served back through receipt.php, which is behind require_login().
define('RECEIPT_DIR', __DIR__ . '/uploads/receipts');
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
        throw new Exception('Only JP, PNG, WebP images or PDF files are accepted.');
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

function partner_map(mysqli $conn): array {
    $m = [];
    $res = $conn->query('SELECT id, name, is_active FROM partners ORDER BY is_active DESC, name');
    while ($r = $res->fetch_assoc()) $m[(int)$r['id']] = $r;
    return $m;
}
function category_list(mysqli $conn): array {
    $out = [];
    $res = $conn->query('SELECT name FROM expense_categories ORDER BY sort_order, name');
    while ($r = $res->fetch_assoc()) $out[] = $r['name'];
    return $out ?: ['Other'];
}

/** Posted line items -> [descr, qty, unit, line]; [] if none entered. */
function read_line_items(): array {
    $descr = $_POST['li_descr'] ?? [];
    $qty   = $_POST['li_qty']   ?? [];
    $unit  = $_POST['li_unit']  ?? [];
    if (!is_array($descr)) return [];
    $items = [];
    for ($i = 0, $n = count($descr); $i < $n; $i++) {
        $d = trim((string)($descr[$i] ?? ''));
        $q = round((float)($qty[$i]  ?? 0), 2);
        $u = round((float)($unit[$i] ?? 0), 2);
        if ($d === '' && $q == 0 && $u == 0) continue;
        if ($d === '') throw new Exception('Every line item needs a description.');
        if ($q <= 0)   throw new Exception('Line "' . $d . '" needs a quantity above zero.');
        if ($u < 0)    throw new Exception('Line "' . $d . '" has a negative unit cost.');
        $items[] = ['descr'=>$d, 'qty'=>$q, 'unit'=>$u, 'line'=>round($q * $u, 2)];
    }
    return $items;
}

/** Posted payment shares -> [[partner_id, amount], ...]; validates partners. */
function read_payments(array $partners): array {
    $pids = $_POST['pay_partner'] ?? [];
    $amts = $_POST['pay_amount']  ?? [];
    if (!is_array($pids)) return [];
    $out = [];
    for ($i = 0, $n = count($pids); $i < $n; $i++) {
        $pid = (int)($pids[$i] ?? 0);
        $amt = round((float)($amts[$i] ?? 0), 2);
        if ($pid === 0 && $amt == 0) continue;                 // empty row
        if (!isset($partners[$pid])) throw new Exception('Choose a valid partner for each payment share.');
        if ($amt <= 0) throw new Exception('Each payment share needs an amount above zero.');
        $out[] = ['pid'=>$pid, 'amt'=>$amt];
    }
    return $out;
}

function write_line_items(mysqli $conn, int $expenseId, array $items): void {
    $d = $conn->prepare('DELETE FROM expense_items WHERE expense_id = ?');
    $d->bind_param('i', $expenseId); $d->execute(); $d->close();
    if (!$items) return;
    $s = $conn->prepare('INSERT INTO expense_items (expense_id, descr, qty, unit_cost, line_total, sort_order) VALUES (?,?,?,?,?,?)');
    foreach ($items as $i => $it) {
        $s->bind_param('isdddi', $expenseId, $it['descr'], $it['qty'], $it['unit'], $it['line'], $i);
        $s->execute();
    }
    $s->close();
}

function write_payments(mysqli $conn, int $expenseId, array $pays, string $date): void {
    $d = $conn->prepare('DELETE FROM expense_payments WHERE expense_id = ?');
    $d->bind_param('i', $expenseId); $d->execute(); $d->close();
    if (!$pays) return;
    $s = $conn->prepare('INSERT INTO expense_payments (expense_id, partner_id, amount, pay_date) VALUES (?,?,?,?)');
    foreach ($pays as $p) {
        $s->bind_param('iids', $expenseId, $p['pid'], $p['amt'], $date);
        $s->execute();
    }
    $s->close();
}

$partners   = partner_map($conn);
$categories = category_list($conn);

// ── Actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $newReceipt = null;   // set if a file is stored; used for rollback cleanup
    try {
        if ($action === 'add' || $action === 'edit') {
            $date   = $_POST['exp_date'] ?? '';
            $item   = trim($_POST['item'] ?? '');
            $paidTo = trim($_POST['paid_to'] ?? '');
            $cat    = trim($_POST['category'] ?? 'Other');
            $det    = trim($_POST['details'] ?? '');
            $disc   = round((float)($_POST['discount'] ?? 0), 2);

            $lines  = read_line_items();
            if ($lines) {
                $gross = 0.0; foreach ($lines as $l) $gross += $l['line'];
                $gross = round($gross, 2);
            } else {
                $gross = round((float)($_POST['amount'] ?? 0), 2);
            }
            $pays   = read_payments($partners);
            $paid   = 0.0; foreach ($pays as $p) $paid += $p['amt']; $paid = round($paid, 2);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Pick a valid date.');
            if ($item === '')  throw new Exception('Item is required.');
            if ($gross <= 0)   throw new Exception('Enter a total amount, or add at least one breakdown line.');
            if ($disc < 0)     throw new Exception('Discount cannot be negative.');
            if ($disc > $gross) throw new Exception('Discount cannot be more than the gross amount.');

            $netOwed = round($gross - $disc, 2);
            // Overpaying beyond what is owed is almost always a typo.
            if ($paid - $netOwed > 0.01) {
                throw new Exception('Payments (' . money($paid) . ') exceed the net owed (' . money($netOwed) . '). Check the shares or the discount.');
            }
            // A "paid_by" for the legacy column: first payer, else 0-safe.
            $legacyPaidBy = $pays[0]['pid'] ?? 0;
            if ($legacyPaidBy === 0) {
                // No payer chosen yet (fully unpaid). Keep a partner for the NOT NULL
                // legacy column: use the first active partner as a neutral placeholder.
                foreach ($partners as $pid=>$pp) { if ($pp['is_active']) { $legacyPaidBy = (int)$pid; break; } }
                if ($legacyPaidBy === 0 && $partners) $legacyPaidBy = (int)array_key_first($partners);
            }
            if (!in_array($cat, $categories, true)) $cat = 'Other';

            // A receipt may ride along with the add/edit form. Validate BEFORE
            // the transaction so a bad file aborts without a half-written row.
            // ($newReceipt was initialised above so the catch block can clean up.)
            $hasUpload = isset($_FILES['receipt']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasUpload) {
                $newReceipt = store_receipt($_FILES['receipt']);   // throws on bad file
            }

            $conn->begin_transaction();
            if ($action === 'add') {
                $s = $conn->prepare('INSERT INTO expenses (exp_date, item, amount, discount, paid_by, paid_to, category, details) VALUES (?,?,?,?,?,?,?,?)');
                $s->bind_param('ssddisss', $date, $item, $gross, $disc, $legacyPaidBy, $paidTo, $cat, $det);
                $s->execute();
                $id = (int)$conn->insert_id;
                $s->close();
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $s = $conn->prepare('UPDATE expenses SET exp_date=?, item=?, amount=?, discount=?, paid_by=?, paid_to=?, category=?, details=? WHERE id=?');
                $s->bind_param('ssddisssi', $date, $item, $gross, $disc, $legacyPaidBy, $paidTo, $cat, $det, $id);
                $s->execute();
                $s->close();
            }
            write_line_items($conn, $id, $lines);
            write_payments($conn, $id, $pays, $date);

            // If a new receipt was uploaded, record it and remove the old file.
            if ($newReceipt !== null) {
                $oldReceipt = receipt_of($conn, $id);
                $s = $conn->prepare('UPDATE expenses SET receipt_path = ? WHERE id = ?');
                $s->bind_param('si', $newReceipt, $id);
                $s->execute(); $s->close();
                $conn->commit();
                delete_receipt_file($oldReceipt);   // after commit, so a rollback keeps the old file
            } else {
                $conn->commit();
            }

            $bal = round($netOwed - $paid, 2);
            $msg = $action === 'add' ? 'Expense added.' : 'Expense updated.';
            if ($bal > 0.01) $msg .= ' Balance still owed: ' . money($bal) . '.';
            flash($msg);

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $old = receipt_of($conn, $id);              // read before the row goes
            $s = $conn->prepare('DELETE FROM expenses WHERE id = ?');   // items + payments cascade
            $s->bind_param('i', $id); $s->execute(); $s->close();
            delete_receipt_file($old);                  // remove the bill too
            flash('Expense deleted.');

        } elseif ($action === 'upload_receipt') {
            // Attach or replace a receipt on an existing expense in one step.
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new Exception('Unknown expense.');
            if (!isset($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new Exception('Choose a file to upload.');
            }
            $newReceipt = store_receipt($_FILES['receipt']);   // throws on bad file
            $old = receipt_of($conn, $id);
            $s = $conn->prepare('UPDATE expenses SET receipt_path = ? WHERE id = ?');
            $s->bind_param('si', $newReceipt, $id);
            $s->execute(); $s->close();
            delete_receipt_file($old);
            flash('Receipt attached.');

        } elseif ($action === 'remove_receipt') {
            $id  = (int)($_POST['id'] ?? 0);
            $old = receipt_of($conn, $id);
            $s = $conn->prepare('UPDATE expenses SET receipt_path = NULL WHERE id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->close();
            delete_receipt_file($old);
            flash('Receipt removed.');
        }
    } catch (Exception $ex) {
        @$conn->rollback();
        // If we stored a file but the DB write failed, don't leave it orphaned.
        if (!empty($newReceipt)) delete_receipt_file($newReceipt);
        flash($ex->getMessage(), 'error');
    }
    $q = http_build_query(array_filter([
        'partner'  => $_POST['r_partner']  ?? '',
        'category' => $_POST['r_category'] ?? '',
        'from'     => $_POST['r_from']     ?? '',
        'to'       => $_POST['r_to']       ?? '',
    ], fn($v) => $v !== ''));
    header('Location: expenses.php' . ($q ? "?$q" : ''));
    exit;
}

// ── Filters ────────────────────────────────────────────────────────
$fPartner  = (int)($_GET['partner'] ?? 0);
$fCategory = trim($_GET['category'] ?? '');
$fFrom     = trim($_GET['from'] ?? '');
$fTo       = trim($_GET['to'] ?? '');

$where = []; $params = []; $types = '';
// Filter by partner = expenses this partner contributed a share to.
if ($fPartner > 0)   { $where[]='EXISTS (SELECT 1 FROM expense_payments ep WHERE ep.expense_id = x.id AND ep.partner_id = ?)'; $params[]=$fPartner; $types.='i'; }
if ($fCategory !== '') { $where[]='x.category = ?'; $params[]=$fCategory; $types.='s'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[]='x.exp_date >= ?'; $params[]=$fFrom; $types.='s'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   { $where[]='x.exp_date <= ?'; $params[]=$fTo;   $types.='s'; }

$sql = 'SELECT x.*,
               (SELECT COUNT(*) FROM expense_items WHERE expense_id = x.id) AS line_count,
               (SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE expense_id = x.id) AS paid_sum
        FROM expenses x';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY x.exp_date DESC, x.id DESC';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Bulk-load line items and payment shares for the visible expenses.
$lineItems = []; $payItems = [];
if ($rows) {
    $ids = array_map(fn($r)=>(int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $tp  = str_repeat('i', count($ids));

    $ls = $conn->prepare("SELECT * FROM expense_items WHERE expense_id IN ($in) ORDER BY expense_id, sort_order, id");
    $ls->bind_param($tp, ...$ids); $ls->execute();
    $lr = $ls->get_result();
    while ($row = $lr->fetch_assoc()) $lineItems[(int)$row['expense_id']][] = $row;
    $ls->close();

    $ps = $conn->prepare("SELECT ep.*, p.name AS partner_name FROM expense_payments ep
                          LEFT JOIN partners p ON p.id = ep.partner_id
                          WHERE ep.expense_id IN ($in) ORDER BY ep.expense_id, ep.id");
    $ps->bind_param($tp, ...$ids); $ps->execute();
    $pr = $ps->get_result();
    while ($row = $pr->fetch_assoc()) $payItems[(int)$row['expense_id']][] = $row;
    $ps->close();
}

// Totals across the visible list.
$sumGross = $sumDisc = $sumPaid = $sumBalance = 0.0;
foreach ($rows as $r) {
    $g = (float)$r['amount']; $d = (float)$r['discount']; $p = (float)$r['paid_sum'];
    $net = $g - $d; $bal = max(0, round($net - $p, 2));
    $sumGross += $g; $sumDisc += $d; $sumPaid += $p; $sumBalance += $bal;
}

$filterQ = array_filter(['partner'=>$fPartner ?: '', 'category'=>$fCategory, 'from'=>$fFrom, 'to'=>$fTo], fn($v)=>$v!=='' && $v!==0);
$flash = flash();

// Payment status label from numbers (never stored). Named distinctly to
// avoid clashing with config.php's pay_status() used by orders.
function expense_pay_status(float $netOwed, float $paid): array {
    if ($netOwed <= 0.001) return ['—', ''];
    if ($paid <= 0.001)                     return ['Unpaid', 'st-unpaid'];
    if ($netOwed - $paid > 0.01)            return ['Partial', 'st-partial'];
    return ['Paid', 'st-paid'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Expenses · Stall Orders</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f5f7;color:#1c1e21;padding:16px;line-height:1.5}
  .card{background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px;margin-bottom:16px}
  .card h2{font-size:16px;font-weight:600;margin-bottom:14px}
  label{display:block;font-size:13px;color:#65676b;margin-bottom:4px}
  input,select,textarea{width:100%;padding:9px 10px;border:1px solid #ccd0d5;border-radius:6px;font-size:14px;font-family:inherit}
  textarea{resize:vertical;min-height:38px}
  input:focus,select:focus,textarea:focus{outline:2px solid #1877f2;outline-offset:-1px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:14px}
  button,.btn{padding:9px 16px;border:1px solid #ccd0d5;border-radius:6px;background:#fff;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none;color:#1c1e21;display:inline-block}
  button:hover,.btn:hover{background:#f0f2f5}
  .primary{background:#1877f2;color:#fff;border-color:#1877f2}.primary:hover{background:#166fe5}
  .danger{color:#c0392b;border-color:#f0c0bb}.danger:hover{background:#fdeceb}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;padding:9px 8px;border-bottom:2px solid #dfe1e5;font-size:12px;text-transform:uppercase;color:#65676b;letter-spacing:.4px;white-space:nowrap}
  td{padding:10px 8px;border-bottom:1px solid #eceef0;vertical-align:top}
  tfoot td{border-top:2px solid #dfe1e5;font-weight:600}
  .r{text-align:right}.scroll{overflow-x:auto}
  .flash{padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:14px}
  .f-success{background:#e3f5eb;color:#1a7f4b;border:1px solid #b8e3ca}
  .f-error{background:#fdeceb;color:#c0392b;border:1px solid #f5c6c2}
  .acts{display:flex;gap:6px;flex-wrap:wrap}.acts button{padding:5px 10px;font-size:13px}
  .pill{display:inline-block;padding:2px 8px;border-radius:12px;background:#eef0f2;font-size:12px;color:#4b4f56}
  details{margin-top:8px}summary{cursor:pointer;font-size:13px;color:#1877f2}
  .muted{color:#8a8d91;font-size:13px}
  .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.filters>div{flex:1;min-width:130px}
  .li-tbl,.pay-tbl{width:100%;border-collapse:collapse;margin-top:6px}
  .li-tbl th,.pay-tbl th{font-size:11px;padding:4px 6px}
  .li-tbl td,.pay-tbl td{padding:4px 6px;border-bottom:1px solid #f0f2f5;vertical-align:middle}
  .li-tbl input,.pay-tbl input,.pay-tbl select{padding:7px 8px}
  .liTotal{font-variant-numeric:tabular-nums;font-size:14px;white-space:nowrap}
  .li-sum,.pay-sum{margin-top:8px;font-size:14px;font-weight:600}
  .li-x{color:#c0392b;border-color:#f0c0bb;padding:5px 9px !important}
  .li-break{font-size:12px;color:#65676b;margin-top:4px;white-space:pre-line}
  .st{display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:500}
  .st-paid{background:#e3f5eb;color:#1a7f4b}.st-partial{background:#fff4e0;color:#a06a00}.st-unpaid{background:#fdeceb;color:#c0392b}
  .money-note{font-size:13px;font-weight:600;margin-top:6px}
  .sect{margin-top:16px;padding-top:14px;border-top:1px solid #eceef0}
  .sect h3{font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#65676b;margin-bottom:8px}
  .payln{font-size:12px;color:#4b4f56}
  .rcpt{margin-top:6px}
  .rcpt a{color:#1877f2;text-decoration:none;font-size:13px}
  .rcpt a:hover{text-decoration:underline}
  .rcpt .thumb{max-width:56px;max-height:56px;border:1px solid #dfe1e5;border-radius:6px;vertical-align:middle;margin-right:6px}
  .rcpt-none{font-size:12px;color:#8a8d91}
  input[type=file]{width:100%;font-size:13px;color:#65676b;border:1px dashed #ccd0d5;border-radius:6px;background:#fbfcfd;padding:6px;cursor:pointer}
  input[type=file]::file-selector-button{
    margin-right:10px;padding:7px 14px;border:1px solid #1877f2;border-radius:6px;
    background:#1877f2;color:#fff;font-size:13px;font-family:inherit;cursor:pointer;
    transition:background .15s}
  input[type=file]::file-selector-button:hover{background:#166fe5}
  /* Older WebKit fallback for the same pseudo-element */
  input[type=file]::-webkit-file-upload-button{
    margin-right:10px;padding:7px 14px;border:1px solid #1877f2;border-radius:6px;
    background:#1877f2;color:#fff;font-size:13px;font-family:inherit;cursor:pointer}
</style>
</head>
<body>
<?php
  $PAGE  = 'expenses';
  $TITLE = 'Expenses';
  require 'layout.php';
?>

  <?php if ($flash): ?><div class="flash f-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add an expense</h2>
    <p class="muted" style="margin-bottom:12px">Record the purchase, an optional breakdown, any discount, and who paid (one or more partners, in full or in part).</p>
    <form method="post" enctype="multipart/form-data" onsubmit="return syncBeforeSubmit(this)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div class="grid">
        <div><label>Date</label><input type="date" name="exp_date" required value="<?= e(date('Y-m-d')) ?>"></div>
        <div><label>Item / purchase</label><input name="item" required maxlength="200" placeholder="e.g. Magnets Purchase"></div>
        <div><label>Paid to (vendor)</label><input name="paid_to" maxlength="200" placeholder="e.g. Print Valley"></div>
        <div><label>Category</label>
          <select name="category">
            <?php foreach ($categories as $c): ?><option <?= $c==='Other'?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sect">
        <h3>Breakdown (optional)</h3>
        <div class="scroll">
          <table class="li-tbl" data-li>
            <thead><tr><th style="width:44%">Description</th><th class="r" style="width:16%">Qty</th><th class="r" style="width:18%">Unit cost ₹</th><th class="r" style="width:16%">Line total</th><th style="width:6%"></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px">
          <button type="button" class="btn" onclick="addLine(this)">+ Add line</button>
          <div class="li-sum">Gross from lines: <span class="liGrand">₹0.00</span></div>
        </div>
      </div>

      <div class="grid" style="margin-top:14px">
        <div>
          <label>Gross amount (₹)</label>
          <input type="number" name="amount" step="0.01" min="0" class="amtField" placeholder="auto from lines if used" oninput="recalcMoney(this.form)">
          <p class="muted amtHint" style="font-size:12px">Fill only if not using the breakdown.</p>
        </div>
        <div>
          <label>Discount (₹)</label>
          <input type="number" name="discount" step="0.01" min="0" value="0" class="discField" oninput="recalcMoney(this.form)">
        </div>
        <div>
          <label>Net owed</label>
          <input type="text" class="netField" readonly value="₹0.00" style="background:#f0f2f5;font-weight:600">
        </div>
      </div>

      <div class="sect">
        <h3>Who paid (from pocket)</h3>
        <div class="scroll">
          <table class="pay-tbl" data-pay>
            <thead><tr><th style="width:55%">Partner</th><th class="r" style="width:35%">Amount ₹</th><th style="width:10%"></th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px">
          <button type="button" class="btn" onclick="addPay(this)">+ Add payer</button>
          <div class="pay-sum">Paid: <span class="payGrand">₹0.00</span> · <span class="balNote"></span></div>
        </div>
        <p class="muted" style="font-size:12px;margin-top:4px">Leave empty if nothing is paid yet (fully owed to vendor). Add multiple rows for a split payment. Pay less than the net owed for a partial payment.</p>
      </div>

      <label style="margin-top:6px">Details / notes</label>
      <textarea name="details" maxlength="500" placeholder="optional notes"></textarea>

      <label style="margin-top:10px">Receipt / bill (optional)</label>
      <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
      <p class="muted" style="font-size:12px;margin-top:4px">JPG, PNG, WebP or PDF, up to 5 MB. Visible to all logged-in partners.</p>

      <button type="submit" class="primary" style="margin-top:12px">Add expense</button>
    </form>
  </div>

  <div class="card">
    <h2>Filter</h2>
    <form method="get">
      <div class="filters">
        <div><label>Partner</label>
          <select name="partner">
            <option value="">All</option>
            <?php foreach ($partners as $id=>$p): ?><option value="<?= (int)$id ?>" <?= $fPartner===(int)$id?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label>Category</label>
          <select name="category">
            <option value="">All</option>
            <?php foreach ($categories as $c): ?><option <?= $fCategory===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div><label>From</label><input type="date" name="from" value="<?= e($fFrom) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= e($fTo) ?>"></div>
        <div style="flex:0"><button class="primary" type="submit">Apply</button> <a class="btn" href="expenses.php">Clear</a></div>
      </div>
    </form>
  </div>

  <div class="card">
    <h2><?= count($rows) ?> expense(s)</h2>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Date</th><th>Item</th><th>Paid by</th><th>Vendor</th><th>Category</th>
              <th class="r">Net owed</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="muted" style="padding:18px 8px">No expenses match.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
              $myLines = $lineItems[(int)$r['id']] ?? [];
              $myPays  = $payItems[(int)$r['id']] ?? [];
              $gross = (float)$r['amount']; $disc = (float)$r['discount'];
              $net = round($gross - $disc, 2);
              $paid = (float)$r['paid_sum'];
              $bal = max(0, round($net - $paid, 2));
              [$stLabel, $stClass] = expense_pay_status($net, $paid);
        ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($r['exp_date']))) ?></td>
            <td>
              <?= e($r['item']) ?>
              <?php if ($myLines): ?>
                <div class="li-break"><?php
                  $bits = [];
                  foreach ($myLines as $k => $li) {
                      $bits[] = ($k+1) . '. ' . $li['descr'] . ' — '
                              . rtrim(rtrim(number_format((float)$li['qty'],2,'.',''), '0'),'.') . ' × '
                              . money($li['unit_cost']) . ' = ' . money($li['line_total']);
                  }
                  echo e(implode("\n", $bits));
                ?></div>
              <?php endif; ?>
              <?php if ($r['details'] !== ''): ?><div class="muted" style="margin-top:2px"><?= e($r['details']) ?></div><?php endif; ?>
              <?php if ($disc > 0.001): ?><div class="muted" style="margin-top:2px">Gross <?= money($gross) ?> − discount <?= money($disc) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($myPays): ?>
                <?php foreach ($myPays as $pp): ?>
                  <div class="payln"><?= e($pp['partner_name'] ?? '—') ?>: <?= money($pp['amount']) ?></div>
                <?php endforeach; ?>
              <?php else: ?><span class="muted">— (unpaid)</span><?php endif; ?>
            </td>
            <td><?= $r['paid_to']!=='' ? e($r['paid_to']) : '<span class="muted">—</span>' ?></td>
            <td><span class="pill"><?= e($r['category']) ?></span></td>
            <td class="r"><?= money($net) ?></td>
            <td class="r"><?= money($paid) ?></td>
            <td class="r"><?= $bal>0.001 ? money($bal) : '<span class="muted">—</span>' ?></td>
            <td><?php if($stLabel!=='—'): ?><span class="st <?= $stClass ?>"><?= $stLabel ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td>
              <div class="acts">
                <form method="post" onsubmit="return confirm('Delete this expense?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                  <button class="danger">Delete</button>
                </form>
              </div>

              <?php
                $rcpt = ($r['receipt_path'] ?? '') !== '' ? $r['receipt_path'] : null;
                $isImg = $rcpt && preg_match('/\.(jpg|png|webp)$/i', $rcpt);
              ?>
              <div class="rcpt">
                <?php if ($rcpt): ?>
                  <a href="receipt.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">
                    <?php if ($isImg): ?>
                      <img class="thumb" src="receipt.php?id=<?= (int)$r['id'] ?>" alt="receipt">
                    <?php endif; ?>
                    View receipt
                  </a>
                  <details style="margin-top:4px">
                    <summary>Replace / remove</summary>
                    <form method="post" enctype="multipart/form-data" style="margin-top:6px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="upload_receipt">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                      <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                      <button class="btn" style="margin-top:6px">Upload new</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Remove this receipt?')" style="margin-top:6px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_receipt">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                      <button class="btn danger">Remove receipt</button>
                    </form>
                  </details>
                <?php else: ?>
                  <details>
                    <summary style="color:#65676b">Attach receipt</summary>
                    <form method="post" enctype="multipart/form-data" style="margin-top:6px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="upload_receipt">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                      <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                      <button class="btn" style="margin-top:6px">Upload</button>
                    </form>
                  </details>
                <?php endif; ?>
              </div>

              <details>
                <summary>Edit</summary>
                <form method="post" style="margin-top:10px" onsubmit="return syncBeforeSubmit(this)">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php foreach ($filterQ as $k=>$v): ?><input type="hidden" name="r_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
                  <div class="grid">
                    <div><label>Date</label><input type="date" name="exp_date" required value="<?= e($r['exp_date']) ?>"></div>
                    <div><label>Item</label><input name="item" required maxlength="200" value="<?= e($r['item']) ?>"></div>
                    <div><label>Vendor</label><input name="paid_to" maxlength="200" value="<?= e($r['paid_to']) ?>"></div>
                    <div><label>Category</label>
                      <select name="category">
                        <?php foreach ($categories as $c): ?><option <?= $r['category']===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                  </div>

                  <div class="sect"><h3>Breakdown (optional)</h3>
                    <div class="scroll">
                      <table class="li-tbl" data-li>
                        <thead><tr><th style="width:44%">Description</th><th class="r" style="width:16%">Qty</th><th class="r" style="width:18%">Unit cost ₹</th><th class="r" style="width:16%">Line total</th><th style="width:6%"></th></tr></thead>
                        <tbody></tbody>
                      </table>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px">
                      <button type="button" class="btn" onclick="addLine(this)">+ Add line</button>
                      <div class="li-sum">Gross from lines: <span class="liGrand">₹0.00</span></div>
                    </div>
                    <script type="application/json" class="li-seed"><?= json_encode(array_map(fn($li)=>['descr'=>$li['descr'],'qty'=>(float)$li['qty'],'unit'=>(float)$li['unit_cost']], $myLines), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
                  </div>

                  <div class="grid" style="margin-top:14px">
                    <div>
                      <label>Gross amount (₹)</label>
                      <input type="number" name="amount" step="0.01" min="0" class="amtField" value="<?= $myLines ? '' : e(number_format($gross,2,'.','')) ?>" oninput="recalcMoney(this.form)">
                      <p class="muted amtHint" style="font-size:12px">Fill only if not using the breakdown.</p>
                    </div>
                    <div><label>Discount (₹)</label><input type="number" name="discount" step="0.01" min="0" value="<?= e(number_format($disc,2,'.','')) ?>" class="discField" oninput="recalcMoney(this.form)"></div>
                    <div><label>Net owed</label><input type="text" class="netField" readonly value="₹0.00" style="background:#f0f2f5;font-weight:600"></div>
                  </div>

                  <div class="sect"><h3>Who paid (from pocket)</h3>
                    <div class="scroll">
                      <table class="pay-tbl" data-pay>
                        <thead><tr><th style="width:55%">Partner</th><th class="r" style="width:35%">Amount ₹</th><th style="width:10%"></th></tr></thead>
                        <tbody></tbody>
                      </table>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:8px">
                      <button type="button" class="btn" onclick="addPay(this)">+ Add payer</button>
                      <div class="pay-sum">Paid: <span class="payGrand">₹0.00</span> · <span class="balNote"></span></div>
                    </div>
                    <script type="application/json" class="pay-seed"><?= json_encode(array_map(fn($pp)=>['pid'=>(int)$pp['partner_id'],'amt'=>(float)$pp['amount']], $myPays), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
                  </div>

                  <label>Details</label>
                  <textarea name="details" maxlength="500"><?= e($r['details']) ?></textarea>
                  <button class="primary" style="margin-top:10px">Save changes</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5">Totals</td>
            <td class="r"><?= money(round($sumGross-$sumDisc,2)) ?></td>
            <td class="r"><?= money($sumPaid) ?></td>
            <td class="r"><?= $sumBalance>0.001 ? money($sumBalance) : '—' ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

<?php
  // JSON list of active partners for the JS payer dropdown.
  $partnerJs = [];
  foreach ($partners as $id=>$p) if ($p['is_active']) $partnerJs[] = ['id'=>(int)$id,'name'=>$p['name']];
?>
<script>
const PARTNERS = <?= json_encode($partnerJs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
function moneyFmt(n){ return '₹' + (isFinite(n)?n:0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }

// ── Line items ──────────────────────────────────────────────────────
function lineRowHtml(d,q,u){
  d=d||''; q=(q===0||q)?q:''; u=(u===0||u)?u:'';
  return '<tr>'
    +'<td><input name="li_descr[]" maxlength="200" placeholder="e.g. Fridge Magnet 2x2" value="'+String(d).replace(/"/g,'&quot;')+'"></td>'
    +'<td><input name="li_qty[]" type="number" step="0.01" min="0" class="r" value="'+q+'" oninput="recalcLine(this)"></td>'
    +'<td><input name="li_unit[]" type="number" step="0.01" min="0" class="r" value="'+u+'" oninput="recalcLine(this)"></td>'
    +'<td class="r liTotal">₹0.00</td>'
    +'<td class="r"><button type="button" class="btn li-x" onclick="delLine(this)">✕</button></td></tr>';
}
function addLine(btn,d,q,u){
  const tb=btn.closest('form').querySelector('table[data-li] tbody');
  tb.insertAdjacentHTML('beforeend', lineRowHtml(d,q,u));
  recalcLine(tb.lastElementChild.querySelector('input'));
}
function delLine(btn){ const f=btn.closest('form'); btn.closest('tr').remove(); recalcMoney(f); }
function recalcLine(input){
  const tr=input.closest('tr');
  const q=parseFloat(tr.querySelector('[name="li_qty[]"]').value)||0;
  const u=parseFloat(tr.querySelector('[name="li_unit[]"]').value)||0;
  tr.querySelector('.liTotal').textContent=moneyFmt(q*u);
  recalcMoney(input.closest('form'));
}

// ── Payers ──────────────────────────────────────────────────────────
function payRowHtml(pid,amt){
  amt=(amt===0||amt)?amt:'';
  let opts='<option value="">Choose partner…</option>';
  PARTNERS.forEach(p=>{ opts+='<option value="'+p.id+'"'+(p.id==pid?' selected':'')+'>'+p.name.replace(/</g,'&lt;')+'</option>'; });
  return '<tr>'
    +'<td><select name="pay_partner[]">'+opts+'</select></td>'
    +'<td><input name="pay_amount[]" type="number" step="0.01" min="0" class="r" value="'+amt+'" oninput="recalcMoney(this.form)"></td>'
    +'<td class="r"><button type="button" class="btn li-x" onclick="delPay(this)">✕</button></td></tr>';
}
function addPay(btn,pid,amt){
  const tb=btn.closest('form').querySelector('table[data-pay] tbody');
  tb.insertAdjacentHTML('beforeend', payRowHtml(pid,amt));
  recalcMoney(btn.closest('form'));
}
function delPay(btn){ const f=btn.closest('form'); btn.closest('tr').remove(); recalcMoney(f); }

// ── Money recompute: gross, discount, net, paid, balance ────────────
function recalcMoney(form){
  // gross: from lines if any real line, else the manual field
  let lineSum=0, anyLine=false;
  form.querySelectorAll('table[data-li] tbody tr').forEach(tr=>{
    const q=parseFloat(tr.querySelector('[name="li_qty[]"]').value)||0;
    const u=parseFloat(tr.querySelector('[name="li_unit[]"]').value)||0;
    const d=tr.querySelector('[name="li_descr[]"]').value.trim();
    if(d!==''||q||u) anyLine=true;
    lineSum+=q*u;
  });
  const grand=form.querySelector('.liGrand'); if(grand) grand.textContent=moneyFmt(lineSum);
  const amt=form.querySelector('.amtField'), hint=form.querySelector('.amtHint');
  if(amt){
    amt.disabled=anyLine; amt.style.opacity=anyLine?.5:1;
    if(anyLine){ amt.value=''; if(hint) hint.textContent='Gross taken from breakdown ('+moneyFmt(lineSum)+').'; }
    else if(hint){ hint.textContent='Fill only if not using the breakdown.'; }
  }
  const gross = anyLine ? lineSum : (parseFloat(amt&&amt.value)||0);
  const disc  = parseFloat(form.querySelector('.discField')?.value)||0;
  const net   = Math.max(0, gross - disc);
  const netF  = form.querySelector('.netField'); if(netF) netF.value=moneyFmt(net);

  let paid=0;
  form.querySelectorAll('table[data-pay] tbody tr').forEach(tr=>{
    paid += parseFloat(tr.querySelector('[name="pay_amount[]"]').value)||0;
  });
  const payG=form.querySelector('.payGrand'); if(payG) payG.textContent=moneyFmt(paid);
  const bal = Math.round((net - paid)*100)/100;
  const bn=form.querySelector('.balNote');
  if(bn){
    if(paid<=0.001) bn.innerHTML='<span style="color:#c0392b">unpaid</span>';
    else if(bal>0.01) bn.innerHTML='<span style="color:#a06a00">balance '+moneyFmt(bal)+'</span>';
    else if(bal<-0.01) bn.innerHTML='<span style="color:#c0392b">overpaid by '+moneyFmt(-bal)+'</span>';
    else bn.innerHTML='<span style="color:#1a7f4b">fully paid</span>';
  }
}
function syncBeforeSubmit(form){
  // gross must be present
  let anyLine=false;
  form.querySelectorAll('table[data-li] tbody tr').forEach(tr=>{
    const q=parseFloat(tr.querySelector('[name="li_qty[]"]').value)||0;
    const d=tr.querySelector('[name="li_descr[]"]').value.trim();
    if(d!==''||q) anyLine=true;
  });
  const amt=form.querySelector('.amtField');
  const manual=amt&&!amt.disabled&&parseFloat(amt.value)>0;
  if(!anyLine&&!manual){ alert('Enter a gross amount, or add at least one breakdown line.'); return false; }
  // disabled amount field must not post
  if(amt&&amt.disabled) amt.removeAttribute('name');
  return true;
}
// seed edit forms
document.querySelectorAll('script.li-seed').forEach(seed=>{
  let data=[]; try{data=JSON.parse(seed.textContent||'[]');}catch(e){}
  const form=seed.closest('form'); const btn=form.querySelector('button[onclick^="addLine"]');
  data.forEach(it=>addLine(btn,it.descr,it.qty,it.unit));
  recalcMoney(form);
});
document.querySelectorAll('script.pay-seed').forEach(seed=>{
  let data=[]; try{data=JSON.parse(seed.textContent||'[]');}catch(e){}
  const form=seed.closest('form'); const btn=form.querySelector('button[onclick^="addPay"]');
  data.forEach(it=>addPay(btn,it.pid,it.amt));
  recalcMoney(form);
});
</script>
<?php require 'layout_end.php'; ?>
</body>
</html>