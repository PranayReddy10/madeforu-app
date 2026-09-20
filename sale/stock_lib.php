<?php
/**
 * stock_lib.php — FIFO stock helpers, shared by the purchasing module and
 * (later) save.php / the Meesho import.
 *
 * Function names are deliberately prefixed `stock_` so they never collide
 * with config.php globals (the pay_status() lesson: a duplicate name is a
 * fatal 500). Include this AFTER config.php.
 *
 * FIFO model:
 *   - A purchase inserts a `purchases` row with qty_remaining = qty_bought
 *     and adds qty to product_stock.
 *   - A sale subtracts from product_stock, then consumes the oldest open
 *     `purchases` rows (order by purchase_date, id) reducing qty_remaining
 *     until the sold qty is covered. The money value of those consumed units
 *     is the FIFO cost of that sale.
 *   - Negative stock is allowed. If open batches run dry mid-sale, remaining
 *     units are costed at the last known unit_cost (or 0) and stock simply
 *     goes negative — nothing is blocked.
 */

if (!function_exists('stock_bump')) {
    /** Add `delta` (can be negative) to a product's stored on-hand count. */
    function stock_bump(mysqli $conn, string $item, int $delta): void {
        $s = $conn->prepare(
            'INSERT INTO product_stock (item, qty_on_hand) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE qty_on_hand = product_stock.qty_on_hand + VALUES(qty_on_hand)'
        );
        $s->bind_param('si', $item, $delta);
        $s->execute();
        $s->close();
    }
}

if (!function_exists('stock_on_hand')) {
    /** Current stored on-hand for one product (0 if no row yet). */
    function stock_on_hand(mysqli $conn, string $item): int {
        $s = $conn->prepare('SELECT qty_on_hand FROM product_stock WHERE item = ?');
        $s->bind_param('s', $item);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        return $row ? (int)$row['qty_on_hand'] : 0;
    }
}

if (!function_exists('stock_add_purchase')) {
    /**
     * Record a purchase batch and increase stock. Returns the new purchase id.
     * Writes a stock_ledger 'purchase' row. Wrap the caller in a transaction.
     */
    function stock_add_purchase(
        mysqli $conn, ?int $dealerId, string $item, float $unitCost,
        int $qty, string $purchaseDate, ?string $notes = null,
        bool $isOpening = false
    ): int {
        $s = $conn->prepare(
            'INSERT INTO purchases
               (dealer_id, item, unit_cost, qty_bought, qty_remaining,
                purchase_date, is_opening, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $open = $isOpening ? 1 : 0;
        $s->bind_param('isdiisis',
            $dealerId, $item, $unitCost, $qty, $qty, $purchaseDate, $open, $notes);
        $s->execute();
        $pid = $s->insert_id;
        $s->close();

        stock_bump($conn, $item, $qty);

        $l = $conn->prepare(
            'INSERT INTO stock_ledger (item, kind, qty, ref_type, ref_id, note)
             VALUES (?, "purchase", ?, "purchase", ?, ?)'
        );
        $l->bind_param('siis', $item, $qty, $pid, $notes);
        $l->execute();
        $l->close();

        return $pid;
    }
}

if (!function_exists('stock_consume')) {
    /**
     * Consume `qty` of `item` FIFO. Draws down the oldest open purchase
     * batches, reduces product_stock, writes a 'sale' ledger row carrying
     * the FIFO cost. Returns that FIFO cost. Negative stock allowed.
     *
     * $refType/$refId ties the movement to its cause (e.g. 'order', 123) so
     * it can be located and reversed. Wrap the caller in a transaction.
     */
    function stock_consume(
        mysqli $conn, string $item, int $qty,
        ?string $refType = null, ?int $refId = null, ?string $note = null
    ): float {
        if ($qty <= 0) return 0.0;

        $need = $qty;
        $cost = 0.0;
        $lastUnit = 0.0;

        // Oldest open batches first.
        $sel = $conn->prepare(
            'SELECT id, unit_cost, qty_remaining
               FROM purchases
              WHERE item = ? AND qty_remaining > 0
              ORDER BY purchase_date ASC, id ASC'
        );
        $sel->bind_param('s', $item);
        $sel->execute();
        $batches = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $sel->close();

        // Which batch gave up which units. This split was always worked
        // out here and then thrown away, only the total surviving. It is
        // kept now because it answers the audit question -- which
        // purchase, at what price, ended up in which order -- and because
        // an order edit can only give stock back exactly if it knows
        // exactly what was taken.
        $draws = [];

        foreach ($batches as $b) {
            if ($need <= 0) break;
            $take     = min($need, (int)$b['qty_remaining']);
            $lastUnit = (float)$b['unit_cost'];
            $cost    += $take * $lastUnit;
            $need    -= $take;

            $u = $conn->prepare('UPDATE purchases SET qty_remaining = qty_remaining - ? WHERE id = ?');
            $bid = (int)$b['id'];
            $u->bind_param('ii', $take, $bid);
            $u->execute();
            $u->close();

            $draws[] = ['purchase_id' => $bid, 'qty' => $take, 'unit_cost' => $lastUnit];
        }

        // Ran out of open batches but still owe units: cost them at the last
        // known price (or 0) and let stock go negative.
        if ($need > 0) {
            $cost += $need * $lastUnit;
            // No batch to point at. Recorded all the same, so that giving
            // the units back restores the same negative position rather
            // than inventing stock that was never bought.
            $draws[] = ['purchase_id' => null, 'qty' => $need, 'unit_cost' => $lastUnit];
        }

        stock_bump($conn, $item, -$qty);

        $l = $conn->prepare(
            'INSERT INTO stock_ledger (item, kind, qty, fifo_cost, ref_type, ref_id, note)
             VALUES (?, "sale", ?, ?, ?, ?, ?)'
        );
        $negQty = -$qty;
        $l->bind_param('sidsis', $item, $negQty, $cost, $refType, $refId, $note);
        $l->execute();
        $ledgerId = $l->insert_id;
        $l->close();

        stock_record_draws($conn, $ledgerId, $item, $refType, $refId, $draws);

        return $cost;
    }
}

if (!function_exists('stock_open_batches')) {
    /** Open FIFO batches for a product, oldest first, with dealer names. */
    function stock_open_batches(mysqli $conn, string $item): array {
        $s = $conn->prepare(
            'SELECT p.id, p.unit_cost, p.qty_remaining, p.purchase_date,
                    p.is_opening, d.name AS dealer
               FROM purchases p
               LEFT JOIN dealers d ON d.id = p.dealer_id
              WHERE p.item = ? AND p.qty_remaining > 0
              ORDER BY p.purchase_date ASC, p.id ASC'
        );
        $s->bind_param('s', $item);
        $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $rows;
    }
}
if (!function_exists('stock_draws_ready')) {
    /**
     * Has the stock_draws migration been applied?
     *
     * The site is uploaded file by file, so this file will land before
     * the SQL does. Everything below degrades quietly rather than taking
     * the sale page down with an undefined table in that window.
     */
    function stock_draws_ready(mysqli $conn): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $conn->query('SELECT 1 FROM stock_draws LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('stock_record_draws')) {
    /** Write the per-batch split of one consumption. */
    function stock_record_draws(
        mysqli $conn, ?int $ledgerId, string $item,
        ?string $refType, ?int $refId, array $draws
    ): void {
        if (!$draws || !stock_draws_ready($conn)) return;

        $s = $conn->prepare(
            'INSERT INTO stock_draws
               (ledger_id, purchase_id, item, qty, unit_cost, ref_type, ref_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($draws as $d) {
            $pid  = $d['purchase_id'];   // may be null
            $qty  = (int)$d['qty'];
            $cost = (float)$d['unit_cost'];
            if ($qty <= 0) continue;
            $s->bind_param('iisidsi', $ledgerId, $pid, $item, $qty, $cost, $refType, $refId);
            $s->execute();
        }
        $s->close();
    }
}

if (!function_exists('stock_release')) {
    /**
     * Give back everything one reference took, exactly.
     *
     * Each recorded draw goes home to the batch it came from, so a
     * re-consume afterwards sees the same FIFO position as before and
     * costs the sale the same way. Reversing by "add the quantity to the
     * oldest open batch" would look equivalent and is not: it moves units
     * between batches bought at different prices, and every later sale
     * is then costed from a ledger that no longer matches what was
     * actually bought.
     *
     * Returns the units returned per item. Safe to call for a reference
     * that drew nothing -- that is the normal case for the orders written
     * before save.php touched stock at all.
     *
     * Wrap the caller in a transaction.
     */
    function stock_release(mysqli $conn, string $refType, int $refId): array {
        if (!stock_draws_ready($conn)) return [];

        $s = $conn->prepare(
            'SELECT id, purchase_id, item, qty FROM stock_draws
              WHERE ref_type = ? AND ref_id = ?'
        );
        $s->bind_param('si', $refType, $refId);
        $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        if (!$rows) return [];

        $perItem = [];
        foreach ($rows as $r) {
            $qty = (int)$r['qty'];
            $perItem[$r['item']] = ($perItem[$r['item']] ?? 0) + $qty;

            if ($r['purchase_id'] !== null) {
                $u = $conn->prepare(
                    'UPDATE purchases SET qty_remaining = qty_remaining + ? WHERE id = ?'
                );
                $pid = (int)$r['purchase_id'];
                $u->bind_param('ii', $qty, $pid);
                $u->execute();
                $u->close();
            }
        }

        foreach ($perItem as $item => $qty) {
            stock_bump($conn, $item, $qty);
            $l = $conn->prepare(
                'INSERT INTO stock_ledger (item, kind, qty, ref_type, ref_id, note)
                 VALUES (?, "adjust", ?, ?, ?, ?)'
            );
            $note = 'Returned to stock when ' . $refType . ' ' . $refId . ' was edited';
            $l->bind_param('sisis', $item, $qty, $refType, $refId, $note);
            $l->execute();
            $l->close();
        }

        $d = $conn->prepare('DELETE FROM stock_draws WHERE ref_type = ? AND ref_id = ?');
        $d->bind_param('si', $refType, $refId);
        $d->execute();
        $d->close();

        return $perItem;
    }
}

if (!function_exists('stock_usage')) {
    /**
     * Bought against used, per raw material, in units and in money.
     *
     * `used` comes from the draws rather than from the orders, which is
     * the whole point: it is what physically left the shelf, priced at
     * what those particular units actually cost, not at whatever the
     * catalogue says a unit costs today.
     *
     * `unaccounted` is bought minus used minus on hand. It should be
     * zero. When it is not, something moved without being written down
     * -- breakage, a sample given away, a miscount -- and that number is
     * the size of it.
     */
    function stock_usage(mysqli $conn): array {
        $bought = [];
        $res = $conn->query(
            'SELECT item,
                    COALESCE(SUM(qty_bought),0) qty,
                    COALESCE(SUM(qty_bought * unit_cost),0) val
               FROM purchases GROUP BY item'
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $bought[$r['item']] = ['qty' => (int)$r['qty'], 'val' => (float)$r['val']];
        }

        $used = [];
        if (stock_draws_ready($conn)) {
            $res = $conn->query(
                'SELECT item,
                        COALESCE(SUM(qty),0) qty,
                        COALESCE(SUM(qty * unit_cost),0) val
                   FROM stock_draws GROUP BY item'
            );
            while ($res && ($r = $res->fetch_assoc())) {
                $used[$r['item']] = ['qty' => (int)$r['qty'], 'val' => (float)$r['val']];
            }
        }

        $onHand = [];
        $res = $conn->query('SELECT item, qty_on_hand FROM product_stock');
        while ($res && ($r = $res->fetch_assoc())) $onHand[$r['item']] = (int)$r['qty_on_hand'];

        $items = array_unique(array_merge(
            array_keys($bought), array_keys($used), array_keys($onHand)
        ));
        sort($items);

        $out = [];
        foreach ($items as $item) {
            $b = $bought[$item] ?? ['qty' => 0, 'val' => 0.0];
            $u = $used[$item]   ?? ['qty' => 0, 'val' => 0.0];
            $h = $onHand[$item] ?? 0;
            $out[] = [
                'item'          => $item,
                'bought_qty'    => $b['qty'],
                'bought_value'  => round($b['val'], 2),
                'used_qty'      => $u['qty'],
                'used_value'    => round($u['val'], 2),
                'on_hand'       => $h,
                'unaccounted'   => $b['qty'] - $u['qty'] - $h,
                'stock_value'   => round($h * ($b['qty'] > 0 ? $b['val'] / $b['qty'] : 0), 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('stock_draws_for')) {
    /**
     * The batches one order ate, named: which dealer, bought when, at
     * what price. This is the per-order half of the audit.
     */
    function stock_draws_for(mysqli $conn, string $refType, int $refId): array {
        if (!stock_draws_ready($conn)) return [];
        $s = $conn->prepare(
            'SELECT sd.item, sd.qty, sd.unit_cost, sd.purchase_id,
                    p.purchase_date, p.is_opening, d.name AS dealer
               FROM stock_draws sd
               LEFT JOIN purchases p ON p.id = sd.purchase_id
               LEFT JOIN dealers  d ON d.id = p.dealer_id
              WHERE sd.ref_type = ? AND sd.ref_id = ?
              ORDER BY sd.item, sd.id'
        );
        $s->bind_param('si', $refType, $refId);
        $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        return array_map(function (array $r): array {
            return [
                'item'          => $r['item'],
                'qty'           => (int)$r['qty'],
                'unit_cost'     => round((float)$r['unit_cost'], 2),
                'value'         => round((int)$r['qty'] * (float)$r['unit_cost'], 2),
                'purchase_id'   => $r['purchase_id'] === null ? null : (int)$r['purchase_id'],
                'purchase_date' => $r['purchase_date'],
                'dealer'        => $r['dealer'],
                'is_opening'    => (int)($r['is_opening'] ?? 0) === 1,
            ];
        }, $rows);
    }
}
