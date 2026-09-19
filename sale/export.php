<?php
require 'config.php';
require_login();

$search     = trim($_GET['search'] ?? '');
$fPaid      = $_GET['paid'] ?? '';
$fReady     = $_GET['ready'] ?? '';
$fDelivered = $_GET['delivered'] ?? '';

$where = []; $params = []; $types = '';

if ($search !== '') {
    $like = '%' . $search . '%';

    // Phone is stored as 10 digits. Match it against the search digits, and
    // also against a country-code-stripped version, so "+91 62017 81217",
    // "62017 81217", "6201781217" and a partial "6201" all find the order.
    // Name and order-no still match the raw term.
    $digits = preg_replace('/[^0-9]/', '', $search);
    if ($digits !== '') {
        // Also try a country-code-stripped form, inline so this does not
        // depend on any helper in config.php being present.
        $stripped = $digits;
        if (strlen($stripped) > 10 && substr($stripped, 0, 2) === '91') {
            $stripped = substr($stripped, 2);
        }
        $stripped = ltrim($stripped, '0');

        $phoneA = '%' . $digits . '%';
        $phoneB = '%' . $stripped . '%';
        $where[] = '((o.phone LIKE ? OR o.phone LIKE ?) OR o.name LIKE ? OR o.order_no LIKE ?)';
        $params[] = $phoneA; $params[] = $phoneB; $params[] = $like; $params[] = $like;
        $types .= 'ssss';
    } else {
        // No digits typed -- a name or order-no search only.
        $where[] = '(o.name LIKE ? OR o.order_no LIKE ?)';
        $params[] = $like; $params[] = $like;
        $types .= 'ss';
    }
}
// Mirror pay_status() exactly. A fully-discounted order (total = 0) owes
// nothing, so it counts as paid -- not unpaid, and not invisible to filters.
if ($fPaid === 'paid')        $where[] = '(o.total <= 0 OR o.paid_amount >= o.total)';
elseif ($fPaid === 'unpaid')  $where[] = '(o.total > 0 AND o.paid_amount <= 0)';
elseif ($fPaid === 'partial') $where[] = '(o.paid_amount > 0 AND o.paid_amount < o.total)';

if ($fReady !== '')     { $where[]='o.is_ready = ?';     $params[]=(int)$fReady;     $types.='i'; }
if ($fDelivered !== '') { $where[]='o.is_delivered = ?'; $params[]=(int)$fDelivered; $types.='i'; }

// Correlated subquery: safe under ONLY_FULL_GROUP_BY.
$sql = 'SELECT o.*, a.name AS admin_name, ev.name AS event_name,
               (SELECT GROUP_CONCAT(CONCAT(i.item," x",i.quantity," @",i.unit_price) SEPARATOR " | ")
                  FROM order_items i WHERE i.order_id = o.id) AS item_list
        FROM orders o
        LEFT JOIN admins a  ON a.id = o.created_by
        LEFT JOIN events ev ON ev.id = o.event_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY o.id DESC';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=orders-' . date('Y-m-d-Hi') . '.csv');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Order No','Name','Phone','Products','Subtotal',
               'Additional Charges','Charge Reason','Discount',
               'Discount Reason','Total','Paid','Balance',
               'Status','Ready','Handed Over','Event','Created By','Created At']);

while ($r = $rows->fetch_assoc()) {
    $bal = max((float)$r['total'] - (float)$r['paid_amount'], 0);
    $st  = pay_status((float)$r['total'], (float)$r['paid_amount']);
    fputcsv($out, [
        $r['order_no'], $r['name'], $r['phone'], $r['item_list'],
        number_format((float)$r['subtotal'], 2, '.', ''),
        number_format((float)$r['extra_charge'], 2, '.', ''),
        $r['extra_charge_reason'],
        number_format((float)$r['discount'], 2, '.', ''),
        $r['discount_reason'],
        number_format((float)$r['total'], 2, '.', ''),
        number_format((float)$r['paid_amount'], 2, '.', ''),
        number_format($bal, 2, '.', ''),
        ['paid'=>'Fully paid','partial'=>'Part paid','unpaid'=>'Unpaid'][$st],
        $r['is_ready'] ? 'Yes' : 'No',
        $r['is_delivered'] ? 'Yes' : 'No',
        $r['event_name'] ?? 'Offline',
        $r['admin_name'] ?? '',
        $r['created_at'],
    ]);
}
fclose($out);
$stmt->close();
$conn->close();