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
        }

        // Ran out of open batches but still owe units: cost them at the last
        // known price (or 0) and let stock go negative.
        if ($need > 0) $cost += $need * $lastUnit;

        stock_bump($conn, $item, -$qty);

        $l = $conn->prepare(
            'INSERT INTO stock_ledger (item, kind, qty, fifo_cost, ref_type, ref_id, note)
             VALUES (?, "sale", ?, ?, ?, ?, ?)'
        );
        $negQty = -$qty;
        $l->bind_param('sidsis', $item, $negQty, $cost, $refType, $refId, $note);
        $l->execute();
        $l->close();

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