-- ─────────────────────────────────────────────────────────────────
-- Wholesale customers — a notebook, not an accounting system.
--
-- A wholesale buyer comes back again and again and takes a few things
-- each time, at prices that are not the counter prices. The question
-- this answers is "what has this person bought from me, and at what" —
-- nothing more.
--
-- These three tables are an island ON PURPOSE. Nothing else in the
-- database reads them:
--
--   they are NOT orders, so they never reach the order book, the
--   dispatch list or a bill;
--
--   they are NOT counted in revenue, profit, the Money page, Stats or
--   the investment summary — revenue_sources() does not know they
--   exist, and nothing here changes that;
--
--   they do NOT draw stock, so the Stock page and the material audit
--   are unaffected.
--
-- Written down here, because this is the kind of thing that gets
-- "helpfully" wired into a total six months later and quietly moves
-- every number on the Money screen. If it should ever count towards
-- revenue, that has to be a decision somebody makes on purpose.
--
-- Additive and safe to run twice.
-- ─────────────────────────────────────────────────────────────────

-- Who buys wholesale. Separate from `orders.name`/`phone`, which is a
-- walk-in customer typed fresh each sale; this is somebody with a
-- history worth keeping.
CREATE TABLE IF NOT EXISTS wholesale_customers (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(20) DEFAULT NULL,
  shop       VARCHAR(160) DEFAULT NULL,
  place      VARCHAR(160) DEFAULT NULL,
  notes      VARCHAR(500) DEFAULT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ws_cust_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One visit: the day they came and took things.
CREATE TABLE IF NOT EXISTS wholesale_visits (
  id          INT(11) NOT NULL AUTO_INCREMENT,
  customer_id INT(11) NOT NULL,
  visit_date  DATE NOT NULL,
  note        VARCHAR(500) DEFAULT NULL,
  created_by  INT(11) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ws_visit_cust (customer_id, visit_date),
  CONSTRAINT fk_ws_visit_customer FOREIGN KEY (customer_id)
    REFERENCES wholesale_customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What they took that day.
--
-- `item` is free text rather than a foreign key to products: a wholesale
-- buyer may take raw material or something not in the retail catalogue
-- at all, and refusing to write that down would defeat the point of a
-- notebook. The page offers the catalogue as suggestions and accepts
-- anything.
--
-- `unit_price` is typed in every time, never looked up. A wholesale
-- price is negotiated, is not the counter price, and must not move when
-- the catalogue moves — which is exactly the bug that hit the order
-- book earlier, so it is designed out here from the start.
CREATE TABLE IF NOT EXISTS wholesale_items (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  visit_id   INT(11) NOT NULL,
  item       VARCHAR(120) NOT NULL,
  quantity   INT(11) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_ws_item_visit (visit_id),
  KEY idx_ws_item_name (item),
  CONSTRAINT fk_ws_item_visit FOREIGN KEY (visit_id)
    REFERENCES wholesale_visits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
