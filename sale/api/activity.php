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
require_once __DIR__ . '/../lib_activity.php';

$me = api_require_auth($conn);

/** 'Y-m-d H:i:s' from the request, or '' — never anything SQL could misread. */
function feed_cursor(): string {
    $raw = trim(api_str('after'));
    if ($raw === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
        throw new ApiInputError('after should look like 2026-09-25 14:05:00.');
    }
    return $raw;
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

        $items = activity_items($conn, $after, $now, (int)$me['id'], '>', $capped);

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
