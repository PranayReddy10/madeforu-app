<?php
/**
 * Bills / invoices.
 *
 * A bill is issued once per order and then frozen: the row keeps a JSON
 * snapshot of the order exactly as it was billed. Editing the order later
 * does not quietly rewrite a bill the customer is already holding — the
 * app offers "re-issue", which writes a new snapshot and bumps `revision`,
 * so the two versions are distinguishable when someone asks why the number
 * changed.
 *
 * Every bill also gets a public_token so a customer can open their own
 * copy without signing in, the same trust model track.php already uses.
 * The token is 16 random bytes: unguessable, and it exposes only that one
 * order.
 *
 * Walk-in orders bill perfectly well — "Walk-in customer" simply prints
 * where the name would be. That is the point of the feature: a bill for
 * every sale, whether or not anyone gave their details.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// fy_label() lives in _bootstrap.php: the Money screen labels the
// financial year too, and two copies of the April boundary is one
// copy too many.

/**
 * Next bill number in the current financial year, derived from the MAX of
 * the existing series rather than COUNT — the same reasoning as
 * generate_order_no(): COUNT reuses a number after a deletion and collides
 * when two people bill at once.
 */
function next_bill_no(mysqli $conn, string $prefix, string $fy): string {
    $like  = $prefix . '/' . $fy . '/%';
    $start = strlen($prefix . '/' . $fy . '/') + 1;

    $s = $conn->prepare(
        'SELECT MAX(CAST(SUBSTRING(bill_no, ?) AS UNSIGNED)) mx FROM bills WHERE bill_no LIKE ?'
    );
    $s->bind_param('is', $start, $like);
    $s->execute();
    $mx = (int)($s->get_result()->fetch_assoc()['mx'] ?? 0);
    $s->close();

    return $prefix . '/' . $fy . '/' . str_pad((string)($mx + 1), 4, '0', STR_PAD_LEFT);
}

/** The order, its lines and its payments, shaped for printing. */
function bill_snapshot(mysqli $conn, int $orderId): array {
    $s = $conn->prepare(
        'SELECT o.*, e.name event_name, a.name created_by_name
           FROM orders o
           LEFT JOIN events e ON e.id = o.event_id
           LEFT JOIN admins a ON a.id = o.created_by
          WHERE o.id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $o = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');

    $lines = [];
    $s = $conn->prepare('SELECT item, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id');
    $s->bind_param('i', $orderId);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) {
        $lines[] = ['item' => $r['item'], 'quantity' => (int)$r['quantity'],
                    'unit_price' => (float)$r['unit_price'], 'line_total' => (float)$r['line_total']];
    }
    $s->close();

    $payments = [];
    $s = $conn->prepare('SELECT amount, mode, note, created_at FROM payments WHERE order_id = ? ORDER BY id');
    $s->bind_param('i', $orderId);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) {
        $payments[] = ['amount' => (float)$r['amount'], 'mode' => $r['mode'],
                       'note' => $r['note'], 'paid_at' => $r['created_at']];
    }
    $s->close();

    $total   = (float)$o['total'];
    $paid    = (float)$o['paid_amount'];
    $balance = round(max($total - $paid, 0), 2);
    $qty     = array_sum(array_column($lines, 'quantity'));
    $phone   = trim((string)$o['phone']);
    $name    = trim((string)$o['name']);

    return [
        'order_id'     => (int)$o['id'],
        'order_no'     => $o['order_no'],
        'order_date'   => $o['created_at'],
        'customer'     => [
            'name'       => ($name === '' || strcasecmp($name, 'Walk-in') === 0) ? 'Walk-in customer' : $name,
            'phone'      => $phone,
            'is_walk_in' => $phone === '',
        ],
        'channel'      => $o['event_name'] ?: 'Direct',
        'served_by'    => $o['created_by_name'],
        'lines'        => $lines,
        'total_qty'    => (int)$qty,
        'subtotal'     => (float)$o['subtotal'],
        'extra_charge' => (float)($o['extra_charge'] ?? 0),
        'extra_charge_reason' => $o['extra_charge_reason'],
        'discount'     => (float)$o['discount'],
        'discount_reason'     => $o['discount_reason'],
        'total'        => $total,
        'paid'         => $paid,
        'balance'      => $balance,
        'pay_status'   => pay_status($total, $paid),
        'payments'     => $payments,
        'awb'          => ($o['awb'] ?? '') !== '' ? $o['awb'] : null,
        'track_url'    => delhivery_link($o['awb'] ?? null),
        'notes'        => $o['notes'],
        'amount_words' => amount_in_words($total),
    ];
}

/** Read a bill row by order id, or null. */
function find_bill(mysqli $conn, int $orderId): ?array {
    $s = $conn->prepare(
        'SELECT b.*, a.name issued_by_name FROM bills b
         LEFT JOIN admins a ON a.id = b.issued_by WHERE b.order_id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/**
 * Issue, or re-issue, the bill for an order.
 * `$refresh` re-snapshots an existing bill after the order changed; the
 * bill NUMBER never changes, only its revision, so the customer's copy and
 * ours still refer to the same document.
 */
function issue_bill(mysqli $conn, int $orderId, ?int $adminId, bool $refresh): array {
    $settings = app_settings($conn);
    $snap     = bill_snapshot($conn, $orderId);
    $json     = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $existing = find_bill($conn, $orderId);
    if ($existing && !$refresh) return $existing;

    if ($existing) {
        $s = $conn->prepare('UPDATE bills SET snapshot = ?, revision = revision + 1, issued_by = ? WHERE id = ?');
        $s->bind_param('sii', $json, $adminId, $existing['id']);
        $s->execute();
        $s->close();
        return find_bill($conn, $orderId);
    }

    $prefix = trim($settings['bill_prefix']) ?: 'MFU';
    $fy     = fy_label((string)$snap['order_date']);
    $token  = bin2hex(random_bytes(16));

    // Retry on the unique index rather than locking the table: two
    // partners billing at the same second can read the same MAX.
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $billNo = next_bill_no($conn, $prefix, $fy);
        try {
            $s = $conn->prepare(
                'INSERT INTO bills (order_id, bill_no, public_token, snapshot, issued_by) VALUES (?,?,?,?,?)'
            );
            $s->bind_param('isssi', $orderId, $billNo, $token, $json, $adminId);
            $s->execute();
            $s->close();
            break;
        } catch (mysqli_sql_exception $dup) {
            if ($dup->getCode() !== 1062 || $attempt === 3) throw $dup;
        }
    }
    return find_bill($conn, $orderId);
}

/** Bill row + decoded snapshot + everything the printer needs. */
function bill_payload(mysqli $conn, array $row): array {
    $settings = app_settings($conn);
    $snap     = json_decode((string)$row['snapshot'], true) ?: [];

    $base = rtrim((string)(($_SERVER['HTTPS'] ?? '') ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'sale.madeforu.co.in')
          . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/bills.php')), '/');

    return [
        'bill_no'      => $row['bill_no'],
        'revision'     => (int)$row['revision'],
        'issued_at'    => $row['issued_at'],
        'issued_by'    => $row['issued_by_name'] ?? null,
        'public_token' => $row['public_token'],
        'public_url'   => $base . '/bills.php?action=html&token=' . $row['public_token'],
        'business'     => [
            'name'  => $settings['business_name'],
            'tag'   => $settings['business_tag'],
            'addr'  => $settings['business_addr'],
            'phone' => $settings['business_phone'],
            'email' => $settings['business_email'],
            'site'  => $settings['business_site'],
            'gstin' => $settings['gstin'],
        ],
        'logo_url'     => trim((string)($settings['logo_url'] ?? '')),
        'footer'       => $settings['bill_footer'],
        'terms'        => $settings['bill_terms'],
        'upi_intent'   => upi_intent($settings, (string)($snap['order_no'] ?? ''), (float)($snap['balance'] ?? 0)),
        'order'        => $snap,
    ];
}

// ── Printable HTML ─────────────────────────────────────────────────

/**
 * Self-contained invoice HTML: no external CSS, no fonts, no images, so
 * it prints identically from a phone's share sheet, a browser and a
 * WhatsApp preview. `size=thermal` switches to an 80mm-roll layout for a
 * counter printer; the default is A4.
 */
function bill_html(array $p, string $size = 'a4'): string {
    $o   = $p['order'];
    $b   = $p['business'];
    $th  = $size === 'thermal';
    $w   = $th ? '80mm' : '210mm';
    $pad = $th ? '4mm'  : '16mm';

    $rows = '';
    foreach ($o['lines'] as $i => $l) {
        $rows .= '<tr><td class="n">' . ($i + 1) . '</td><td>' . e($l['item']) . '</td>'
               . '<td class="c">' . (int)$l['quantity'] . '</td>'
               . '<td class="r">' . number_format((float)$l['unit_price'], 2) . '</td>'
               . '<td class="r">' . number_format((float)$l['line_total'], 2) . '</td></tr>';
    }

    $adj = '';
    if ((float)$o['extra_charge'] > 0.001) {
        $label = trim((string)($o['extra_charge_reason'] ?? '')) ?: 'Additional charges';
        $adj .= '<tr><td colspan="4" class="r">' . e($label) . '</td><td class="r">+' . number_format((float)$o['extra_charge'], 2) . '</td></tr>';
    }
    if ((float)$o['discount'] > 0.001) {
        $label = trim((string)($o['discount_reason'] ?? '')) ?: 'Discount';
        $adj .= '<tr><td colspan="4" class="r">' . e($label) . '</td><td class="r">-' . number_format((float)$o['discount'], 2) . '</td></tr>';
    }

    $payRows = '';
    foreach ($o['payments'] as $pay) {
        $payRows .= '<tr><td>' . e(date('d M Y', strtotime((string)$pay['paid_at']))) . '</td>'
                  . '<td>' . e(strtoupper((string)$pay['mode'])) . '</td>'
                  . '<td class="r">' . number_format((float)$pay['amount'], 2) . '</td></tr>';
    }
    $payBlock = $payRows === '' ? '' :
        '<div class="sec"><h3>Payments received</h3><table class="pay"><tbody>' . $payRows . '</tbody></table></div>';

    $balanceBlock = (float)$o['balance'] > 0.001
        ? '<div class="due">Balance due &#8377;' . number_format((float)$o['balance'], 2) . '</div>'
        : '<div class="paidmark">PAID IN FULL</div>';

    $upi = $p['upi_intent']
        ? '<div class="upi">Pay by UPI: <b>' . e(parse_upi_vpa($p['upi_intent'])) . '</b></div>' : '';

    $track = !empty($o['track_url'])
        ? '<div class="sec small">Delhivery tracking: <b>' . e((string)$o['awb']) . '</b><br>' . e((string)$o['track_url']) . '</div>' : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($p['bill_no']) . ' — ' . e($b['name']) . '</title>
<style>
  @page { size: ' . ($th ? '80mm auto' : 'A4') . '; margin: ' . ($th ? '3mm' : '12mm') . '; }
  * { box-sizing: border-box; }
  body { margin:0; padding:' . $pad . '; width:' . $w . '; max-width:100%;
         font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
         color:#14161a; background:#fff; font-size:' . ($th ? '11px' : '13px') . '; line-height:1.45; }
  h1 { margin:0; font-size:' . ($th ? '16px' : '22px') . '; letter-spacing:-.3px; }
  .tag { color:#6b7280; font-size:' . ($th ? '9px' : '11px') . '; margin-top:2px; }
  .head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start;
          border-bottom:2px solid #14161a; padding-bottom:10px; ' . ($th ? 'flex-direction:column;' : '') . ' }
  .billno { text-align:' . ($th ? 'left' : 'right') . '; font-size:' . ($th ? '10px' : '12px') . '; }
  .billno b { display:block; font-size:' . ($th ? '12px' : '15px') . '; }
  .logo { max-height:52px; max-width:180px; display:block; margin-bottom:8px; }
  .meta { display:flex; gap:16px; margin:12px 0; ' . ($th ? 'flex-direction:column; gap:6px;' : '') . ' }
  .meta > div { flex:1; }
  .lbl { color:#6b7280; font-size:' . ($th ? '9px' : '10px') . '; text-transform:uppercase; letter-spacing:.6px; }
  table { width:100%; border-collapse:collapse; margin-top:6px; }
  th { text-align:left; font-size:' . ($th ? '9px' : '11px') . '; text-transform:uppercase; letter-spacing:.5px;
       color:#6b7280; border-bottom:1px solid #d1d5db; padding:6px 4px; }
  td { padding:6px 4px; border-bottom:1px solid #f0f1f3; vertical-align:top; }
  td.r, th.r { text-align:right; } td.c, th.c { text-align:center; } td.n { color:#9ca3af; width:18px; }
  tfoot td { border:none; padding:4px; }
  .grand td { border-top:2px solid #14161a; font-size:' . ($th ? '13px' : '16px') . '; font-weight:700; padding-top:8px; }
  .words { margin-top:8px; font-style:italic; color:#4b5563; font-size:' . ($th ? '9px' : '11px') . '; }
  .sec { margin-top:14px; } .sec h3 { margin:0 0 4px; font-size:' . ($th ? '10px' : '12px') . '; text-transform:uppercase; letter-spacing:.6px; color:#6b7280; }
  .pay td { font-size:' . ($th ? '10px' : '12px') . '; }
  .due { margin-top:10px; padding:8px 10px; background:#fef2f2; border:1px solid #fecaca; color:#b91c1c;
         font-weight:700; text-align:center; border-radius:6px; }
  .paidmark { margin-top:10px; padding:8px 10px; background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d;
              font-weight:700; text-align:center; letter-spacing:1px; border-radius:6px; }
  .upi { margin-top:8px; text-align:center; font-size:' . ($th ? '10px' : '12px') . '; }
  .foot { margin-top:16px; padding-top:10px; border-top:1px dashed #d1d5db; text-align:center; color:#6b7280;
          font-size:' . ($th ? '9px' : '11px') . '; }
  .small { font-size:' . ($th ? '9px' : '11px') . '; color:#4b5563; }
  @media print { .noprint { display:none; } body { padding:0; } }
</style></head><body>
<div class="head">
  <div>' . ($p['logo_url'] !== '' ? '<img class="logo" src="' . e($p['logo_url']) . '" alt="">' : '') . '
    <h1>' . e($b['name']) . '</h1><div class="tag">' . e($b['tag']) . '</div>
    <div class="small">' . e($b['addr']) . ($b['phone'] ? ' &middot; ' . e($b['phone']) : '') . '</div>'
    . ($b['gstin'] ? '<div class="small">GSTIN: ' . e($b['gstin']) . '</div>' : '') . '</div>
  <div class="billno"><span class="lbl">Invoice</span><b>' . e($p['bill_no']) . '</b>'
    . e(date('d M Y, g:i a', strtotime((string)$p['issued_at']))) . '</div>
</div>
<div class="meta">
  <div><div class="lbl">Billed to</div><b>' . e($o['customer']['name']) . '</b>'
    . ($o['customer']['phone'] ? '<br>' . e($o['customer']['phone']) : '') . '</div>
  <div><div class="lbl">Order</div><b>' . e($o['order_no']) . '</b><br>'
    . e(date('d M Y', strtotime((string)$o['order_date']))) . '</div>
  <div><div class="lbl">Channel</div>' . e($o['channel']) . '</div>
</div>
<table>
  <thead><tr><th class="n">#</th><th>Item</th><th class="c">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
  <tbody>' . $rows . '</tbody>
  <tfoot>
    <tr><td colspan="4" class="r">Subtotal</td><td class="r">' . number_format((float)$o['subtotal'], 2) . '</td></tr>
    ' . $adj . '
    <tr class="grand"><td colspan="4" class="r">Total</td><td class="r">&#8377;' . number_format((float)$o['total'], 2) . '</td></tr>
  </tfoot>
</table>
<div class="words">' . e((string)$o['amount_words']) . '</div>
' . $payBlock . $balanceBlock . $upi . $track . '
<div class="foot">' . e((string)$p['footer']) . '<br>' . e((string)$p['terms']) . '</div>
</body></html>';
}

/** Pull the VPA back out of a upi:// intent for display on the bill. */
function parse_upi_vpa(string $intent): string {
    $q = parse_url($intent, PHP_URL_QUERY) ?: '';
    parse_str($q, $parts);
    return (string)($parts['pa'] ?? '');
}

// ── Routes ─────────────────────────────────────────────────────────
// html/ has a public path (a customer opening their own bill link), so
// auth is applied per route rather than at the top of the file.

api_dispatch([

    // ── POST issue {order_id, refresh?} ────────────────────────────
    'issue' => function () use ($conn) {
        $me = api_require_auth($conn);
        $orderId = api_int('order_id', api_int('id'));
        if ($orderId < 1) throw new ApiInputError('Invalid order.');

        $row = issue_bill($conn, $orderId, $me['id'], api_bool('refresh'));
        api_ok(['bill' => bill_payload($conn, $row),
                'message' => 'Bill ' . $row['bill_no'] . ' ready.']);
    },

    // ── GET get&order_id= ──────────────────────────────────────────
    // Issues on first read: a partner tapping "Bill" wants a bill, not a
    // two-step ceremony.
    'get' => function () use ($conn) {
        $me = api_require_auth($conn);
        $orderId = api_int('order_id', api_int('id'));
        if ($orderId < 1) throw new ApiInputError('Invalid order.');

        $row = find_bill($conn, $orderId) ?? issue_bill($conn, $orderId, $me['id'], false);
        api_ok(['bill' => bill_payload($conn, $row)]);
    },

    // ── GET list — the bill book ───────────────────────────────────
    'list' => function () use ($conn) {
        api_require_auth($conn);
        $limit  = max(1, min(api_int('limit', 50), MAX_PAGE_SIZE));
        $offset = max(0, api_int('offset', 0));

        $s = $conn->prepare(
            'SELECT b.bill_no, b.revision, b.issued_at, b.public_token, b.order_id,
                    o.order_no, o.name, o.total, o.paid_amount
               FROM bills b JOIN orders o ON o.id = b.order_id
              ORDER BY b.issued_at DESC, b.id DESC LIMIT ? OFFSET ?'
        );
        $s->bind_param('ii', $limit, $offset);
        $s->execute();
        $res = $s->get_result();

        $out = [];
        while ($r = $res->fetch_assoc()) {
            $total = (float)$r['total']; $paid = (float)$r['paid_amount'];
            $out[] = ['bill_no' => $r['bill_no'], 'revision' => (int)$r['revision'],
                      'issued_at' => $r['issued_at'], 'order_id' => (int)$r['order_id'],
                      'order_no' => $r['order_no'], 'customer' => $r['name'],
                      'total' => $total, 'balance' => round(max($total - $paid, 0), 2),
                      'pay_status' => pay_status($total, $paid),
                      'public_token' => $r['public_token']];
        }
        $s->close();
        api_ok(['bills' => $out, 'has_more' => count($out) === $limit]);
    },

    /**
     * GET html — the printable document.
     * Two ways in: a signed-in partner passing order_id, or anyone holding
     * the bill's public token. Nothing else is reachable through the
     * token, and it is random enough not to be guessed.
     */
    'html' => function () use ($conn) {
        // `token` is a bill's public token and nothing else. A signed-in
        // partner passes order_id plus `auth` (handled by api_raw_token),
        // never a session token here — putting one in `token` is what made
        // printing answer {"ok":false} instead of a page.
        $token = api_str('token');
        $size  = api_str('size', 'a4') === 'thermal' ? 'thermal' : 'a4';

        if ($token !== '') {
            $s = $conn->prepare(
                'SELECT b.*, a.name issued_by_name FROM bills b
                 LEFT JOIN admins a ON a.id = b.issued_by WHERE b.public_token = ?'
            );
            $s->bind_param('s', $token);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row) api_fail(404, 'not_found', 'This bill link is not valid.');
        } else {
            $me = api_require_auth($conn);
            $orderId = api_int('order_id', api_int('id'));
            if ($orderId < 1) throw new ApiInputError('Invalid order.');
            $row = find_bill($conn, $orderId) ?? issue_bill($conn, $orderId, $me['id'], false);
        }

        ob_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo bill_html(bill_payload($conn, $row), $size);
        exit;
    },
]);