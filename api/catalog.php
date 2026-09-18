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
    $sql = 'SELECT p.id, p.name, p.price, p.is_active, p.sort_order,
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
                      'sort_order' => 0, 'qty_on_hand' => 0.0];
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
    'update_product' => function () use ($conn) {
        $id = api_int('id');
        if ($id < 1) throw new ApiInputError('Invalid product.');

        $s = $conn->prepare('SELECT name FROM products WHERE id = ?');
        $s->bind_param('i', $id);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) api_fail(404, 'not_found', 'That product no longer exists.');
        $name = $row['name'];

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

        // Existing orders keep the price they were billed at: order_items
        // stores unit_price per line, so repricing never rewrites history.
        api_ok(['products' => catalog_products($conn, true), 'message' => $name . ' updated.']);
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
