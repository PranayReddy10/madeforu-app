<?php
/**
 * What changed in the business: new sales, payments, later edits to an
 * order, expenses and account movements.
 *
 * Read from the tables themselves rather than from a log every screen
 * would have to remember to write to, so a change made on the website,
 * in the PWA or in the Android app is seen the same way. Shared by
 * api/activity.php (the apps' feed) and lib_push.php (real-time push),
 * so both describe a change in the same words.
 *
 * Nothing is deleted from here, so a deletion is never announced.
 */
declare(strict_types=1);

const ACTIVITY_LIMIT = 50;

/** An order's lines as "Round Magnet x2, Keychain x1", as the order list shows them. */
const ACTIVITY_ITEMS_SUBQUERY = "(SELECT GROUP_CONCAT(CONCAT(oi.item, ' x', oi.quantity) ORDER BY oi.id SEPARATOR ', ')
                               FROM order_items oi WHERE oi.order_id = o.id)";

/**
 * Run one kind's query. When a kind comes back full, its rows after the
 * last one were not read, so the earliest such cut-off is remembered in
 * $capped and the cursor is not moved past it.
 */
function activity_rows(mysqli $conn, string $sql, string $types, array $params, ?string &$capped): array {
    $s = $conn->prepare($sql);
    if ($types !== '') $s->bind_param($types, ...$params);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    if (count($rows) >= ACTIVITY_LIMIT) {
        $last = (string)end($rows)['at'];
        if ($capped === null || $last < $capped) $capped = $last;
    }
    return $rows;
}

function activity_money(float $v): string {
    return '₹' . number_format($v, (abs($v - round($v)) < 0.005) ? 0 : 2);
}

/**
 * Every change between $after and $now, oldest first.
 *
 * $op is the lower bound: '>' for a cursor that has fully read its
 * second (the apps' feed), '>=' when the caller dedupes the boundary
 * second itself (push). `mine` is true when the row records that
 * $meId made it; only orders and payments record that.
 */
function activity_items(mysqli $conn, string $after, string $now, int $meId, string $op, ?string &$capped): array {
    if ($op !== '>' && $op !== '>=') throw new InvalidArgumentException('op');
    $items = [];
    $capped = null;

    // New sales.
    foreach (activity_rows($conn,
        'SELECT o.id, o.order_no, o.name, o.phone, o.total, o.created_by, o.created_at at,
                e.name event_name, a.name by_name, ' . ACTIVITY_ITEMS_SUBQUERY . ' items_text
           FROM orders o
           LEFT JOIN events e ON e.id = o.event_id
           LEFT JOIN admins a ON a.id = o.created_by
          WHERE o.created_at ' . $op . ' ? AND o.created_at <= ?
          ORDER BY o.created_at LIMIT ' . ACTIVITY_LIMIT,
        'ss', [$after, $now], $capped) as $r) {
        $who = trim((string)$r['phone']) === '' ? 'Walk-in' : (string)$r['name'];
        $items[] = [
            'kind'     => 'order',
            'id'       => (int)$r['id'],
            'order_id' => (int)$r['id'],
            'at'       => $r['at'],
            'mine'     => (int)$r['created_by'] === $meId,
            'title'    => 'New sale · ' . activity_money((float)$r['total']),
            'body'     => $who . ' · ' . $r['order_no']
                . ($r['event_name'] ? ' · ' . $r['event_name'] : '')
                . ($r['items_text'] ? "\n" . $r['items_text'] : '')
                . ($r['by_name'] ? "\nby " . $r['by_name'] : ''),
        ];
    }

    // Payments — except the one taken with the sale itself, which the
    // "New sale" line above already covers.
    foreach (activity_rows($conn,
        'SELECT p.id, p.order_id, p.amount, p.mode, p.taken_by, p.created_at at,
                o.order_no, o.name, o.phone, o.total, o.paid_amount, a.name by_name
           FROM payments p
           JOIN orders o ON o.id = p.order_id
           LEFT JOIN admins a ON a.id = p.taken_by
          WHERE p.created_at ' . $op . ' ? AND p.created_at <= ?
            AND p.created_at > o.created_at + INTERVAL 10 SECOND
          ORDER BY p.created_at LIMIT ' . ACTIVITY_LIMIT,
        'ss', [$after, $now], $capped) as $r) {
        $due = max(0.0, (float)$r['total'] - (float)$r['paid_amount']);
        $who = trim((string)$r['phone']) === '' ? 'Walk-in' : (string)$r['name'];
        $items[] = [
            'kind'     => 'payment',
            'id'       => (int)$r['id'],
            'order_id' => (int)$r['order_id'],
            'at'       => $r['at'],
            'mine'     => (int)$r['taken_by'] === $meId,
            'title'    => 'Payment · ' . activity_money((float)$r['amount']) . ' ' . strtoupper((string)$r['mode']),
            'body'     => $who . ' · ' . $r['order_no']
                . ($due > 0.5 ? ' · ' . activity_money($due) . ' still due' : ' · fully paid')
                . ($r['by_name'] ? "\nby " . $r['by_name'] : ''),
        ];
    }

    // Orders changed after they were made: ready, delivered, shipped,
    // edited. A payment also touches updated_at, so an update within
    // ten seconds of a payment is that payment, already listed above.
    foreach (activity_rows($conn,
        'SELECT o.id, o.order_no, o.name, o.phone, o.is_ready, o.is_delivered, o.awb, o.updated_at at
           FROM orders o
          WHERE o.updated_at ' . $op . ' ? AND o.updated_at <= ?
            AND o.updated_at > o.created_at + INTERVAL 30 SECOND
            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id
                              AND ABS(TIMESTAMPDIFF(SECOND, p.created_at, o.updated_at)) <= 10)
          ORDER BY o.updated_at LIMIT ' . ACTIVITY_LIMIT,
        'ss', [$after, $now], $capped) as $r) {
        $state = (int)$r['is_delivered'] === 1 ? 'Delivered'
            : (($r['awb'] ?? '') !== '' ? 'Shipped · ' . $r['awb']
            : ((int)$r['is_ready'] === 1 ? 'Ready' : 'To make'));
        $who = trim((string)$r['phone']) === '' ? 'Walk-in' : (string)$r['name'];
        $items[] = [
            'kind'     => 'update',
            'id'       => (int)$r['id'],
            'order_id' => (int)$r['id'],
            'at'       => $r['at'],
            'mine'     => false,
            'title'    => 'Order updated · ' . $r['order_no'],
            'body'     => $who . ' · now ' . $state,
        ];
    }

    // Expenses.
    foreach (activity_rows($conn,
        'SELECT x.id, x.item, x.amount, x.discount, x.category, x.created_at at, pt.name paid_by_name
           FROM expenses x LEFT JOIN partners pt ON pt.id = x.paid_by
          WHERE x.created_at ' . $op . ' ? AND x.created_at <= ?
          ORDER BY x.created_at LIMIT ' . ACTIVITY_LIMIT,
        'ss', [$after, $now], $capped) as $r) {
        $items[] = [
            'kind'     => 'expense',
            'id'       => (int)$r['id'],
            'order_id' => null,
            'at'       => $r['at'],
            'mine'     => false,
            'title'    => 'New expense · ' . activity_money((float)$r['amount'] - (float)$r['discount']),
            'body'     => $r['item'] . ' · ' . $r['category']
                . ($r['paid_by_name'] ? ' · paid by ' . $r['paid_by_name'] : ''),
        ];
    }

    // Account movements. A settlement between partners is written as
    // two rows sharing a transfer_id; announce it once, from the payer.
    foreach (activity_rows($conn,
        'SELECT m.id, m.direction, m.kind, m.amount, m.invest_adjust, m.source, m.note,
                m.created_at at, pt.name partner, cp.name counterparty
           FROM account_movements m
           LEFT JOIN partners pt ON pt.id = m.partner_id
           LEFT JOIN partners cp ON cp.id = m.counterparty_id
          WHERE m.created_at ' . $op . ' ? AND m.created_at <= ?
            AND (m.transfer_id IS NULL OR m.direction = \'debit\')
          ORDER BY m.created_at LIMIT ' . ACTIVITY_LIMIT,
        'ss', [$after, $now], $capped) as $r) {
        $amount = (float)$r['amount'] > 0.001 ? (float)$r['amount'] : abs((float)$r['invest_adjust']);
        $credit = $r['direction'] === 'credit';
        $what = $r['counterparty'] && in_array($r['kind'], ['transfer', 'invest'], true)
            ? $r['partner'] . ' → ' . $r['counterparty']
            : ($credit ? 'Credit to ' : 'Drawn by ') . $r['partner'];
        $items[] = [
            'kind'     => 'movement',
            'id'       => (int)$r['id'],
            'order_id' => null,
            'at'       => $r['at'],
            'mine'     => false,
            'title'    => 'Movement · ' . ($credit ? '+' : '-') . activity_money($amount),
            'body'     => $what . ($r['source'] !== '' ? ' · ' . $r['source'] : '')
                . ($r['note'] !== '' ? "\n" . $r['note'] : ''),
        ];
    }

    usort($items, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));

    return $items;
}
