<?php
/**
 * What has changed since a device last looked — the feed behind the
 * apps' notifications.
 *
 * It reads the tables themselves rather than a log that every screen
 * would have to remember to write to. A sale saved from the website's
 * index.php, the PWA or the Android app is the same row in `orders`, so
 * all three are seen without touching any of their save code:
 *
 *   order     a new sale                      orders.created_at
 *   payment   money taken against an order    payments.created_at
 *   update    an order changed later          orders.updated_at
 *   expense   a new expense                   expenses.created_at
 *   movement  a credit, draw or settlement    account_movements.created_at
 *
 * The cursor is the database's own clock, two seconds behind. The first call (no `after`)
 * returns only `now`, so a newly signed-in phone does not announce every
 * sale ever made; each later call passes back the `now` it was given.
 * Comparing in SQL against NOW() keeps a phone with its clock set wrong
 * from missing or repeating anything.
 *
 * `mine` is true when the row records that the caller made it (orders
 * and payments do). Expenses, movements and later edits do not record
 * who made them, so those arrive with `mine` false whoever it was.
 *
 * Nothing is deleted from here, so a deletion is not announced.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

const FEED_LIMIT = 50;

/** 'Y-m-d H:i:s' from the request, or '' — never anything SQL could misread. */
function feed_cursor(): string {
    $raw = trim(api_str('after'));
    if ($raw === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
        throw new ApiInputError('after should look like 2026-09-25 14:05:00.');
    }
    return $raw;
}

/**
 * Run one kind's query. When a kind comes back full, its rows after the
 * last one were not read, so the earliest such cut-off is remembered in
 * $capped and the cursor is not moved past it.
 */
function feed_rows(mysqli $conn, string $sql, string $types, array $params, ?string &$capped): array {
    $s = $conn->prepare($sql);
    if ($types !== '') $s->bind_param($types, ...$params);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    if (count($rows) >= FEED_LIMIT) {
        $last = (string)end($rows)['at'];
        if ($capped === null || $last < $capped) $capped = $last;
    }
    return $rows;
}

function feed_money(float $v): string {
    return '₹' . number_format($v, (abs($v - round($v)) < 0.005) ? 0 : 2);
}

api_dispatch([

    // ── GET feed?after=Y-m-d H:i:s ─────────────────────────────────
    'feed' => function () use ($conn, $me) {
        // Two seconds behind the clock, not NOW() itself. A sale saved later
        // in the current second would carry that same second, and the next
        // call's strict "after" would skip it for good. A second is only
        // read once it has fully passed (with a moment's grace for a save
        // still committing); a save in the last two seconds waits for the
        // next check instead of being lost.
        $now = (string)$conn->query('SELECT NOW() - INTERVAL 2 SECOND n')->fetch_assoc()['n'];
        $after = feed_cursor();
        if ($after === '') api_ok(['now' => $now, 'items' => []]);

        $meId = (int)$me['id'];
        $items = [];
        $capped = null;

        // New sales.
        foreach (feed_rows($conn,
            'SELECT o.id, o.order_no, o.name, o.phone, o.total, o.created_by, o.created_at at,
                    e.name event_name, a.name by_name, ' . ORDER_ITEMS_SUBQUERY . ' items_text
               FROM orders o
               LEFT JOIN events e ON e.id = o.event_id
               LEFT JOIN admins a ON a.id = o.created_by
              WHERE o.created_at > ? AND o.created_at <= ?
              ORDER BY o.created_at LIMIT ' . FEED_LIMIT,
            'ss', [$after, $now], $capped) as $r) {
            $who = trim((string)$r['phone']) === '' ? 'Walk-in' : (string)$r['name'];
            $items[] = [
                'kind'     => 'order',
                'id'       => (int)$r['id'],
                'order_id' => (int)$r['id'],
                'at'       => $r['at'],
                'mine'     => (int)$r['created_by'] === $meId,
                'title'    => 'New sale · ' . feed_money((float)$r['total']),
                'body'     => $who . ' · ' . $r['order_no']
                    . ($r['event_name'] ? ' · ' . $r['event_name'] : '')
                    . ($r['items_text'] ? "\n" . $r['items_text'] : '')
                    . ($r['by_name'] ? "\nby " . $r['by_name'] : ''),
            ];
        }

        // Payments — except the one taken with the sale itself, which the
        // "New sale" line above already covers.
        foreach (feed_rows($conn,
            'SELECT p.id, p.order_id, p.amount, p.mode, p.taken_by, p.created_at at,
                    o.order_no, o.name, o.phone, o.total, o.paid_amount, a.name by_name
               FROM payments p
               JOIN orders o ON o.id = p.order_id
               LEFT JOIN admins a ON a.id = p.taken_by
              WHERE p.created_at > ? AND p.created_at <= ?
                AND p.created_at > o.created_at + INTERVAL 10 SECOND
              ORDER BY p.created_at LIMIT ' . FEED_LIMIT,
            'ss', [$after, $now], $capped) as $r) {
            $due = max(0.0, (float)$r['total'] - (float)$r['paid_amount']);
            $who = trim((string)$r['phone']) === '' ? 'Walk-in' : (string)$r['name'];
            $items[] = [
                'kind'     => 'payment',
                'id'       => (int)$r['id'],
                'order_id' => (int)$r['order_id'],
                'at'       => $r['at'],
                'mine'     => (int)$r['taken_by'] === $meId,
                'title'    => 'Payment · ' . feed_money((float)$r['amount']) . ' ' . strtoupper((string)$r['mode']),
                'body'     => $who . ' · ' . $r['order_no']
                    . ($due > 0.5 ? ' · ' . feed_money($due) . ' still due' : ' · fully paid')
                    . ($r['by_name'] ? "\nby " . $r['by_name'] : ''),
            ];
        }

        // Orders changed after they were made: ready, delivered, shipped,
        // edited. A payment also touches updated_at, so an update within
        // ten seconds of a payment is that payment, already listed above.
        foreach (feed_rows($conn,
            'SELECT o.id, o.order_no, o.name, o.phone, o.is_ready, o.is_delivered, o.awb, o.updated_at at
               FROM orders o
              WHERE o.updated_at > ? AND o.updated_at <= ?
                AND o.updated_at > o.created_at + INTERVAL 30 SECOND
                AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id
                                  AND ABS(TIMESTAMPDIFF(SECOND, p.created_at, o.updated_at)) <= 10)
              ORDER BY o.updated_at LIMIT ' . FEED_LIMIT,
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
        foreach (feed_rows($conn,
            'SELECT x.id, x.item, x.amount, x.discount, x.category, x.created_at at, pt.name paid_by_name
               FROM expenses x LEFT JOIN partners pt ON pt.id = x.paid_by
              WHERE x.created_at > ? AND x.created_at <= ?
              ORDER BY x.created_at LIMIT ' . FEED_LIMIT,
            'ss', [$after, $now], $capped) as $r) {
            $items[] = [
                'kind'     => 'expense',
                'id'       => (int)$r['id'],
                'order_id' => null,
                'at'       => $r['at'],
                'mine'     => false,
                'title'    => 'New expense · ' . feed_money((float)$r['amount'] - (float)$r['discount']),
                'body'     => $r['item'] . ' · ' . $r['category']
                    . ($r['paid_by_name'] ? ' · paid by ' . $r['paid_by_name'] : ''),
            ];
        }

        // Account movements. A settlement between partners is written as
        // two rows sharing a transfer_id; announce it once, from the payer.
        foreach (feed_rows($conn,
            'SELECT m.id, m.direction, m.kind, m.amount, m.invest_adjust, m.source, m.note,
                    m.created_at at, pt.name partner, cp.name counterparty
               FROM account_movements m
               LEFT JOIN partners pt ON pt.id = m.partner_id
               LEFT JOIN partners cp ON cp.id = m.counterparty_id
              WHERE m.created_at > ? AND m.created_at <= ?
                AND (m.transfer_id IS NULL OR m.direction = \'debit\')
              ORDER BY m.created_at LIMIT ' . FEED_LIMIT,
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
                'title'    => 'Movement · ' . ($credit ? '+' : '-') . feed_money($amount),
                'body'     => $what . ($r['source'] !== '' ? ' · ' . $r['source'] : '')
                    . ($r['note'] !== '' ? "\n" . $r['note'] : ''),
            ];
        }

        usort($items, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));

        // A kind that came back full has unread rows after $capped. Send
        // only what is older than that second, and set the cursor one
        // second before it, so the next call starts with that second's
        // rows instead of skipping them (the comparison is strict).
        $next = $now;
        if ($capped !== null) {
            $items = array_values(array_filter($items, fn($i) => (string)$i['at'] < $capped));
            $next = date('Y-m-d H:i:s', strtotime($capped) - 1);
        }

        api_ok(['now' => $next, 'items' => $items]);
    },
]);
