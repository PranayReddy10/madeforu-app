<?php
/**
 * How much money the business took in — the one definition.
 *
 * ⚠ This file exists because the same arithmetic used to live in
 * investment.php and again in api/finance.php, and two copies of a
 * revenue rule means two answers to "what did we earn" with no way to
 * tell which is right. The website and the API both include this.
 *
 * Requires nothing but a live mysqli connection.
 */
declare(strict_types=1);

/**
 * ₹1,98,014.16 — Indian grouping, and the minus before the symbol.
 *
 * config.php currently defines money() as
 *
 *     function money($v) { return '₹' . number_format((float)$v, 2); }
 *
 * which gives ₹198,014.16 in Western grouping, and ₹-157,378.29 with the
 * sign in the wrong place. The apps print -₹1,57,378.29 for the same
 * figure, so one number read two ways depending on the screen.
 *
 * config.php is not in the repository (it holds the database password),
 * so this cannot simply be changed here. To fix the format everywhere,
 * REPLACE that one line in config.php on the server with the body below.
 *
 * Do NOT delete it. config.php is what every page of the website loads,
 * and eighteen of them call money() without ever seeing this file --
 * products.php, expenses.php, movements.php and the rest -- so deleting
 * the line takes the whole site down with an undefined function. (Tested:
 * it does.)
 *
 * The definition here is therefore guarded and normally dormant. It
 * exists so this file is usable on its own, and so a config.php that
 * has never had money() still works.
 */
if (!function_exists('money')) {
    function money($v): string {
        $n = (float)$v;
        $abs = number_format(abs($n), 2, '.', '');
        [$whole, $frac] = explode('.', $abs);
        // Indian grouping: the last three digits, then pairs.
        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest  = substr($whole, 0, -3);
            $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $last3;
        }
        return ($n < 0 ? '-₹' : '₹') . $whole . '.' . $frac;
    }
}

/**
 * Everything the business took in, from every channel.
 *
 * Orders are not the whole story: money also arrives as account
 * movements — a Meesho payout, a marketplace settlement — for goods sold
 * without an order being written. Counting only orders understated
 * revenue, and therefore profit, by exactly that amount.
 *
 * The danger in adding credits is double-counting, because most credits
 * are order money being moved into a partner's account rather than new
 * money:
 *
 *   offline credits stamp orders.credited_mov_id, so that order revenue
 *   is already in the orders total;
 *
 *   event credits carry event_id and credit_event() caps them at that
 *   event's own order revenue, so they too are already counted. Only any
 *   amount credited BEYOND an event's orders is new money, which is what
 *   the max(0, credited − orders) below picks up;
 *
 *   profit distributions, settlements and settle-ups are credits that
 *   move money that already exists between partners. Counting them as
 *   revenue would inflate it every time profit is shared out;
 *
 *   payouts (kind 'payout') are a marketplace settling up for orders
 *   that are already on the books. Amazon sells on Monday and pays in a
 *   lump on Friday: the sale was revenue on Monday, and counting the
 *   Friday money again would book it twice. The kind = 'normal' filters
 *   exclude them on their own; the per-event query has to say so
 *   explicitly, because it does not filter on kind at all.
 *
 * This is what makes it safe to enter marketplace orders properly
 * rather than only recording the payout. Credits already in the table
 * keep the kind they have, so no past total moves.
 *
 * What is left — a credit with no event, not stamped on an order, of
 * kind 'normal' — is a sale that happened somewhere the order book does
 * not reach, and it is revenue.
 */
function revenue_sources(mysqli $conn): array {
    $orders = (float)($conn->query('SELECT COALESCE(SUM(total),0) v FROM orders')
                           ->fetch_assoc()['v'] ?? 0);

    // Credits that are order money in a different place.
    $offline = (float)($conn->query(
        "SELECT COALESCE(SUM(amount),0) v FROM account_movements
          WHERE direction = 'credit'
            AND id IN (SELECT credited_mov_id FROM orders WHERE credited_mov_id IS NOT NULL)"
    )->fetch_assoc()['v'] ?? 0);

    // Per event: what its orders came to, and what was credited for it.
    $eventCredited = 0.0; $eventExtra = 0.0;
    $res = $conn->query(
        "SELECT e.id,
                COALESCE((SELECT SUM(o.total) FROM orders o WHERE o.event_id = e.id),0) ord_rev,
                COALESCE((SELECT SUM(m.amount) FROM account_movements m
                           WHERE m.event_id = e.id AND m.direction = 'credit'
                             AND m.kind <> 'payout'),0) credited
           FROM events e"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $c = (float)$r['credited']; $o = (float)$r['ord_rev'];
        $eventCredited += $c;
        // Credited beyond what the event's own orders came to: money that
        // is not in the orders total, so it counts.
        if ($c > $o) $eventExtra += ($c - $o);
    }

    // New money: a plain credit, not tied to an event, not stamped on an
    // order, and not partners moving money between themselves.
    $extra = (float)($conn->query(
        "SELECT COALESCE(SUM(amount),0) v FROM account_movements
          WHERE direction = 'credit'
            AND kind = 'normal'
            AND event_id IS NULL
            AND id NOT IN (SELECT credited_mov_id FROM orders WHERE credited_mov_id IS NOT NULL)"
    )->fetch_assoc()['v'] ?? 0);

    $other = round($extra + $eventExtra, 2);

    // Named, so the Money screen can show where the extra came from
    // rather than presenting a number nobody can trace.
    $bySource = [];
    $res = $conn->query(
        "SELECT COALESCE(NULLIF(TRIM(source), ''), 'Other credits') src,
                COUNT(*) n, COALESCE(SUM(amount),0) v
           FROM account_movements
          WHERE direction = 'credit' AND kind = 'normal' AND event_id IS NULL
            AND id NOT IN (SELECT credited_mov_id FROM orders WHERE credited_mov_id IS NOT NULL)
          GROUP BY src ORDER BY v DESC"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $bySource[] = ['source' => $r['src'], 'count' => (int)$r['n'],
                       'amount' => round((float)$r['v'], 2)];
    }
    if ($eventExtra > 0.005) {
        $bySource[] = ['source' => 'Credited above event sales', 'count' => 0,
                       'amount' => round($eventExtra, 2)];
    }

    return [
        'orders'       => round($orders, 2),
        'other'        => $other,
        'total'        => round($orders + $other, 2),
        'by_source'    => $bySource,
        // Shown so the sum is checkable: these are credits that look like
        // extra revenue but are the order money already counted.
        'already_counted' => [
            'offline_credits' => round($offline, 2),
            'event_credits'   => round($eventCredited - $eventExtra, 2),
        ],
    ];
}


/**
 * Revenue split by where the sale came from.
 *
 * Two kinds of row, which are not the same thing and are not merged:
 *
 *   orders carrying a channel — a real sale, with products and a cost,
 *   counted the day it was written;
 *
 *   credits tagged with a channel but backed by no order — a sale that
 *   happened somewhere the order book does not reach. Money in, but no
 *   product detail and therefore no profit for it.
 *
 * Orders written before channels existed have no channel to report, and
 * are gathered under "Not recorded" rather than being quietly assigned
 * to Direct. Guessing would put a number on the screen that nobody
 * measured.
 *
 * Payouts are absent by construction: kind = 'normal' excludes them,
 * which is the whole point — the orders they settle are already the
 * first kind of row above.
 */
function revenue_by_channel(mysqli $conn): array {
    $rows = [];

    // The website is uploaded file by file, so this function will exist
    // for a while on a database that has no channels table yet. An
    // empty breakdown is a screen with one section missing; an
    // unguarded query there is the Money page replaced by a 500.
    try {
        $conn->query('SELECT 1 FROM channels LIMIT 1');
    } catch (Throwable $e) {
        return [];
    }

    $res = $conn->query(
        'SELECT c.id, c.name, COUNT(o.id) n, COALESCE(SUM(o.total),0) v
           FROM orders o
           LEFT JOIN channels c ON c.id = o.channel_id
          GROUP BY c.id, c.name
          ORDER BY v DESC'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = [
            'channel_id' => $r['id'] === null ? null : (int)$r['id'],
            'channel'    => $r['name'] ?? 'Not recorded',
            'orders'     => (int)$r['n'],
            'order_rev'  => round((float)$r['v'], 2),
            'credit_rev' => 0.0,
        ];
    }

    // Channel-tagged credits that are genuinely new money, using the same
    // exclusions revenue_sources() applies, so the two always agree.
    $res = $conn->query(
        "SELECT m.channel_id, c.name, COALESCE(SUM(m.amount),0) v
           FROM account_movements m
           LEFT JOIN channels c ON c.id = m.channel_id
          WHERE m.direction = 'credit' AND m.kind = 'normal'
            AND m.event_id IS NULL AND m.channel_id IS NOT NULL
            AND m.id NOT IN (SELECT credited_mov_id FROM orders WHERE credited_mov_id IS NOT NULL)
          GROUP BY m.channel_id, c.name"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $cid = (int)$r['channel_id'];
        $found = false;
        foreach ($rows as $i => $row) {
            if ($row['channel_id'] === $cid) {
                $rows[$i]['credit_rev'] = round((float)$r['v'], 2);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = [
                'channel_id' => $cid,
                'channel'    => $r['name'] ?? 'Not recorded',
                'orders'     => 0,
                'order_rev'  => 0.0,
                'credit_rev' => round((float)$r['v'], 2),
            ];
        }
    }

    foreach ($rows as $i => $r) {
        $rows[$i]['total'] = round($r['order_rev'] + $r['credit_rev'], 2);
    }
    usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
    return $rows;
}
