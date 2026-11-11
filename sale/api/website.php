<?php
/**
 * POST api/website.php — delivered orders from madeforu.co.in.
 *
 * Auth: header X-Website-Key (or `key` in the body) must equal the key on
 * the Website sync page. Actions:
 *
 *   ping    → checks the key; the website's "Test connection" button.
 *   upsert  → add or update one order, matched on its website number:
 *             { web_order_no, name, phone, date, items: [{name, qty, unit_price, unit_cost}],
 *               discount, discount_reason, extra_charge, extra_charge_reason,
 *               payment: {amount, mode: cash|upi|card|other, note}, awb, dispatch_date, notes }
 *   remove  → { web_order_no } — the order was cancelled or returned after all.
 *
 * Prices are taken exactly as the website sold them, not from this panel's
 * price list: the website has its own products and prices.
 *
 * When a partner is chosen on the Website sync page, each order is also
 * credited to that partner's account (lib_website.php website_sync_credit);
 * removing the order removes its credit.
 */
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib_website.php';

api_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_fail(405, 'post_only', 'Send a POST request.');
}

$key = (string)website_setting($conn, 'website_sync_key');
$sent = (string)($_SERVER['HTTP_X_WEBSITE_KEY'] ?? api_str('key'));
if ($key === '') {
    api_fail(503, 'not_set_up', 'Website sync is not set up yet: open the sale panel → Website sync and create a key.');
}
if ($sent === '' || !hash_equals($key, $sent)) {
    api_fail(401, 'bad_key', 'The website sync key is wrong. Copy it again from the sale panel → Website sync.');
}

website_ensure_schema($conn);

/** Our order id for a website order number, or 0. */
function website_find(mysqli $conn, string $webNo): int {
    $s = $conn->prepare('SELECT id FROM orders WHERE web_order_no = ?');
    $s->bind_param('s', $webNo);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ? (int)$row['id'] : 0;
}

function website_web_no(): string {
    $no = api_str('web_order_no');
    if ($no === '' || strlen($no) > 40) throw new ApiInputError('web_order_no is missing.');
    return $no;
}

api_dispatch([
    'ping' => function () use ($conn) {
        api_ok(['message' => 'Connected to the sale panel.', 'synced' => website_synced_count($conn)]);
    },

    'upsert' => function () use ($conn) {
        $webNo = website_web_no();
        $name  = mb_substr(api_str('name', 'Website customer'), 0, 120) ?: 'Website customer';
        $phone = substr(preg_replace('/\D/', '', api_str('phone')), -10);
        $date  = api_str('date');
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $date) ? $date : date('Y-m-d H:i:s');

        $items = api_in('items', []);
        if (!is_array($items) || !$items) throw new ApiInputError('The order has no items.');
        $rows = [];
        foreach ($items as $it) {
            $qty = max(1, (int)($it['qty'] ?? 1));
            $unit = round((float)($it['unit_price'] ?? 0), 2);
            $rows[] = [
                'item' => mb_substr(trim((string)($it['name'] ?? 'Item')), 0, 60) ?: 'Item',
                'qty' => $qty, 'unit' => $unit, 'cost' => round((float)($it['unit_cost'] ?? 0), 2),
                'line' => round($unit * $qty, 2),
            ];
        }
        $extra = max(0, api_float('extra_charge'));
        $extraReason = $extra > 0 ? (mb_substr(api_str('extra_charge_reason'), 0, 120) ?: null) : null;
        $disc = max(0, api_float('discount'));
        $discReason = $disc > 0 ? (mb_substr(api_str('discount_reason'), 0, 120) ?: null) : null;
        $notes = mb_substr(api_str('notes', 'Website order ' . $webNo), 0, 255);
        $awb = preg_replace('/[^A-Za-z0-9\-]/', '', api_str('awb')) ?: null;
        $dispatch = api_date('dispatch_date') ?: null;
        $pay = api_in('payment', []);
        $payAmount = round((float)($pay['amount'] ?? 0), 2);
        $payMode = in_array($pay['mode'] ?? '', ['cash', 'upi', 'card', 'other'], true) ? $pay['mode'] : 'other';
        $payNote = mb_substr('Website: ' . trim((string)($pay['note'] ?? '')), 0, 120);
        $eventId = website_event_id($conn);

        $conn->begin_transaction();
        try {
            $id = website_find($conn, $webNo);
            $created = $id === 0;
            if ($created) {
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $orderNo = generate_order_no($conn);
                    try {
                        $s = $conn->prepare(
                            'INSERT INTO orders (order_no, web_order_no, name, phone, event_id, discount, discount_reason,
                                                 extra_charge, extra_charge_reason, is_ready, is_delivered, awb, dispatch_date,
                                                 notes, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,1,1,?,?,?,?)'
                        );
                        $s->bind_param('ssssidsdsssss', $orderNo, $webNo, $name, $phone, $eventId, $disc, $discReason,
                                       $extra, $extraReason, $awb, $dispatch, $notes, $date);
                        $s->execute();
                        $id = (int)$conn->insert_id;
                        $s->close();
                        break;
                    } catch (mysqli_sql_exception $dup) {
                        // 1062 on order_no: two orders in the same second; retry the number.
                        // 1062 on web_order_no: the same order sent twice at once; update it instead.
                        if ($dup->getCode() !== 1062 || $attempt === 2) throw $dup;
                        if (($found = website_find($conn, $webNo)) > 0) { $id = $found; $created = false; break; }
                    }
                }
            }
            if (!$created) {
                $s = $conn->prepare(
                    'UPDATE orders SET name = ?, phone = ?, event_id = ?, discount = ?, discount_reason = ?, extra_charge = ?,
                            extra_charge_reason = ?, is_ready = 1, is_delivered = 1, awb = ?, dispatch_date = ?, notes = ?
                      WHERE id = ?'
                );
                $s->bind_param('ssidsdssssi', $name, $phone, $eventId, $disc, $discReason, $extra, $extraReason, $awb, $dispatch, $notes, $id);
                $s->execute();
                $s->close();
                // The website is the source of truth for its own lines and its payment.
                $conn->query('DELETE FROM order_items WHERE order_id = ' . $id);
                $like = 'Website:%';
                $s = $conn->prepare('DELETE FROM payments WHERE order_id = ? AND note LIKE ?');
                $s->bind_param('is', $id, $like);
                $s->execute();
                $s->close();
            }

            $s = $conn->prepare('INSERT INTO order_items (order_id, item, quantity, unit_price, unit_cost, line_total) VALUES (?,?,?,?,?,?)');
            foreach ($rows as $r) {
                $s->bind_param('isiddd', $id, $r['item'], $r['qty'], $r['unit'], $r['cost'], $r['line']);
                $s->execute();
            }
            $s->close();

            recalc_total($conn, $id);
            $total = (float)$conn->query('SELECT total FROM orders WHERE id = ' . $id)->fetch_assoc()['total'];
            $payAmount = min($payAmount, $total);
            if ($payAmount > 0.001) {
                $s = $conn->prepare('INSERT INTO payments (order_id, amount, mode, note, created_at) VALUES (?,?,?,?,?)');
                $s->bind_param('idsss', $id, $payAmount, $payMode, $payNote, $date);
                $s->execute();
                $s->close();
            }
            recalc_paid($conn, $id);
            $credit = website_sync_credit($conn, $id, $eventId, $webNo);
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        $o = $conn->query('SELECT order_no, total, paid_amount FROM orders WHERE id = ' . $id)->fetch_assoc();
        website_setting_set($conn, 'website_sync_last', date('Y-m-d H:i:s') . ' · ' . $webNo);
        api_ok([
            'order_no' => $o['order_no'], 'id' => $id, 'created' => $created,
            'total' => (float)$o['total'], 'paid' => (float)$o['paid_amount'],
            'credited' => $credit,
            'message' => ($created ? 'Added ' : 'Updated ') . $o['order_no'] . ' for website order ' . $webNo . ' — ' . money($o['total'])
                         . ($credit ? ', credited ' . money($credit['amount']) . ' to ' . $credit['partner'] : ''),
        ]);
    },

    'remove' => function () use ($conn) {
        $webNo = website_web_no();
        $id = website_find($conn, $webNo);
        if ($id > 0) {
            $conn->begin_transaction();
            try {
                // Its automatic credit goes with it: the partner never got that money.
                website_drop_credit($conn, $id);
                // Children first: not every copy of the database has the cascade rules.
                foreach (['DELETE FROM payments WHERE order_id = ?', 'DELETE FROM order_items WHERE order_id = ?', 'DELETE FROM orders WHERE id = ?'] as $sql) {
                    $s = $conn->prepare($sql);
                    $s->bind_param('i', $id);
                    $s->execute();
                    $s->close();
                }
                $conn->commit();
            } catch (Exception $ex) {
                $conn->rollback();
                throw $ex;
            }
        }
        api_ok(['removed' => $id > 0, 'message' => $id > 0 ? 'Removed website order ' . $webNo . '.' : 'Website order ' . $webNo . ' was not in the panel.']);
    },
]);
