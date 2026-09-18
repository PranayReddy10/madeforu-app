<?php
/**
 * Statistics for the dashboard and the reports screen.
 *
 * Two different profit numbers live here on purpose, because the business
 * asks two different questions:
 *
 *   product_profit = revenue - (units sold x unit_cost)
 *
 * unit_cost is read from order_items, where it was frozen at the moment
 * of sale, and only falls back to the live product_costs table for rows
 * written before that column existed. Reading it live meant that putting
 * up what an item costs us restated the profit on every sale ever made —
 * the cost-side twin of repricing a completed order.
 *       "are we pricing the mugs right?" Uses the per-event cost override
 *       when the order belongs to an event, exactly like event_costs.php.
 *
 *   business_profit = revenue - (expenses.amount - expenses.discount)
 *       "what did the business actually make?" This is the basis
 *       investment.php distributes to partners, so the app must report the
 *       same figure or the two will disagree in front of a partner.
 *
 * Every range is inclusive by calendar day (DATE(created_at) BETWEEN),
 * which is what a partner means by "1st to 15th".
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

/** Default range: the current month, which is what the dashboard opens on. */
function stats_range(): array {
    $from = api_date('from', date('Y-m-01'));
    $to   = api_date('to',   date('Y-m-d'));
    if ($to < $from) [$from, $to] = [$to, $from];
    return [$from, $to];
}

/** The equally-long window immediately before, for "vs last period". */
function previous_range(string $from, string $to): array {
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    return [date('Y-m-d', strtotime($from . ' -' . $days . ' day')),
            date('Y-m-d', strtotime($from . ' -1 day'))];
}

/** Optional event filter shared by every query on this page. */
/**
 * What one unit of a sold item cost us.
 *
 * Preferring the frozen order_items.unit_cost is what stops a change to
 * our own costs restating the profit on every sale ever made. It falls
 * back to the live tables for rows written before that column existed —
 * and for a server where api/ was uploaded before the migration ran, in
 * which case the column is not there to select at all.
 */
function cost_expr(mysqli $conn): string {
    return db_has_column($conn, 'order_items', 'unit_cost')
        ? 'COALESCE(NULLIF(oi.unit_cost, 0), eic.unit_cost, pc.unit_cost, 0)'
        : 'COALESCE(eic.unit_cost, pc.unit_cost, 0)';
}

function stats_event_clause(): array {
    $event = api_str('event', 'all');
    if ($event === 'offline') return [' AND o.event_id IS NULL', '', []];
    if ($event !== 'all' && ctype_digit($event)) return [' AND o.event_id = ?', 'i', [(int)$event]];
    return ['', '', []];
}

function stats_headline(mysqli $conn, string $from, string $to): array {
    [$evSql, $evTypes, $evParams] = stats_event_clause();

    $sql = 'SELECT COUNT(*) orders,
                   COALESCE(SUM(o.total),0)        revenue,
                   COALESCE(SUM(o.paid_amount),0)  collected,
                   COALESCE(SUM(o.discount),0)     discount,
                   COALESCE(SUM(o.extra_charge),0) extra,
                   SUM(CASE WHEN o.is_delivered = 1 THEN 1 ELSE 0 END) delivered,
                   SUM(CASE WHEN o.is_ready = 1 AND o.is_delivered = 0 THEN 1 ELSE 0 END) ready,
                   SUM(CASE WHEN o.is_ready = 0 THEN 1 ELSE 0 END) pending,
                   SUM(CASE WHEN COALESCE(o.awb,\'\') <> \'\' THEN 1 ELSE 0 END) dispatched
              FROM orders o
             WHERE DATE(o.created_at) BETWEEN ? AND ?' . $evSql;

    $s = $conn->prepare($sql);
    $types  = 'ss' . $evTypes;
    $params = array_merge([$from, $to], $evParams);
    $s->bind_param($types, ...$params);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();

    $revenue   = (float)$r['revenue'];
    $collected = (float)$r['collected'];
    $orders    = (int)$r['orders'];

    return [
        'orders'      => $orders,
        'revenue'     => round($revenue, 2),
        'collected'   => round($collected, 2),
        'outstanding' => round(max($revenue - $collected, 0), 2),
        'avg_order'   => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
        'discount'    => round((float)$r['discount'], 2),
        'extra'       => round((float)$r['extra'], 2),
        'delivered'   => (int)$r['delivered'],
        'ready'       => (int)$r['ready'],
        'pending'     => (int)$r['pending'],
        'dispatched'  => (int)$r['dispatched'],
    ];
}

/**
 * Cost of what was sold. COALESCE picks the per-event cost first (a stall
 * pays different packing/printing costs), then the global product cost,
 * then 0 — an item nobody has costed yet counts as free rather than
 * dropping the line out of the total.
 */
function stats_cogs(mysqli $conn, string $from, string $to): float {
    [$evSql, $evTypes, $evParams] = stats_event_clause();

    $sql = 'SELECT COALESCE(SUM(oi.quantity * ' . cost_expr($conn) . '),0) cogs
              FROM order_items oi
              JOIN orders o ON o.id = oi.order_id
              LEFT JOIN event_item_costs eic ON eic.event_id = o.event_id AND eic.item = oi.item
              LEFT JOIN product_costs    pc  ON pc.item = oi.item
             WHERE DATE(o.created_at) BETWEEN ? AND ?' . $evSql;

    $s = $conn->prepare($sql);
    $s->bind_param('ss' . $evTypes, ...array_merge([$from, $to], $evParams));
    $s->execute();
    $v = (float)($s->get_result()->fetch_assoc()['cogs'] ?? 0);
    $s->close();
    return round($v, 2);
}

/** Expenses on the same basis investment.php uses: amount minus discount. */
function stats_expenses(mysqli $conn, string $from, string $to): float {
    try {
        $s = $conn->prepare(
            'SELECT COALESCE(SUM(amount - discount),0) v FROM expenses WHERE exp_date BETWEEN ? AND ?'
        );
        $s->bind_param('ss', $from, $to);
        $s->execute();
        $v = (float)($s->get_result()->fetch_assoc()['v'] ?? 0);
        $s->close();
        return round($v, 2);
    } catch (mysqli_sql_exception $e) {
        return 0.0;
    }
}

api_dispatch([

    /**
     * GET dashboard — the home screen in one request: headline numbers,
     * the same numbers for the previous window, today's snapshot, and the
     * live work queues a partner acts on.
     */
    'dashboard' => function () use ($conn) {
        [$from, $to] = stats_range();
        [$pFrom, $pTo] = previous_range($from, $to);

        $now  = stats_headline($conn, $from, $to);
        $prev = stats_headline($conn, $pFrom, $pTo);

        $cogs     = stats_cogs($conn, $from, $to);
        $expenses = stats_expenses($conn, $from, $to);

        $today      = stats_headline($conn, date('Y-m-d'), date('Y-m-d'));
        $todayCogs  = stats_cogs($conn, date('Y-m-d'), date('Y-m-d'));

        // Work queues: what is unfinished right now, regardless of the
        // selected range. A stale order from last month still needs making.
        $q = $conn->query(
            "SELECT
               (SELECT COUNT(*) FROM orders WHERE is_ready = 0) to_make,
               (SELECT COUNT(*) FROM orders WHERE is_ready = 1 AND is_delivered = 0) to_hand_over,
               (SELECT COUNT(*) FROM orders WHERE total > 0.001 AND paid_amount < total - 0.001) owing,
               (SELECT COALESCE(SUM(total - paid_amount),0) FROM orders
                 WHERE total > 0.001 AND paid_amount < total - 0.001) owed_amount"
        )->fetch_assoc();

        $delta = function (float $a, float $b): ?float {
            // No baseline means no percentage. Showing "+100%" against zero
            // reads as growth when it only means "the last window was empty".
            if ($b <= 0.001) return null;
            return round(($a - $b) / $b * 100, 1);
        };

        api_ok([
            'range'    => ['from' => $from, 'to' => $to],
            'previous' => ['from' => $pFrom, 'to' => $pTo],
            'headline' => $now + [
                'product_profit'  => round($now['revenue'] - $cogs, 2),
                'cogs'            => $cogs,
                'expenses'        => $expenses,
                'business_profit' => round($now['revenue'] - $expenses, 2),
                'margin_pct'      => $now['revenue'] > 0.001
                                     ? round(($now['revenue'] - $cogs) / $now['revenue'] * 100, 1) : null,
            ],
            'change'   => [
                'revenue'   => $delta($now['revenue'],   $prev['revenue']),
                'orders'    => $delta((float)$now['orders'], (float)$prev['orders']),
                'collected' => $delta($now['collected'], $prev['collected']),
                'avg_order' => $delta($now['avg_order'], $prev['avg_order']),
            ],
            'previous_headline' => $prev,
            'today'    => $today + ['product_profit' => round($today['revenue'] - $todayCogs, 2)],
            'queues'   => [
                'to_make'      => (int)$q['to_make'],
                'to_hand_over' => (int)$q['to_hand_over'],
                'owing'        => (int)$q['owing'],
                'owed_amount'  => round((float)$q['owed_amount'], 2),
            ],
        ]);
    },

    /**
     * GET series — revenue/orders per bucket for the chart.
     * Buckets are built in PHP from a day-grouped query so empty days
     * still appear: a gap in a line chart reads as "no data", but a zero
     * reads as "no sales", and only one of those is true.
     */
    'series' => function () use ($conn) {
        [$from, $to] = stats_range();
        $bucket = api_str('bucket', 'day');
        if (!in_array($bucket, ['day', 'week', 'month'], true)) $bucket = 'day';

        [$evSql, $evTypes, $evParams] = stats_event_clause();
        $s = $conn->prepare(
            'SELECT DATE(o.created_at) d, COUNT(*) n,
                    COALESCE(SUM(o.total),0) revenue, COALESCE(SUM(o.paid_amount),0) collected
               FROM orders o
              WHERE DATE(o.created_at) BETWEEN ? AND ?' . $evSql . '
              GROUP BY DATE(o.created_at) ORDER BY d'
        );
        $s->bind_param('ss' . $evTypes, ...array_merge([$from, $to], $evParams));
        $s->execute();
        $res = $s->get_result();

        $byDay = [];
        while ($r = $res->fetch_assoc()) {
            $byDay[$r['d']] = ['n' => (int)$r['n'], 'revenue' => (float)$r['revenue'],
                               'collected' => (float)$r['collected']];
        }
        $s->close();

        $points = [];
        $cursor = strtotime($from);
        $end    = strtotime($to);
        while ($cursor <= $end) {
            $day = date('Y-m-d', $cursor);
            $key = match ($bucket) {
                'week'  => date('o-\WW', $cursor),
                'month' => date('Y-m', $cursor),
                default => $day,
            };
            $label = match ($bucket) {
                'week'  => 'W' . date('W', $cursor),
                'month' => date('M y', $cursor),
                default => date('d M', $cursor),
            };
            if (!isset($points[$key])) {
                $points[$key] = ['key' => $key, 'label' => $label, 'date' => $day,
                                 'orders' => 0, 'revenue' => 0.0, 'collected' => 0.0];
            }
            if (isset($byDay[$day])) {
                $points[$key]['orders']    += $byDay[$day]['n'];
                $points[$key]['revenue']   += $byDay[$day]['revenue'];
                $points[$key]['collected'] += $byDay[$day]['collected'];
            }
            $cursor = strtotime('+1 day', $cursor);
        }

        $out = array_map(function (array $p) {
            $p['revenue']   = round($p['revenue'], 2);
            $p['collected'] = round($p['collected'], 2);
            return $p;
        }, array_values($points));

        api_ok(['range' => ['from' => $from, 'to' => $to], 'bucket' => $bucket, 'points' => $out]);
    },

    /** GET breakdown — the three donut/bar charts on the stats screen. */
    'breakdown' => function () use ($conn) {
        [$from, $to] = stats_range();
        [$evSql, $evTypes, $evParams] = stats_event_clause();
        $baseTypes  = 'ss' . $evTypes;
        $baseParams = array_merge([$from, $to], $evParams);

        // Top products, by revenue, with the profit each one contributed.
        $s = $conn->prepare(
            'SELECT oi.item,
                    SUM(oi.quantity) qty,
                    SUM(oi.line_total) revenue,
                    SUM(oi.quantity * ' . cost_expr($conn) . ') cost
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN event_item_costs eic ON eic.event_id = o.event_id AND eic.item = oi.item
               LEFT JOIN product_costs    pc  ON pc.item = oi.item
              WHERE DATE(o.created_at) BETWEEN ? AND ?' . $evSql . '
              GROUP BY oi.item ORDER BY revenue DESC'
        );
        $s->bind_param($baseTypes, ...$baseParams);
        $s->execute();
        $res = $s->get_result();
        $products = [];
        while ($r = $res->fetch_assoc()) {
            $rev = (float)$r['revenue']; $cost = (float)$r['cost'];
            $products[] = ['item' => $r['item'], 'qty' => (int)$r['qty'],
                           'revenue' => round($rev, 2), 'cost' => round($cost, 2),
                           'profit' => round($rev - $cost, 2),
                           'margin_pct' => $rev > 0.001 ? round(($rev - $cost) / $rev * 100, 1) : null];
        }
        $s->close();

        // Money actually collected, split by how it came in.
        $s = $conn->prepare(
            'SELECT p.mode, COUNT(*) n, COALESCE(SUM(p.amount),0) amount
               FROM payments p JOIN orders o ON o.id = p.order_id
              WHERE DATE(p.created_at) BETWEEN ? AND ?' . $evSql . '
              GROUP BY p.mode ORDER BY amount DESC'
        );
        $s->bind_param($baseTypes, ...$baseParams);
        $s->execute();
        $res = $s->get_result();
        $modes = [];
        while ($r = $res->fetch_assoc()) {
            $modes[] = ['mode' => $r['mode'], 'count' => (int)$r['n'], 'amount' => round((float)$r['amount'], 2)];
        }
        $s->close();

        // Channel: each event against the walk-up/direct pile.
        $s = $conn->prepare(
            'SELECT COALESCE(e.name, \'Direct / walk-up\') channel, COUNT(*) n,
                    COALESCE(SUM(o.total),0) revenue
               FROM orders o LEFT JOIN events e ON e.id = o.event_id
              WHERE DATE(o.created_at) BETWEEN ? AND ?
              GROUP BY COALESCE(e.name, \'Direct / walk-up\') ORDER BY revenue DESC'
        );
        $s->bind_param('ss', $from, $to);
        $s->execute();
        $res = $s->get_result();
        $channels = [];
        while ($r = $res->fetch_assoc()) {
            $channels[] = ['channel' => $r['channel'], 'orders' => (int)$r['n'],
                           'revenue' => round((float)$r['revenue'], 2)];
        }
        $s->close();

        // When the counter is busy — drives the "best hours" bar strip.
        $s = $conn->prepare(
            'SELECT HOUR(o.created_at) h, COUNT(*) n, COALESCE(SUM(o.total),0) revenue
               FROM orders o
              WHERE DATE(o.created_at) BETWEEN ? AND ?' . $evSql . '
              GROUP BY HOUR(o.created_at) ORDER BY h'
        );
        $s->bind_param($baseTypes, ...$baseParams);
        $s->execute();
        $res = $s->get_result();
        $hours = array_fill(0, 24, ['hour' => 0, 'orders' => 0, 'revenue' => 0.0]);
        for ($h = 0; $h < 24; $h++) $hours[$h] = ['hour' => $h, 'orders' => 0, 'revenue' => 0.0];
        while ($r = $res->fetch_assoc()) {
            $hours[(int)$r['h']] = ['hour' => (int)$r['h'], 'orders' => (int)$r['n'],
                                    'revenue' => round((float)$r['revenue'], 2)];
        }
        $s->close();

        // Repeat customers are identified by phone, so walk-ins (phone '')
        // are excluded — otherwise every anonymous sale would look like one
        // enormous returning customer.
        $s = $conn->prepare(
            'SELECT o.phone, MAX(o.name) name, COUNT(*) n, COALESCE(SUM(o.total),0) spent
               FROM orders o
              WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.phone <> \'\'
              GROUP BY o.phone HAVING n > 0 ORDER BY spent DESC LIMIT 10'
        );
        $s->bind_param('ss', $from, $to);
        $s->execute();
        $res = $s->get_result();
        $customers = [];
        while ($r = $res->fetch_assoc()) {
            $customers[] = ['phone' => $r['phone'], 'name' => $r['name'],
                            'orders' => (int)$r['n'], 'spent' => round((float)$r['spent'], 2)];
        }
        $s->close();

        api_ok([
            'range'     => ['from' => $from, 'to' => $to],
            'products'  => $products,
            'modes'     => $modes,
            'channels'  => $channels,
            'hours'     => array_values($hours),
            'customers' => $customers,
        ]);
    },
]);
