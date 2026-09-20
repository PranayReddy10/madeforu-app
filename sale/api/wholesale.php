<?php
/**
 * Wholesale buyers — the phone's half of wholesale.php.
 *
 * The same island rule as the website page, and it matters more here
 * because an API is where things quietly get joined to other things:
 *
 *   these are NOT orders — nothing written here reaches the order book,
 *   the dispatch list or a bill;
 *
 *   they are NOT revenue or profit — finance.php, stats.php and
 *   lib_money.php do not read these tables, and must not start;
 *
 *   they do NOT move stock.
 *
 * Prices are stored exactly as sent and never looked up from the
 * catalogue. A wholesale price is negotiated, is not the counter price,
 * and must not move when the catalogue moves.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

/** Has the migration been run? The SQL lands separately from the PHP. */
function ws_ready(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query('SELECT 1 FROM wholesale_customers LIMIT 1'); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    return $ok;
}

function ws_require(mysqli $conn): void {
    if (!ws_ready($conn)) {
        throw new ApiInputError('Wholesale is not set up on the server yet. '
            . 'Run sale/api/migrations/2026-09-wholesale.sql.');
    }
}

/** Every buyer, with how often they come and what they have taken. */
function ws_customers(mysqli $conn): array {
    $out = [];
    $res = $conn->query(
        'SELECT c.id, c.name, c.phone, c.shop, c.place, c.notes, c.is_active,
                (SELECT COUNT(*) FROM wholesale_visits v WHERE v.customer_id = c.id) visits,
                (SELECT MAX(v.visit_date) FROM wholesale_visits v WHERE v.customer_id = c.id) last_visit,
                (SELECT COALESCE(SUM(i.line_total),0)
                   FROM wholesale_items i
                   JOIN wholesale_visits v ON v.id = i.visit_id
                  WHERE v.customer_id = c.id) taken
           FROM wholesale_customers c
          ORDER BY c.is_active DESC, c.name'
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $out[] = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'phone'      => $r['phone'],
            'shop'       => $r['shop'],
            'place'      => $r['place'],
            'notes'      => $r['notes'],
            'is_active'  => (int)$r['is_active'] === 1,
            'visits'     => (int)$r['visits'],
            'last_visit' => $r['last_visit'],
            'taken'      => round((float)$r['taken'], 2),
        ];
    }
    return $out;
}

/** One buyer: every visit with its lines, plus what they buy overall. */
function ws_customer(mysqli $conn, int $id): array {
    $s = $conn->prepare('SELECT * FROM wholesale_customers WHERE id = ?');
    $s->bind_param('i', $id);
    $s->execute();
    $c = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$c) api_fail(404, 'not_found', 'That buyer no longer exists.');

    $s = $conn->prepare(
        'SELECT v.id, v.visit_date, v.note, v.created_at, a.name AS by_name
           FROM wholesale_visits v
           LEFT JOIN admins a ON a.id = v.created_by
          WHERE v.customer_id = ?
          ORDER BY v.visit_date DESC, v.id DESC'
    );
    $s->bind_param('i', $id);
    $s->execute();
    $visitRows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    $lines = [];
    if ($visitRows) {
        $ids = implode(',', array_map(fn($v) => (int)$v['id'], $visitRows));
        $res = $conn->query(
            "SELECT visit_id, item, quantity, unit_price, line_total
               FROM wholesale_items WHERE visit_id IN ($ids) ORDER BY id"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $lines[(int)$r['visit_id']][] = [
                'item'       => $r['item'],
                'quantity'   => (int)$r['quantity'],
                'unit_price' => round((float)$r['unit_price'], 2),
                'line_total' => round((float)$r['line_total'], 2),
            ];
        }
    }

    $visits = [];
    $total = 0.0; $units = 0;
    foreach ($visitRows as $v) {
        $mine = $lines[(int)$v['id']] ?? [];
        $vt = 0.0;
        foreach ($mine as $l) { $vt += $l['line_total']; $units += $l['quantity']; }
        $total += $vt;
        $visits[] = [
            'id'    => (int)$v['id'],
            'date'  => $v['visit_date'],
            'note'  => $v['note'],
            'by'    => $v['by_name'],
            'total' => round($vt, 2),
            'items' => $mine,
        ];
    }

    // What they take, across every visit. The price range is the useful
    // part: it shows where it has moved between visits.
    $s = $conn->prepare(
        'SELECT i.item, SUM(i.quantity) qty, SUM(i.line_total) total,
                MIN(i.unit_price) lo, MAX(i.unit_price) hi,
                MAX(v.visit_date) last_date, COUNT(DISTINCT v.id) times
           FROM wholesale_items i
           JOIN wholesale_visits v ON v.id = i.visit_id
          WHERE v.customer_id = ?
          GROUP BY i.item
          ORDER BY total DESC'
    );
    $s->bind_param('i', $id);
    $s->execute();
    $sumRows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();

    $summary = array_map(fn(array $r): array => [
        'item'      => $r['item'],
        'times'     => (int)$r['times'],
        'qty'       => (int)$r['qty'],
        'low'       => round((float)$r['lo'], 2),
        'high'      => round((float)$r['hi'], 2),
        'total'     => round((float)$r['total'], 2),
        'last_date' => $r['last_date'],
    ], $sumRows);

    return [
        'customer' => [
            'id'        => (int)$c['id'],
            'name'      => $c['name'],
            'phone'     => $c['phone'],
            'shop'      => $c['shop'],
            'place'     => $c['place'],
            'notes'     => $c['notes'],
            'is_active' => (int)$c['is_active'] === 1,
        ],
        'visits'  => $visits,
        'summary' => $summary,
        'totals'  => [
            'visits' => count($visits),
            'units'  => $units,
            'value'  => round($total, 2),
        ],
    ];
}

api_dispatch([

    // ── GET list ──────────────────────────────────────────────────
    'list' => function () use ($conn) {
        if (!ws_ready($conn)) {
            // Named rather than returned as an empty list: an app that
            // silently shows nothing makes a missing migration look
            // like a broken screen.
            api_ok(['ready' => false, 'customers' => [],
                    'message' => 'Wholesale is not set up on the server yet. '
                               . 'Run sale/api/migrations/2026-09-wholesale.sql.']);
        }
        api_ok(['ready' => true, 'customers' => ws_customers($conn)]);
    },

    // ── GET get&id= ───────────────────────────────────────────────
    'get' => function () use ($conn) {
        ws_require($conn);
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Which buyer?');
        api_ok(ws_customer($conn, $id));
    },

    // ── POST add_customer / update_customer ───────────────────────
    'add_customer' => function () use ($conn) {
        ws_require($conn);
        $name = trim(api_str('name'));
        if ($name === '') throw new ApiInputError('A name is required.');
        $phone = mb_substr(trim(api_str('phone')), 0, 20);
        $shop  = mb_substr(trim(api_str('shop')), 0, 160);
        $place = mb_substr(trim(api_str('place')), 0, 160);
        $notes = mb_substr(trim(api_str('notes')), 0, 500);

        $s = $conn->prepare(
            'INSERT INTO wholesale_customers (name, phone, shop, place, notes) VALUES (?,?,?,?,?)'
        );
        $s->bind_param('sssss', $name, $phone, $shop, $place, $notes);
        $s->execute();
        $id = (int)$s->insert_id;
        $s->close();

        api_ok(['id' => $id, 'customers' => ws_customers($conn),
                'message' => $name . ' added.']);
    },

    'update_customer' => function () use ($conn) {
        ws_require($conn);
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Which buyer?');
        $name = trim(api_str('name'));
        if ($name === '') throw new ApiInputError('A name is required.');
        $phone = mb_substr(trim(api_str('phone')), 0, 20);
        $shop  = mb_substr(trim(api_str('shop')), 0, 160);
        $place = mb_substr(trim(api_str('place')), 0, 160);
        $notes = mb_substr(trim(api_str('notes')), 0, 500);

        $s = $conn->prepare(
            'UPDATE wholesale_customers SET name=?, phone=?, shop=?, place=?, notes=? WHERE id=?'
        );
        $s->bind_param('sssssi', $name, $phone, $shop, $place, $notes, $id);
        $s->execute();
        $s->close();

        api_ok(['customers' => ws_customers($conn), 'message' => 'Saved.']);
    },

    // ── POST add_visit ────────────────────────────────────────────
    //
    // items: [{item, quantity, unit_price}, …]. Lines are validated in
    // full before anything is written, so a bad one leaves no half a
    // visit behind.
    'add_visit' => function () use ($conn, $me) {
        ws_require($conn);
        $cid = api_int('customer_id');
        if ($cid < 1) throw new ApiInputError('Which buyer?');

        $chk = $conn->prepare('SELECT name FROM wholesale_customers WHERE id = ?');
        $chk->bind_param('i', $cid);
        $chk->execute();
        $cust = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$cust) api_fail(404, 'not_found', 'That buyer no longer exists.');

        $date = api_date('visit_date', date('Y-m-d'));
        $note = mb_substr(trim(api_str('note')), 0, 500);

        $items = api_in('items', []);
        if (!is_array($items) || !$items) throw new ApiInputError('Add at least one product.');

        $rows = []; $total = 0.0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)($it['item'] ?? ''));
            if ($name === '') continue;
            $name = mb_substr($name, 0, 120);
            $q = (int)($it['quantity'] ?? 0);
            $p = round((float)($it['unit_price'] ?? 0), 2);
            if ($q < 1) throw new ApiInputError('Quantity for "' . $name . '" must be at least 1.');
            if ($p < 0) throw new ApiInputError('Price for "' . $name . '" cannot be negative.');
            $lt = round($q * $p, 2);
            $total += $lt;
            $rows[] = [$name, $q, $p, $lt];
        }
        if (!$rows) throw new ApiInputError('Add at least one product.');

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'INSERT INTO wholesale_visits (customer_id, visit_date, note, created_by)
                 VALUES (?,?,?,?)'
            );
            $s->bind_param('issi', $cid, $date, $note, $me['id']);
            $s->execute();
            $vid = (int)$s->insert_id;
            $s->close();

            $s = $conn->prepare(
                'INSERT INTO wholesale_items (visit_id, item, quantity, unit_price, line_total)
                 VALUES (?,?,?,?,?)'
            );
            foreach ($rows as [$n, $q, $p, $lt]) {
                $s->bind_param('isidd', $vid, $n, $q, $p, $lt);
                $s->execute();
            }
            $s->close();
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        api_ok(ws_customer($conn, $cid) + [
            'message' => count($rows) . ' item' . (count($rows) === 1 ? '' : 's')
                       . ' recorded for ' . $cust['name'] . ' — ' . money($total) . '.',
        ]);
    },

    // ── POST delete_visit ─────────────────────────────────────────
    'delete_visit' => function () use ($conn) {
        ws_require($conn);
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Which entry?');

        $s = $conn->prepare('SELECT customer_id FROM wholesale_visits WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $v = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$v) api_fail(404, 'not_found', 'That entry no longer exists.');
        $cid = (int)$v['customer_id'];

        // The lines go with it: the foreign key cascades.
        $s = $conn->prepare('DELETE FROM wholesale_visits WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $s->close();

        api_ok(ws_customer($conn, $cid) + ['message' => 'Entry removed.']);
    },
]);
