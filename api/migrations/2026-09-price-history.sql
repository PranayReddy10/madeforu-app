-- ─────────────────────────────────────────────────────────────────
-- A price change must never reach a sale that already happened.
--
-- order_items.unit_price already froze the selling price at the moment
-- of sale. Two things were still read live at report time and could
-- rewrite history underneath a completed order:
--
--   1. editing an order re-priced every line from today's catalogue
--      (fixed in orders.php / save.php, not here);
--   2. product_costs.unit_cost is joined by name when Stats works out
--      profit, so changing what an item costs us silently changed the
--      profit on every sale ever made.
--
-- This migration is additive and safe to run twice.
-- ─────────────────────────────────────────────────────────────────

-- 1. Freeze the cost side too, the same way the price side already is.
ALTER TABLE order_items
  ADD COLUMN IF NOT EXISTS unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

-- Backfill with EXACTLY the expression Stats used before this column
-- existed: the per-event cost first, then the global one. Taking the
-- global cost alone would look harmless and silently restate the profit
-- on every stall sale that had its own costs -- on this database, 13
-- lines whose real cost was 880 would have been recorded as 484. The
-- point of the column is that history stops moving, so migration day
-- must not move it either.
UPDATE order_items oi
  JOIN orders o ON o.id = oi.order_id
  LEFT JOIN event_item_costs eic
    ON eic.event_id = o.event_id
   AND eic.item = oi.item COLLATE utf8mb4_unicode_ci
  LEFT JOIN product_costs pc
    ON pc.item = oi.item COLLATE utf8mb4_unicode_ci
   SET oi.unit_cost = COALESCE(eic.unit_cost, pc.unit_cost, 0)
 WHERE oi.unit_cost = 0;

-- 2. What a product used to cost and sell for, and when it changed.
CREATE TABLE IF NOT EXISTS product_price_history (
  id          INT(11) NOT NULL AUTO_INCREMENT,
  product_id  INT(11) DEFAULT NULL,
  item        VARCHAR(60) NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  unit_cost   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  changed_by  INT(11) DEFAULT NULL,
  changed_at  DATETIME NOT NULL DEFAULT current_timestamp(),
  note        VARCHAR(160) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_pph_item (item, changed_at),
  KEY idx_pph_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed one row per product at today's figures, so a history that starts
-- now still has a floor to show rather than an empty screen.
INSERT INTO product_price_history (product_id, item, price, unit_cost, note)
SELECT p.id, p.name, p.price, COALESCE(pc.unit_cost, 0), 'Starting price when history began'
  FROM products p
  LEFT JOIN product_costs pc ON pc.item = p.name COLLATE utf8mb4_unicode_ci
 WHERE NOT EXISTS (
         SELECT 1 FROM product_price_history h
          WHERE h.item = p.name COLLATE utf8mb4_unicode_ci
       );
