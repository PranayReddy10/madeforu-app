<?php
/**
 * Catalogue, events and the one-shot bootstrap the app pulls at launch.
 *
 * Renaming and deleting products is deliberately NOT exposed here. The
 * database joins products by NAME across products, product_costs,
 * product_stock, purchases, stock_ledger, event_item_costs and
 * order_items; a rename has to propagate through all seven in one
 * transaction (products.php does exactly that). A phone in a noisy stall
 * is the wrong place to run that, so the app can add products, reprice
 * them, reorder and hide them — everything that is safe — and links out
 * to the website for the rest.
 */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = api_require_auth($conn);

function catalog_products(mysqli $conn, bool $includeHidden = false): array {
    // The COLLATE is not decoration. `products` was created under MariaDB's
    // newer default (utf8mb4_uca1400_ai_ci) while product_costs and
    // product_stock are utf8mb4_unicode_ci, so joining their name columns
    // raises "Illegal mix of collations" and the whole catalogue call
    // fails. Forcing one side makes the comparison well-defined. Both are
    // utf8mb4, so no character is lost in the conversion.
    // image_url and product_url were added to the website for the public
    // menu.php catalogue and share.php. COALESCE rather than a bare select:
    // an install that has not run that migration yet simply reports empty
    // strings instead of failing the whole catalogue call.
    $sql = 'SELECT p.id, p.name, p.price, p.is_active, p.sort_order,
                   COALESCE(p.image_url, \'\') image_url,
                   COALESCE(p.product_url, \'\') product_url,
                   COALESCE(c.unit_cost, 0) unit_cost,
                   COALESCE(s.qty_on_hand, 0) qty_on_hand
              FROM products p
              LEFT JOIN product_costs c ON c.item = p.name COLLATE utf8mb4_unicode_ci
              LEFT JOIN product_stock s ON s.item = p.name COLLATE utf8mb4_unicode_ci'
         . ($includeHidden ? '' : ' WHERE p.is_active = 1')
         . ' ORDER BY p.sort_order, p.name';

    $out = [];
    try {
        $res = $conn->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $price = (float)$r['price'];
            $cost  = (float)$r['unit_cost'];
            $out[] = [
                'id'          => (int)$r['id'],
                'name'        => $r['name'],
                'price'       => $price,
                'unit_cost'   => $cost,
                'margin'      => round($price - $cost, 2),
                'margin_pct'  => $price > 0.001 ? round(($price - $cost) / $price * 100, 1) : null,
                'is_active'   => (int)$r['is_active'] === 1,
                'sort_order'  => (int)$r['sort_order'],
                'qty_on_hand' => (float)$r['qty_on_hand'],
                'image_url'   => (string)($r['image_url'] ?? ''),
                'product_url' => (string)($r['product_url'] ?? ''),
            ];
        }
    } catch (mysqli_sql_exception $e) {
        // products table missing (pre-migration install): fall back to the
        // hardcoded catalogue so the app can still take an order.
        global $ITEMS;
        $i = 0;
        foreach ($ITEMS as $name => $price) {
            $out[] = ['id' => --$i, 'name' => $name, 'price' => (float)$price, 'unit_cost' => 0.0,
                      'margin' => (float)$price, 'margin_pct' => 100.0, 'is_active' => true,
                      'sort_order' => 0, 'qty_on_hand' => 0.0,
                      'image_url' => '', 'product_url' => ''];
        }
    }
    return $out;
}

function catalog_events(mysqli $conn, bool $activeOnly = false): array {
    $sql = "SELECT e.id, e.name, e.is_paid, e.entry_cost, e.start_date, e.end_date,
                   e.is_active, e.notes, e.extra_cost,
                   (SELECT COUNT(*) FROM orders o WHERE o.event_id = e.id) order_count,
                   (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.event_id = e.id) revenue
              FROM events e"
         . ($activeOnly ? ' WHERE e.is_active = 1' : '')
         . ' ORDER BY e.is_active DESC, COALESCE(e.start_date, e.created_at) DESC, e.id DESC';

    $out = [];
    $res = $conn->query($sql);
    while ($res && ($r = $res->fetch_assoc())) {
        $out[] = [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'is_paid'     => (int)$r['is_paid'] === 1,
            'entry_cost'  => (float)$r['entry_cost'],
            'extra_cost'  => (float)($r['extra_cost'] ?? 0),
            'start_date'  => $r['start_date'],
            'end_date'    => $r['end_date'],
            'is_active'   => (int)$r['is_active'] === 1,
            'notes'       => $r['notes'],
            'order_count' => (int)$r['order_count'],
            'revenue'     => (float)$r['revenue'],
        ];
    }
    return $out;
}

/**
 * Open orders whose lines are not at today's catalogue price.
 *
 * One definition, used by both the preview and the write, so what is
 * shown and what happens cannot drift apart.
 */
function reprice_candidates(mysqli $conn, string $item = ''): array {
    $sql =
        'SELECT o.id, o.order_no, o.name customer, o.created_at,
                o.total, o.paid_amount, o.discount, o.extra_charge,
                SUM(oi.quantity * oi.unit_price)                    old_lines,
                SUM(ROUND(p.price * oi.quantity, 2))                new_lines,
                GROUP_CONCAT(CONCAT(oi.item, " ", oi.quantity, " x ",
                             oi.unit_price, " -> ", p.price) SEPARATOR ", ") lines_text
           FROM orders o
           JOIN order_items oi ON oi.order_id = o.id
           JOIN products p ON p.name = oi.item COLLATE utf8mb4_unicode_ci
          WHERE o.is_delivered = 0
            AND o.paid_amount < o.total - 0.001
            AND ABS(p.price - oi.unit_price) > 0.001'
        . ($item !== '' ? ' AND oi.item = ?' : '')
        . ' GROUP BY o.id ORDER BY o.created_at DESC';

    $s = $conn->prepare($sql);
    if ($item !== '') $s->bind_param('s', $item);
    $s->execute();
    $res = $s->get_result();

    $rows = []; $net = 0.0;
    while ($r = $res->fetch_assoc()) {
        // The order total is its lines less discount plus extras, so the
        // change to the total is exactly the change to the lines.
        $delta    = round((float)$r['new_lines'] - (float)$r['old_lines'], 2);
        $newTotal = round((float)$r['total'] + $delta, 2);
        $rows[] = [
            'id'          => (int)$r['id'],
            'order_no'    => $r['order_no'],
            'customer'    => $r['customer'],
            'created_at'  => $r['created_at'],
            'total'       => round((float)$r['total'], 2),
            'new_total'   => $newTotal,
            'change'      => $delta,
            'paid_amount' => round((float)$r['paid_amount'], 2),
            'lines'       => $r['lines_text'],
            // A cut that would take the total under what has been paid
            // cannot be applied; the preview says so rather than the
            // write failing later.
            'blocked'     => $newTotal < (float)$r['paid_amount'] - 0.001,
        ];
        $net += $delta;
    }
    $s->close();

    return ['orders' => $rows, 'count' => count($rows), 'net_change' => round($net, 2),
            'item' => $item];
}

/** Today's selling price and unit cost for one product. */
function product_current_figures(mysqli $conn, int $id, string $name): array {
    $s = $conn->prepare('SELECT price FROM products WHERE id = ?');
    $s->bind_param('i', $id);
    $s->execute();
    $price = (float)($s->get_result()->fetch_assoc()['price'] ?? 0);
    $s->close();

    $cost = 0.0;
    try {
        $s = $conn->prepare('SELECT unit_cost FROM product_costs WHERE item = ?');
        $s->bind_param('s', $name);
        $s->execute();
        $cost = (float)($s->get_result()->fetch_assoc()['unit_cost'] ?? 0);
        $s->close();
    } catch (mysqli_sql_exception $e) { /* optional table */ }

    return [$price, $cost];
}

/**
 * Append a row to the price history.
 *
 * Never fails the price change itself: the history is a record of what
 * happened, and refusing a legitimate price rise because a log table is
 * missing would be the tail wagging the dog.
 */
function record_price_change(mysqli $conn, int $id, string $name,
                             float $price, float $cost, ?int $by): void {
    try {
        $s = $conn->prepare(
            'INSERT INTO product_price_history (product_id, item, price, unit_cost, changed_by)
             VALUES (?,?,?,?,?)'
        );
        $s->bind_param('isddi', $id, $name, $price, $cost, $by);
        $s->execute();
        $s->close();
    } catch (mysqli_sql_exception $e) { /* table arrives with the migration */ }
}

/** How many orders already contain this item — i.e. how many are protected. */
function past_sales_count(mysqli $conn, string $name): int {
    $s = $conn->prepare('SELECT COUNT(DISTINCT order_id) n FROM order_items WHERE item = ?');
    $s->bind_param('s', $name);
    $s->execute();
    $n = (int)($s->get_result()->fetch_assoc()['n'] ?? 0);
    $s->close();
    return $n;
}

api_dispatch([

    /**
     * Everything the app needs to render its first screen in one round
     * trip: catalogue, events, partners, payment modes and the business
     * identity. One request on a stall's 3G beats five.
     */
    'bootstrap' => function () use ($conn, $me) {
        $partners = [];
        $res = $conn->query('SELECT id, name, is_active FROM partners ORDER BY is_active DESC, name');
        while ($res && ($r = $res->fetch_assoc())) {
            $partners[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'is_active' => (int)$r['is_active'] === 1];
        }

        $categories = [];
        try {
            $res = $conn->query('SELECT id, name FROM expense_categories ORDER BY sort_order, name');
            while ($res && ($r = $res->fetch_assoc())) {
                $categories[] = ['id' => (int)$r['id'], 'name' => $r['name']];
            }
        } catch (mysqli_sql_exception $e) { /* optional table */ }

        api_ok([
            'admin'         => ['id' => $me['id'], 'name' => $me['name'], 'phone' => $me['phone']],
            'products'      => catalog_products($conn),
            'events'        => catalog_events($conn, true),
            'partners'      => $partners,
            'categories'    => $categories,
            'payment_modes' => [['key' => 'cash', 'label' => 'Cash'], ['key' => 'upi', 'label' => 'UPI'],
                                ['key' => 'card', 'label' => 'Card'], ['key' => 'other', 'label' => 'Other']],
            'settings'      => app_settings($conn),
            'api_version'   => API_VERSION,
            'server_time'   => date('c'),
        ]);
    },

    'products' => function () use ($conn) {
        api_ok(['products' => catalog_products($conn, api_bool('include_hidden'))]);
    },

    // ── POST add_product {name, price, unit_cost} ──────────────────
    'add_product' => function () use ($conn) {
        $name  = api_str('name');
        $price = api_float('price', -1);
        $cost  = api_float('unit_cost', 0);

        if ($name === '')            throw new ApiInputError('Give the product a name.');
        if (mb_strlen($name) > 60)   throw new ApiInputError('Product name is too long (60 characters max).');
        if ($price < 0)              throw new ApiInputError('Enter a selling price.');
        if ($cost < 0)               throw new ApiInputError('Cost cannot be negative.');

        $s = $conn->prepare('SELECT id FROM products WHERE name = ?');
        $s->bind_param('s', $name);
        $s->execute();
        $exists = (bool)$s->get_result()->fetch_assoc();
        $s->close();
        if ($exists) throw new ApiInputError('A product called "' . $name . '" already exists.');

        $conn->begin_transaction();
        try {
            $next = (int)($conn->query('SELECT COALESCE(MAX(sort_order),0)+1 n FROM products')->fetch_assoc()['n']);
            $s = $conn->prepare('INSERT INTO products (name, price, sort_order) VALUES (?,?,?)');
            $s->bind_param('sdi', $name, $price, $next);
            $s->execute();
            $s->close();

            // Seed the cost row too, so the margin report has a number to
            // work with instead of silently treating the item as free.
            $s = $conn->prepare(
                'INSERT INTO product_costs (item, unit_cost) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE unit_cost = VALUES(unit_cost)'
            );
            $s->bind_param('sd', $name, $cost);
            $s->execute();
            $s->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['products' => catalog_products($conn, true), 'message' => $name . ' added.']);
    },

    // ── POST update_product {id, price?, unit_cost?, is_active?} ───
    'update_product' => function () use ($conn, $me) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid product.');

        $s = $conn->prepare('SELECT name FROM products WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) api_fail(404, 'not_found', 'That product no longer exists.');
        $name = $row['name'];

        // What it was, before this call changes anything — the history row
        // written at the end is only worth writing if something moved.
        [$wasPrice, $wasCost] = product_current_figures($conn, $id, $name);

        if (api_in('price', null) !== null) {
            $price = api_float('price', -1);
            if ($price < 0) throw new ApiInputError('Price cannot be negative.');
            $s = $conn->prepare('UPDATE products SET price = ? WHERE id = ?');
            $s->bind_param('di', $price, $id);
            $s->execute();
            $s->close();
        }
        if (api_in('unit_cost', null) !== null) {
            $cost = api_float('unit_cost', -1);
            if ($cost < 0) throw new ApiInputError('Cost cannot be negative.');
            $s = $conn->prepare(
                'INSERT INTO product_costs (item, unit_cost) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE unit_cost = VALUES(unit_cost)'
            );
            $s->bind_param('sd', $name, $cost);
            $s->execute();
            $s->close();
        }
        if (api_in('is_active', null) !== null) {
            $active = api_bool('is_active') ? 1 : 0;
            $s = $conn->prepare('UPDATE products SET is_active = ? WHERE id = ?');
            $s->bind_param('ii', $active, $id);
            $s->execute();
            $s->close();
        }
        // The catalogue photo and the shop link, same columns products.php
        // writes. Both are NOT NULL DEFAULT '', so clearing one stores ''.
        foreach (['image_url', 'product_url'] as $col) {
            if (api_in($col, null) === null) continue;
            $value = api_str($col);
            if (mb_strlen($value) > 500) throw new ApiInputError('That URL is too long (500 characters max).');
            // Column names cannot be bound, so the name comes from this
            // fixed list and never from the request.
            $s = $conn->prepare("UPDATE products SET $col = ? WHERE id = ?");
            $s->bind_param('si', $value, $id);
            $s->execute();
            $s->close();
        }

        [$nowPrice, $nowCost] = product_current_figures($conn, $id, $name);
        $priceMoved = abs($nowPrice - $wasPrice) > 0.001;
        $costMoved  = abs($nowCost - $wasCost) > 0.001;

        $message = $name . ' updated.';
        if ($priceMoved || $costMoved) {
            record_price_change($conn, $id, $name, $nowPrice, $nowCost, $me['id'] ?? null);

            // Said plainly, because this is the question anyone asking for
            // a price rise actually has. Past sales are untouched: each
            // order_items row keeps the unit_price it was sold at, and an
            // edit to an old order now keeps it too (orders.php).
            $past = past_sales_count($conn, $name);
            if ($priceMoved) {
                $message = $name . ' is now ' . money($nowPrice)
                    . ' (was ' . money($wasPrice) . '). This applies to new sales only'
                    . ($past > 0
                        ? '; the ' . $past . ' order' . ($past === 1 ? '' : 's')
                          . ' already sold keep the price they were sold at.'
                        : '.');
            }
        }

        api_ok([
            'products' => catalog_products($conn, true),
            'price_changed' => $priceMoved,
            'was_price'     => round($wasPrice, 2),
            'now_price'     => round($nowPrice, 2),
            'past_orders'   => ($priceMoved || $costMoved) ? past_sales_count($conn, $name) : 0,
            'message'       => $message,
        ]);
    },

    /**
     * GET reprice_preview&item= — which OPEN orders a price change would
     * move, and by how much. Writes nothing.
     *
     * "Open" means not yet delivered AND not yet paid in full. Both have
     * to be true. A delivered order is goods the customer has, at the
     * price that was agreed; a fully-paid one is money already taken at
     * that price. Either way the sale is done, and changing its total
     * afterwards invents a balance nobody agreed to — which is exactly
     * what used to happen by accident and is now deliberate and narrow.
     *
     * Omit `item` to see every product whose catalogue price has moved
     * away from what open orders are holding.
     */
    'reprice_preview' => function () use ($conn) {
        api_ok(reprice_candidates($conn, api_str('item')));
    },

    /**
     * POST reprice_apply {order_ids:[...], item}
     *
     * Applies today's catalogue price to the lines named, and only to
     * orders that are still open when the write happens — re-checked
     * here rather than trusted from the preview, because the preview may
     * have been on screen while someone else delivered or took payment.
     *
     * A price CUT that would drop a total below what has already been
     * collected is skipped, not clamped: the alternative is an order
     * that quietly disagrees with its own payments.
     */
    'reprice_apply' => function () use ($conn) {
        $ids = api_in('order_ids', []);
        if (!is_array($ids) || !$ids) throw new ApiInputError('Choose at least one order.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) throw new ApiInputError('Choose at least one order.');
        if (count($ids) > 500) throw new ApiInputError('Too many orders at once — do it in batches.');

        $item = api_str('item');

        // Re-derive the candidates instead of taking amounts from the
        // request: the client says WHICH orders, never what they are worth.
        $fresh = reprice_candidates($conn, $item);
        $allowed = [];
        foreach ($fresh['orders'] as $o) $allowed[$o['id']] = $o;

        $done = []; $skipped = []; $moved = 0.0;

        $conn->begin_transaction();
        try {
            foreach ($ids as $id) {
                if (!isset($allowed[$id])) {
                    $skipped[] = ['id' => $id, 'why' => 'no longer open, or already at the current price'];
                    continue;
                }
                $o = $allowed[$id];
                if ($o['new_total'] < $o['paid_amount'] - 0.001) {
                    $skipped[] = ['id' => $id, 'order_no' => $o['order_no'],
                                  'why' => 'the new total ' . money($o['new_total'])
                                           . ' is below the ' . money($o['paid_amount']) . ' already collected'];
                    continue;
                }

                $u = $conn->prepare(
                    'UPDATE order_items oi
                       JOIN products p ON p.name = oi.item COLLATE utf8mb4_unicode_ci
                        SET oi.unit_price = p.price,
                            oi.line_total = ROUND(p.price * oi.quantity, 2)
                      WHERE oi.order_id = ?'
                    . ($item !== '' ? ' AND oi.item = ?' : '')
                );
                if ($item !== '') $u->bind_param('is', $id, $item);
                else              $u->bind_param('i', $id);
                $u->execute();
                $u->close();

                recalc_total($conn, $id);

                $done[] = ['id' => $id, 'order_no' => $o['order_no'],
                           'was' => $o['total'], 'now' => $o['new_total'],
                           'change' => $o['change']];
                $moved += $o['change'];
            }
            $conn->commit();
        } catch (Exception $ex) {
            $conn->rollback();
            throw $ex;
        }

        api_ok([
            'updated' => $done,
            'skipped' => $skipped,
            'message' => count($done) . ' order' . (count($done) === 1 ? '' : 's') . ' repriced'
                . ($moved != 0.0 ? ', ' . ($moved > 0 ? 'up ' : 'down ') . money(abs($moved)) : '')
                . (count($skipped) ? '. ' . count($skipped) . ' skipped.' : '.'),
        ]);
    },

    /**
     * GET price_history&item= — what this product has cost and sold for.
     *
     * Newest first. The apps show it beside the price field so a partner
     * changing a price can see what it used to be, and see for themselves
     * that past sales are recorded separately from the current figure.
     */
    'price_history' => function () use ($conn) {
        $item = api_str('item');
        if ($item === '') throw new ApiInputError('Which product?');

        $out = [];
        try {
            $s = $conn->prepare(
                'SELECT h.price, h.unit_cost, h.changed_at, h.note, a.name changed_by
                   FROM product_price_history h
                   LEFT JOIN admins a ON a.id = h.changed_by
                  WHERE h.item = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 40'
            );
            $s->bind_param('s', $item);
            $s->execute();
            $res = $s->get_result();
            while ($r = $res->fetch_assoc()) {
                $out[] = ['price' => round((float)$r['price'], 2),
                          'unit_cost' => round((float)$r['unit_cost'], 2),
                          'changed_at' => $r['changed_at'],
                          'changed_by' => $r['changed_by'],
                          'note' => $r['note']];
            }
            $s->close();
        } catch (mysqli_sql_exception $e) {
            // The table arrives with a migration; an older server just has
            // no history to show, which is not an error worth failing on.
        }

        api_ok(['item' => $item, 'history' => $out,
                'past_orders' => past_sales_count($conn, $item)]);
    },

    // ── POST reorder {ids:[...]} — drag-to-sort on the phone ───────
    'reorder_products' => function () use ($conn) {
        $ids = api_in('ids', []);
        if (!is_array($ids) || !$ids) throw new ApiInputError('Nothing to reorder.');

        $conn->begin_transaction();
        try {
            $s = $conn->prepare('UPDATE products SET sort_order = ? WHERE id = ?');
            foreach (array_values($ids) as $pos => $pid) {
                $order = $pos + 1;
                $pid   = (int)$pid;
                $s->bind_param('ii', $order, $pid);
                $s->execute();
            }
            $s->close();
            $conn->commit();
        } catch (Exception $ex) { $conn->rollback(); throw $ex; }

        api_ok(['products' => catalog_products($conn, true), 'message' => 'Order saved.']);
    },

    'events' => function () use ($conn) {
        api_ok(['events' => catalog_events($conn, api_bool('active_only'))]);
    },

    // ── POST add_event ─────────────────────────────────────────────
    'add_event' => function () use ($conn) {
        $name = api_str('name');
        if ($name === '') throw new ApiInputError('Give the event a name.');

        $isPaid    = api_bool('is_paid') ? 1 : 0;
        $entryCost = api_float('entry_cost', 0);
        if ($entryCost < 0) throw new ApiInputError('Entry cost cannot be negative.');
        if (!$isPaid) $entryCost = 0.0;

        $start = api_date('start_date') ?: null;
        $end   = api_date('end_date') ?: null;
        if ($start && $end && $end < $start) throw new ApiInputError('The end date is before the start date.');
        $notes = api_str('notes');
        $notes = $notes !== '' ? mb_substr($notes, 0, 255) : null;

        $s = $conn->prepare(
            'INSERT INTO events (name, is_paid, entry_cost, start_date, end_date, notes) VALUES (?,?,?,?,?,?)'
        );
        $s->bind_param('sidsss', $name, $isPaid, $entryCost, $start, $end, $notes);
        $s->execute();
        $newId = (int)$conn->insert_id;
        $s->close();

        api_ok(['events' => catalog_events($conn), 'event_id' => $newId, 'message' => $name . ' created.']);
    },

    // ── POST close_event {id, is_active} ───────────────────────────
    'set_event_active' => function () use ($conn) {
        $id     = api_int('id');
        $active = api_bool('is_active') ? 1 : 0;
        if ($id < 1) throw new ApiInputError('Invalid event.');

        $s = $conn->prepare('UPDATE events SET is_active = ? WHERE id = ?');
        $s->bind_param('ii', $active, $id);
        $s->execute();
        $s->close();

        api_ok(['events' => catalog_events($conn),
                'message' => $active ? 'Event reopened.' : 'Event closed.']);
    },

    // ── POST save_settings — bill header/footer, UPI id ────────────
    'save_settings' => function () use ($conn) {
        $saved = 0;
        foreach (array_keys(APP_SETTING_DEFAULTS) as $key) {
            $v = api_in($key, null);
            if ($v === null) continue;
            app_setting_put($conn, $key, mb_substr(trim((string)$v), 0, 500));
            $saved++;
        }
        if ($saved === 0) throw new ApiInputError('Nothing to save.');

        // app_settings() memoises per request; re-read for the response.
        $fresh = APP_SETTING_DEFAULTS;
        $res = $conn->query('SELECT skey, sval FROM app_settings');
        while ($res && ($r = $res->fetch_assoc())) $fresh[$r['skey']] = (string)$r['sval'];

        api_ok(['settings' => $fresh, 'message' => 'Saved.']);
    },
]);