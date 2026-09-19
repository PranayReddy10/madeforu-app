<?php
/**
 * Orders: list, read, create, update, payments, status, dispatch, delete.
 *
 * This is the API mirror of save.php and deliberately keeps every one of
 * its invariants:
 *   - prices are read from $ITEMS server-side, never from the request;
 *   - duplicate product lines merge;
 *   - extra_charge is added BEFORE the discount, so a discount can cancel
 *     a delivery fee;
 *   - an order can never be overpaid, and its total can never drop below
 *     what was already collected;
 *   - delivered implies ready (normalise_status);
 *   - an AWB forces ready, and clearing it clears the dispatch date.
 *
 * The one deliberate difference: the customer is optional here. A stall
 * sale where nobody wants to give a phone number becomes a walk-in order
 * (name 'Walk-in', phone ''), which is exactly what the counter needs and
 * what save.php's customer() refuses. See web-patch/ for the matching
 * six-line change to the website, without which the website's edit form
 * will refuse to save a walk-in order.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

// ── Input shaping ──────────────────────────────────────────────────

/**
 * Customer details, all optional.
 * A blank name becomes 'Walk-in'; a blank phone stays ''. A phone that is
 * present but not 10 digits is a typo worth stopping for — silently
 * storing it would break every wa.me link built from it later.
 */
function api_customer(): array {
    $name  = trim((string)api_in('name', ''));
    $rawPh = trim((string)api_in('phone', ''));
    $notes = trim((string)api_in('notes', ''));

    $phone = $rawPh === '' ? '' : normalise_phone($rawPh);
    if ($rawPh !== '' && strlen($phone) !== 10) {
        throw new ApiInputError('Phone must be 10 digits, or leave it empty for a walk-in sale.');
    }
    if ($name === '') $name = 'Walk-in';
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    return [$name, $phone, ($notes !== '' ? mb_substr($notes, 0, 255) : null)];
}

/**
 * Product lines. Accepts [{item, quantity}] — the app's shape — and also
 * the website's parallel item[]/quantity[] arrays, so the same endpoint
 * can be driven from a form while debugging.
 *
 * Prices come from $ITEMS. Duplicates merge, so "Cup x2" twice becomes
 * "Cup x4" rather than two lines that look like a double charge on the bill.
 *
 * $agreed is what makes a price rise safe. On an edit it carries the
 * unit_price each line was ALREADY sold at, and those lines keep it: a
 * sale is a thing that happened at a price, not a thing that gets
 * recalculated whenever the catalogue moves. Without it, raising Square
 * Magnet from 50 to 60 and then merely ticking an old order "ready"
 * turned a paid-in-full ₹500 sale into ₹600 with ₹100 apparently still
 * owed — money the customer never agreed to and nobody would ever
 * collect. Only lines that are genuinely new to the order are priced at
 * today's rate; passing [] (a new sale) prices everything at today's.
 *
 * $costs is the same rule for the cost side, frozen into
 * order_items.unit_cost so Stats cannot restate past profit either.
 */
function api_lines(array $ITEMS, array $agreed = [], array $costs = [], array $agreedCosts = []): array {
    $raw = api_in('items', null);
    $pairs = [];

    if (is_array($raw)) {
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $name = trim((string)($row['item'] ?? $row['name'] ?? ''));
            $qty  = (int)($row['quantity'] ?? $row['qty'] ?? 0);
            if ($name !== '') $pairs[] = [$name, $qty];
        }
    } else {
        $names = api_in('item', []);
        $qtys  = api_in('quantity', []);
        if (is_array($names) && is_array($qtys) && count($names) === count($qtys)) {
            foreach ($names as $i => $n) $pairs[] = [trim((string)$n), (int)$qtys[$i]];
        }
    }

    $merged = [];
    foreach ($pairs as [$name, $qty]) {
        if ($name === '') continue;
        // An item already on this order stays valid even if it has since
        // been retired from the catalogue — otherwise the day a product is
        // hidden, every past order containing it becomes uneditable and
        // nobody can so much as tick it delivered.
        if (!isset($ITEMS[$name]) && !array_key_exists($name, $agreed)) {
            throw new ApiInputError('That product is no longer in the catalogue: ' . $name);
        }
        if ($qty < 1 || $qty > 999) throw new ApiInputError('Quantity must be between 1 and 999.');
        $merged[$name] = ($merged[$name] ?? 0) + $qty;
    }
    if (!$merged) throw new ApiInputError('Add at least one product.');

    $out = []; $subtotal = 0.0; $repriced = [];
    foreach ($merged as $name => $qty) {
        // A line already on this order keeps the price it was sold at.
        // A line being added now is a new agreement, so it takes today's.
        $wasSold = array_key_exists($name, $agreed);
        $unit    = $wasSold ? (float)$agreed[$name] : (float)$ITEMS[$name];
        $cost    = array_key_exists($name, $agreedCosts)
            ? (float)$agreedCosts[$name]
            : (float)($costs[$name] ?? 0);
        $line    = round($unit * $qty, 2);
        $subtotal += $line;

        // Surfaced so the apps can say "this line is at the price it sold
        // at, the catalogue now says something else" rather than leaving
        // someone to wonder why the totals do not match the price list.
        if ($wasSold && isset($ITEMS[$name]) && abs($unit - (float)$ITEMS[$name]) > 0.001) {
            $repriced[] = ['item' => $name, 'sold_at' => round($unit, 2),
                           'catalogue' => round((float)$ITEMS[$name], 2)];
        }

        $out[] = ['item' => $name, 'qty' => $qty, 'unit' => $unit,
                  'cost' => $cost, 'lt' => $line];
    }
    return [$out, round($subtotal, 2), $repriced];
}

/**
 * What an order's lines were sold at: item => unit_price, and the costs
 * beside them. Read before an edit rewrites the rows, because the rewrite
 * is a DELETE followed by an INSERT and the old prices are gone after it.
 */
function api_sold_prices(mysqli $conn, int $orderId): array {
    // Same reason as api_write_lines: unit_cost may not exist yet on a
    // server where api/ was uploaded before the migration was run, and an
    // order nobody can edit is worse than one with no cost recorded.
    $hasCost = db_has_column($conn, 'order_items', 'unit_cost');
    $s = $conn->prepare(
        $hasCost
            ? 'SELECT item, unit_price, unit_cost FROM order_items WHERE order_id = ?'
            : 'SELECT item, unit_price FROM order_items WHERE order_id = ?'
    );
    $s->bind_param('i', $orderId);
    $s->execute();
    $res = $s->get_result();
    $prices = []; $costs = [];
    while ($r = $res->fetch_assoc()) {
        $prices[$r['item']] = (float)$r['unit_price'];
        $costs[$r['item']]  = (float)($r['unit_cost'] ?? 0);
    }
    $s->close();
    return [$prices, $costs];
}

/**
 * Write an order's lines, with the cost frozen on if the column is there.
 *
 * order_items.unit_cost arrives with a migration that is uploaded by hand
 * and may not have been run yet. Naming it unconditionally would mean a
 * shop that uploaded api/ first could not take an order at all, so the
 * statement adapts instead.
 */
function api_write_lines(mysqli $conn, int $orderId, array $rows): void {
    if (db_has_column($conn, 'order_items', 'unit_cost')) {
        $s = $conn->prepare(
            'INSERT INTO order_items (order_id, item, quantity, unit_price, unit_cost, line_total)
             VALUES (?,?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $s->bind_param('isiddd', $orderId, $r['item'], $r['qty'], $r['unit'], $r['cost'], $r['lt']);
            $s->execute();
        }
    } else {
        $s = $conn->prepare(
            'INSERT INTO order_items (order_id, item, quantity, unit_price, line_total)
             VALUES (?,?,?,?,?)'
        );
        foreach ($rows as $r) {
            $s->bind_param('isidd', $orderId, $r['item'], $r['qty'], $r['unit'], $r['lt']);
            $s->execute();
        }
    }
    $s->close();
}

/** Today's cost per item, for stamping onto a brand-new sale. */
function api_costs(mysqli $conn): array {
    $out = [];
    try {
        $res = $conn->query('SELECT item, unit_cost FROM product_costs');
        while ($res && ($r = $res->fetch_assoc())) $out[$r['item']] = (float)$r['unit_cost'];
    } catch (mysqli_sql_exception $e) { /* optional table */ }
    return $out;
}

function api_mode(): string {
    $m = strtolower(api_str('payment_mode', api_str('mode', 'cash')));
    return in_array($m, ['cash', 'upi', 'card', 'other'], true) ? $m : 'cash';
}

function api_extra_charge(): array {
    $x = api_float('extra_charge', 0.0);
    if ($x < 0) throw new ApiInputError('Additional charge cannot be negative.');
    $x = clamp_extra_charge($x);
    $r = api_str('extra_charge_reason');
    return [$x, ($r !== '' && $x > 0) ? mb_substr($r, 0, 120) : null];
}

function api_discount(float $subtotal, float $extra): array {
    $d = api_float('discount', 0.0);
    if ($d < 0) throw new ApiInputError('Discount cannot be negative.');
    $base = $subtotal + $extra;
    if ($d > $base + 0.001) {
        throw new ApiInputError('Discount of ' . money($d) . ' is more than the order value of ' . money($base) . '.');
    }
    $d = clamp_discount($subtotal, $d, $extra);
    $r = api_str('discount_reason');
    return [$d, ($r !== '' && $d > 0) ? mb_substr($r, 0, 120) : null];
}

/** Delhivery fields. An AWB with no date means it shipped today. */
function api_dispatch_fields(): array {
    if (!api_bool('is_online', true)) return [null, null];

    $awb = api_str('awb');
    if ($awb === '') return [null, null];
    if (strlen($awb) > 60) throw new ApiInputError('Tracking number is too long.');
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $awb)) {
        throw new ApiInputError('Tracking number should contain only letters, numbers and dashes.');
    }
    $date = api_date('dispatch_date', date('Y-m-d'));
    return [$awb, $date];
}

/** Validate an event id, or NULL for an offline/walk-up sale. */
function api_event_id(mysqli $conn): ?int {
    $v = api_in('event_id', null);
    if ($v === null || $v === '' || (int)$v < 1) return null;
    $id = (int)$v;
    $s = $conn->prepare('SELECT id FROM events WHERE id = ?');
    $s->bind_param('i', $id);
    $s->execute();
    $found = (bool)$s->get_result()->fetch_assoc();
    $s->close();
    return $found ? $id : null;   // deleted since the app cached it -> offline
}

// ── Reads ──────────────────────────────────────────────────────────

/** Full order + items + payments, the shape the detail screen renders. */
function load_order(mysqli $conn, int $id): array {
    $s = $conn->prepare(
        'SELECT o.*, e.name event_name, a.name created_by_name
           FROM orders o
           LEFT JOIN events e ON e.id = o.event_id
           LEFT JOIN admins a ON a.id = o.created_by
          WHERE o.id = ?'
    );
    $s->bind_param('i', $id);
    $s->execute();
    $o = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');

    $items = [];
    $s = $conn->prepare('SELECT id, item, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id');
    $s->bind_param('i', $id);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) {
        $items[] = ['id' => (int)$r['id'], 'item' => $r['item'], 'quantity' => (int)$r['quantity'],
                    'unit_price' => (float)$r['unit_price'], 'line_total' => (float)$r['line_total']];
    }
    $s->close();

    $payments = [];
    $s = $conn->prepare(
        'SELECT p.id, p.amount, p.mode, p.note, p.created_at, a.name taken_by_name
           FROM payments p LEFT JOIN admins a ON a.id = p.taken_by
          WHERE p.order_id = ? ORDER BY p.id'
    );
    $s->bind_param('i', $id);
    $s->execute();
    $res = $s->get_result();
    while ($r = $res->fetch_assoc()) {
        $payments[] = ['id' => (int)$r['id'], 'amount' => (float)$r['amount'], 'mode' => $r['mode'],
                       'note' => $r['note'], 'taken_by' => $r['taken_by_name'], 'created_at' => $r['created_at']];
    }
    $s->close();

    $itemsText = implode(', ', array_map(fn($i) => $i['item'] . ' x' . $i['quantity'], $items));
    $o['items_text'] = $itemsText;

    $row = api_order_row($o);
    $row['items']    = $items;
    $row['payments'] = $payments;
    $row['has_bill'] = bill_exists($conn, $id);

    // Server-owned wording: the same messages the website sends, so a
    // customer gets identical text whoever served them.
    $waOrder = whatsapp_message($o, $itemsText);
    $row['whatsapp'] = [
        'order_text'    => $waOrder,
        'order_link'    => $row['phone'] !== '' ? whatsapp_link($row['phone'], $waOrder) : null,
        'dispatch_text' => $row['awb'] ? dispatch_message($o) : null,
        'dispatch_link' => ($row['awb'] && $row['phone'] !== '') ? whatsapp_link($row['phone'], dispatch_message($o)) : null,
    ];
    return $row;
}

function bill_exists(mysqli $conn, int $orderId): bool {
    try {
        $s = $conn->prepare('SELECT id FROM bills WHERE order_id = ?');
        $s->bind_param('i', $orderId);
        $s->execute();
        $found = (bool)$s->get_result()->fetch_assoc();
        $s->close();
        return $found;
    } catch (mysqli_sql_exception $e) {
        return false;   // bills table not migrated yet
    }
}

/**
 * WHERE clauses + bind values for the list filters. Returns
 * [sql, types, params] so both the page query and the totals query can
 * share one definition — two copies would drift apart the first time a
 * filter changes.
 */
function list_filters(): array {
    $where = []; $types = ''; $params = [];

    $q = api_str('q');
    if ($q !== '') {
        // Search across the things a partner actually remembers: the order
        // number, who it was for, the phone, and the product on it.
        $where[] = "(o.order_no LIKE ? OR o.name LIKE ? OR o.phone LIKE ? OR o.awb LIKE ?
                     OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.item LIKE ?))";
        $like = '%' . $q . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    // 'all' = every channel, 'offline' = walk-up only, a number = one event.
    $event = api_str('event', 'all');
    if ($event === 'offline') {
        $where[] = 'o.event_id IS NULL';
    } elseif ($event !== 'all' && $event !== '' && ctype_digit($event)) {
        $where[] = 'o.event_id = ?';
        $types .= 'i'; $params[] = (int)$event;
    }

    // Payment filters mirror pay_status() exactly, 0.001 tolerance and all:
    // DECIMAL columns arrive as floats, and strict equality reports settled
    // orders as still owing.
    switch (api_str('pay', 'all')) {
        case 'paid':    $where[] = '(o.total <= 0.001 OR o.paid_amount >= o.total - 0.001)'; break;
        case 'unpaid':  $where[] = '(o.total > 0.001 AND o.paid_amount <= 0.001)'; break;
        case 'partial': $where[] = '(o.total > 0.001 AND o.paid_amount > 0.001 AND o.paid_amount < o.total - 0.001)'; break;
    }

    switch (api_str('status', 'all')) {
        case 'pending':    $where[] = 'o.is_ready = 0'; break;
        case 'ready':      $where[] = 'o.is_ready = 1 AND o.is_delivered = 0'; break;
        case 'delivered':  $where[] = 'o.is_delivered = 1'; break;
        // Dispatch is defined by having an AWB — there is no separate flag.
        case 'dispatched': $where[] = "COALESCE(o.awb,'') <> ''"; break;
    }

    $from = api_date('from');
    $to   = api_date('to');
    if ($from !== '') { $where[] = 'DATE(o.created_at) >= ?'; $types .= 's'; $params[] = $from; }
    if ($to   !== '') { $where[] = 'DATE(o.created_at) <= ?'; $types .= 's'; $params[] = $to; }

    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $types, $params];
}

function bind_and_run(mysqli $conn, string $sql, string $types, array $params) {
    $s = $conn->prepare($sql);
    if ($types !== '') $s->bind_param($types, ...$params);
    $s->execute();
    return [$s, $s->get_result()];
}

// ── Routes ─────────────────────────────────────────────────────────

api_dispatch([

    // ── GET list ───────────────────────────────────────────────────
    'list' => function () use ($conn) {
        [$whereSql, $types, $params] = list_filters();

        $limit  = max(1, min(api_int('limit', 40), MAX_PAGE_SIZE));
        $offset = max(0, api_int('offset', 0));

        $sql = 'SELECT o.*, e.name event_name, ' . ORDER_ITEMS_SUBQUERY . ' items_text
                  FROM orders o LEFT JOIN events e ON e.id = o.event_id'
             . $whereSql . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?';

        $pTypes  = $types . 'ii';
        $pParams = array_merge($params, [$limit + 1, $offset]);   // +1 probes for a next page
        [$s, $res] = bind_and_run($conn, $sql, $pTypes, $pParams);

        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = api_order_row($r);
        $s->close();

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        // Totals for the whole filtered set, not just this page — the
        // header says "₹12,400 across 38 orders", which would be a lie if
        // it only counted the 40 rows currently scrolled into view.
        $sumSql = 'SELECT COUNT(*) n, COALESCE(SUM(o.total),0) total,
                          COALESCE(SUM(o.paid_amount),0) paid
                     FROM orders o' . $whereSql;
        [$s2, $res2] = bind_and_run($conn, $sumSql, $types, $params);
        $sum = $res2->fetch_assoc();
        $s2->close();

        $total = (float)$sum['total']; $paid = (float)$sum['paid'];
        api_ok([
            'orders'   => $rows,
            'has_more' => $hasMore,
            'summary'  => [
                'count'   => (int)$sum['n'],
                'total'   => round($total, 2),
                'paid'    => round($paid, 2),
                'balance' => round(max($total - $paid, 0), 2),
            ],
        ]);
    },

    // ── GET get&id= ────────────────────────────────────────────────
    'get' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid order id.');
        api_ok(['order' => load_order($conn, $id)]);
    },

    // ── POST create ────────────────────────────────────────────────
    'create' => function () use ($conn, $ITEMS, $me) {
        [$name, $phone, $notes] = api_customer();
        // A new sale: everything at today's price, and today's cost
        // frozen alongside it so profit on this sale never moves again.
        [$rows, $subtotal]      = api_lines($ITEMS, [], api_costs($conn));
        [$extra, $extraReason]  = api_extra_charge();
        [$disc, $discReason]    = api_discount($subtotal, $extra);
        $eventId                = api_event_id($conn);

        $total = round($subtotal + $extra - $disc, 2);

        $paid = api_float('paid_amount', 0.0);
        if ($paid < 0)               throw new ApiInputError('Payment cannot be negative.');
        if ($paid > $total + 0.001)  throw new ApiInputError('Payment cannot be more than the total of ' . money($total) . '.');
        $paid = min($paid, $total);

        [$ready, $delivered] = normalise_status(
            api_bool('is_ready') ? 1 : 0,
            api_bool('is_delivered') ? 1 : 0
        );

        $conn->begin_transaction();
        try {
            // Retry the number, not the whole order: two counter staff
            // billing in the same second can read the same MAX.
            $orderId = 0;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $orderNo = generate_order_no($conn);
                try {
                    $s = $conn->prepare(
                        'INSERT INTO orders (order_no, name, phone, event_id, subtotal, discount,
                                             discount_reason, extra_charge, extra_charge_reason,
                                             total, paid_amount, is_ready, is_delivered, notes, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)'
                    );
                    $s->bind_param('sssiddsdsdiisi', $orderNo, $name, $phone, $eventId, $subtotal,
                                   $disc, $discReason, $extra, $extraReason, $total,
                                   $ready, $delivered, $notes, $me['id']);
                    $s->execute();
                    $orderId = (int)$conn->insert_id;
                    $s->close();
                    break;
                } catch (mysqli_sql_exception $dup) {
                    if ($dup->getCode() !== 1062 || $attempt === 2) throw $dup;
                }
            }

            api_write_lines($conn, $orderId, $rows);

            if ($paid > 0.001) {
                $mode = api_mode();
                $note = 'Initial payment';
                $s = $conn->prepare(
                    'INSERT INTO payments (order_id, amount, mode, note, taken_by) VALUES (?,?,?,?,?)'
                );
                $s->bind_param('idssi', $orderId, $paid, $mode, $note, $me['id']);
                $s->execute();
                $s->close();
            }

            recalc_total($conn, $orderId);
            recalc_paid($conn, $orderId);
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        $order = load_order($conn, $orderId);
        api_ok([
            'order'   => $order,
            'message' => 'Order ' . $order['order_no'] . ' created — ' . money($order['total'])
                       . ($order['balance'] > 0.001 ? ', balance ' . money($order['balance']) : ', fully paid'),
        ]);
    },

    // ── POST update ────────────────────────────────────────────────
    'update' => function () use ($conn, $ITEMS) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid order id.');

        // Read the agreed prices BEFORE the rewrite below deletes them.
        [$soldPrices, $soldCosts] = api_sold_prices($conn, $id);

        [$name, $phone, $notes] = api_customer();
        [$rows, $subtotal, $repriced] = api_lines($ITEMS, $soldPrices, api_costs($conn), $soldCosts);
        [$extra, $extraReason]  = api_extra_charge();
        [$disc, $discReason]    = api_discount($subtotal, $extra);
        [$awb, $dispatchDate]   = api_dispatch_fields();

        $total = round($subtotal + $extra - $disc, 2);

        $s = $conn->prepare('SELECT paid_amount, event_id FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $cur = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$cur) api_fail(404, 'not_found', 'That order no longer exists.');

        // Lowering the total below money already collected would mean the
        // customer overpaid and the balance went negative. Say which lever
        // to pull rather than just refusing.
        $alreadyPaid = (float)$cur['paid_amount'];
        if ($total < $alreadyPaid - 0.001) {
            throw new ApiInputError('New total ' . money($total) . ' is less than the ' . money($alreadyPaid)
                . ' already collected. Reduce the discount, add back items or charges, or delete a payment first.');
        }

        $ready     = api_bool('is_ready') ? 1 : 0;
        $delivered = api_bool('is_delivered') ? 1 : 0;
        if ($awb !== null) $ready = 1;    // a tracked parcel was necessarily made
        [$ready, $delivered] = normalise_status($ready, $delivered);

        // The website cannot move an order between events yet; the app can,
        // because the column and the credit guard already exist. Moving a
        // credited order is refused: its revenue is already in a partner's
        // ledger under the old channel.
        $moveEvent = api_in('event_id', '__keep__');
        $newEvent  = $moveEvent === '__keep__' ? null : api_event_id($conn);
        $changing  = $moveEvent !== '__keep__'
                     && (int)($cur['event_id'] ?? 0) !== (int)($newEvent ?? 0);
        if ($changing) {
            $c = $conn->prepare('SELECT credited_mov_id FROM orders WHERE id = ?');
            $c->bind_param('i', $id);
            $c->execute();
            $cr = $c->get_result()->fetch_assoc();
            $c->close();
            if (!empty($cr['credited_mov_id'])) {
                throw new ApiInputError('This order\'s revenue was already credited to a partner, so it cannot change event.');
            }
        }

        $conn->begin_transaction();
        try {
            $s = $conn->prepare(
                'UPDATE orders SET name=?, phone=?, notes=?, discount=?, discount_reason=?,
                                   extra_charge=?, extra_charge_reason=?,
                                   is_ready=?, is_delivered=?, awb=?, dispatch_date=? WHERE id=?'
            );
            $s->bind_param('sssdsdsiissi', $name, $phone, $notes, $disc, $discReason,
                           $extra, $extraReason, $ready, $delivered, $awb, $dispatchDate, $id);
            $s->execute();
            $s->close();

            if ($changing) {
                $s = $conn->prepare('UPDATE orders SET event_id = ? WHERE id = ?');
                $s->bind_param('ii', $newEvent, $id);
                $s->execute();
                $s->close();
            }

            $s = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();

            api_write_lines($conn, $id, $rows);

            recalc_total($conn, $id);
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        // `repriced` names any line still charged at what it sold for
        // while the catalogue has since moved. It is not a warning that
        // something went wrong — it is the guarantee working, said out
        // loud so nobody has to wonder why the total is not the price
        // list times the quantity.
        api_ok([
            'order'    => load_order($conn, $id),
            'repriced' => $repriced,
            'message'  => $repriced
                ? 'Order updated. ' . count($repriced) . ' line'
                    . (count($repriced) === 1 ? '' : 's')
                    . ' kept the price it was sold at.'
                : 'Order updated.',
        ]);
    },

    // ── POST add_payment ───────────────────────────────────────────
    'add_payment' => function () use ($conn, $me) {
        $id  = api_int('id');
        $amt = api_float('amount', 0.0);
        if ($id < 1)   throw new ApiInputError('Invalid order id.');
        if ($amt <= 0) throw new ApiInputError('Enter an amount greater than zero.');

        $s = $conn->prepare('SELECT total, paid_amount FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');

        $balance = (float)$o['total'] - (float)$o['paid_amount'];
        if ($balance <= 0.001) throw new ApiInputError('This order is already fully paid.');
        if ($amt > $balance + 0.001) {
            throw new ApiInputError('Payment of ' . money($amt) . ' is more than the balance of ' . money($balance) . '.');
        }

        $mode = api_mode();
        $note = api_str('note');
        $note = $note !== '' ? mb_substr($note, 0, 120) : null;

        $conn->begin_transaction();
        try {
            $s = $conn->prepare('INSERT INTO payments (order_id, amount, mode, note, taken_by) VALUES (?,?,?,?,?)');
            $s->bind_param('idssi', $id, $amt, $mode, $note, $me['id']);
            $s->execute();
            $s->close();
            recalc_paid($conn, $id);
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        $newBal = round($balance - $amt, 2);
        api_ok([
            'order'   => load_order($conn, $id),
            'message' => money($amt) . ' received. '
                       . ($newBal > 0.001 ? 'Balance now ' . money($newBal) . '.' : 'Order fully paid.'),
        ]);
    },

    // ── POST delete_payment ────────────────────────────────────────
    'delete_payment' => function () use ($conn) {
        $id  = api_int('id');
        $pid = api_int('payment_id');
        if ($id < 1 || $pid < 1) throw new ApiInputError('Invalid payment.');

        $conn->begin_transaction();
        try {
            $s = $conn->prepare('DELETE FROM payments WHERE id = ? AND order_id = ?');
            $s->bind_param('ii', $pid, $id);
            $s->execute();
            $gone = $s->affected_rows;
            $s->close();
            if ($gone < 1) { $conn->rollback(); api_fail(404, 'not_found', 'That payment was already removed.'); }
            recalc_paid($conn, $id);
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }
        api_ok(['order' => load_order($conn, $id), 'message' => 'Payment removed.']);
    },

    // ── POST toggle {id, field} ────────────────────────────────────
    'toggle' => function () use ($conn) {
        $id    = api_int('id');
        $field = api_str('field');
        if ($id < 1) throw new ApiInputError('Invalid order id.');
        if (!in_array($field, ['is_ready', 'is_delivered'], true)) throw new ApiInputError('Invalid field.');

        $s = $conn->prepare('SELECT is_ready, is_delivered FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');

        $ready     = (int)$o['is_ready'];
        $delivered = (int)$o['is_delivered'];
        $note      = null;

        if ($field === 'is_delivered') {
            $delivered = 1 - $delivered;
            if ($delivered === 1 && $ready === 0) { $ready = 1; $note = 'Marked delivered. Ready was set automatically.'; }
        } else {
            $ready = 1 - $ready;
            if ($ready === 0 && $delivered === 1) { $delivered = 0; $note = 'Marked not ready. Delivery was cleared.'; }
        }
        [$ready, $delivered] = normalise_status($ready, $delivered);

        $s = $conn->prepare('UPDATE orders SET is_ready = ?, is_delivered = ? WHERE id = ?');
        $s->bind_param('iii', $ready, $delivered, $id);
        $s->execute();
        $s->close();

        api_ok(['order' => load_order($conn, $id), 'message' => $note ?? 'Status updated.']);
    },

    // ── POST dispatch {id, awb, dispatch_date} ─────────────────────
    // Split out from update() so the courier desk can scan an AWB onto an
    // order without re-sending the whole basket.
    'dispatch' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid order id.');

        $s = $conn->prepare('SELECT event_id, is_delivered FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');

        [$awb, $date] = api_dispatch_fields();
        if ($awb !== null && $o['event_id'] !== null) {
            throw new ApiInputError('Only direct orders are shipped. An event sale is handed over at the stall.');
        }

        $ready     = $awb !== null ? 1 : (int)$o['is_delivered'];
        $delivered = (int)$o['is_delivered'];
        [$ready, $delivered] = normalise_status($ready, $delivered);

        $s = $conn->prepare('UPDATE orders SET awb = ?, dispatch_date = ?, is_ready = ?, is_delivered = ? WHERE id = ?');
        $s->bind_param('ssiii', $awb, $date, $ready, $delivered, $id);
        $s->execute();
        $s->close();

        api_ok(['order' => load_order($conn, $id),
                'message' => $awb !== null ? 'Tracking number saved.' : 'Tracking cleared.']);
    },

    // ── POST delete ────────────────────────────────────────────────
    'delete' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid order id.');

        // A credited order's revenue already sits in a partner's ledger.
        // Deleting it would leave that credit pointing at nothing and the
        // partner's balance permanently wrong.
        $s = $conn->prepare('SELECT credited_mov_id FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $o = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$o) api_fail(404, 'not_found', 'That order no longer exists.');
        if (!empty($o['credited_mov_id'])) {
            throw new ApiInputError('This order was already credited to a partner. Reverse the credit on the website before deleting it.');
        }

        $s = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $s->close();
        api_ok(['message' => 'Order deleted.']);
    },
]);