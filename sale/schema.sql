-- Run this INSIDE your existing database (u291217659_sale).
-- Shared hosting does not allow CREATE DATABASE.

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone (phone)
) ENGINE=InnoDB;

-- Throttles brute-force attempts per phone + IP.
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(15) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lookup (phone, ip, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,   -- sum of line items
  discount DECIMAL(10,2) NOT NULL DEFAULT 0,   -- flat amount off
  discount_reason VARCHAR(120) DEFAULT NULL,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,      -- subtotal - discount = payable
  paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_ready TINYINT(1) NOT NULL DEFAULT 0,
  is_delivered TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_phone (phone),
  INDEX idx_created (created_at),
  CONSTRAINT fk_order_admin FOREIGN KEY (created_by)
    REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Delivered implies ready. Enforced in PHP (normalise_status) on every write.
-- The CHECK below is a backstop, but only MySQL 8.0.16+ / MariaDB 10.2+
-- enforce it, and some older versions reject the syntax outright -- which
-- would stop the CREATE TABLE above from running at all. So it is separate
-- and optional. Run it if your server accepts it; ignore the error if not.
--
--   ALTER TABLE orders
--     ADD CONSTRAINT chk_ready CHECK (is_delivered = 0 OR is_ready = 1);

-- ── Upgrading an existing install ──────────────────────────────────
-- If you already have an orders table with data, run this instead of
-- dropping it. The old `total` column becomes `subtotal`.
--
--   ALTER TABLE orders
--     ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER phone,
--     ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER subtotal,
--     ADD COLUMN discount_reason VARCHAR(120) DEFAULT NULL AFTER discount;
--   UPDATE orders SET subtotal = total;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  item VARCHAR(60) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  INDEX idx_order (order_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  mode ENUM('cash','upi','card','other') NOT NULL DEFAULT 'cash',
  note VARCHAR(120) DEFAULT NULL,
  taken_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_order (order_id),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_admin FOREIGN KEY (taken_by)
    REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════
-- Events + product costs (added later)
-- ═══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  is_paid TINYINT(1) NOT NULL DEFAULT 0,      -- 0 = free entry, 1 = paid entry
  entry_cost DECIMAL(10,2) NOT NULL DEFAULT 0,-- cost to attend, if paid
  start_date DATE DEFAULT NULL,
  end_date DATE DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- Orders belong to an event. NULL = the default walk-up / offline channel,
-- so every existing order stays valid without a backfill.
ALTER TABLE orders
  ADD COLUMN event_id INT DEFAULT NULL AFTER phone,
  ADD COLUMN extra_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_reason,
  ADD CONSTRAINT fk_order_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE SET NULL;

-- One editable cost per catalogue item. Seeded with placeholders you change
-- from the cost page. Revenue - cost = profit.
CREATE TABLE IF NOT EXISTS product_costs (
  item VARCHAR(60) NOT NULL PRIMARY KEY,
  unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════════
-- Editable product catalogue (prices managed from the admin panel)
-- ═══════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed with the original catalogue. Runs once; existing rows are left alone.
INSERT IGNORE INTO products (name, price, sort_order) VALUES
  ('Square Magnet',  50, 1),
  ('Round Magnet',   50, 2),
  ('MDF Magnet',    100, 3),
  ('Acrylic Magnet',100, 4),
  ('Bottle',        350, 5),
  ('Cup',           250, 6),
  ('Key Chain',      50, 7);
