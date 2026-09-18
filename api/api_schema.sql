-- ═══════════════════════════════════════════════════════════════════
-- MadeForU Android API — schema additions
-- Run once inside u291217659_sale (phpMyAdmin → SQL tab).
-- Every statement is idempotent, so re-running it is safe.
-- Nothing here changes an existing table's meaning; the app reads the
-- same orders/payments/partners tables the website already writes.
-- ═══════════════════════════════════════════════════════════════════

-- ── 1. API sessions ────────────────────────────────────────────────
-- One row per signed-in phone. The raw token never touches the database:
-- only its SHA-256 is stored, so a database dump cannot be replayed as a
-- login, the same reason password_hash() is used for passwords.
CREATE TABLE IF NOT EXISTS api_tokens (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  admin_id      INT NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  device        VARCHAR(120) DEFAULT NULL,
  last_used_at  DATETIME DEFAULT NULL,
  expires_at    DATETIME NOT NULL,
  revoked       TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  INDEX idx_admin (admin_id),
  CONSTRAINT fk_token_admin FOREIGN KEY (admin_id)
    REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. Business identity printed on bills ──────────────────────────
-- Key/value so a new bill field never needs another migration.
CREATE TABLE IF NOT EXISTS app_settings (
  skey       VARCHAR(60) NOT NULL PRIMARY KEY,
  sval       TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings (skey, sval) VALUES
  ('business_name',  'MadeForU'),
  ('business_tag',   'Handmade personalised gifts'),
  ('business_addr',  'Hyderabad, Telangana'),
  ('business_phone', ''),
  ('business_email', ''),
  ('business_site',  'madeforu.co.in'),
  ('gstin',          ''),
  ('upi_id',         ''),
  ('upi_name',       'MadeForU'),
  ('bill_prefix',    'MFU'),
  ('bill_footer',    'Thank you for shopping with MadeForU!'),
  ('bill_terms',     'Custom-made items are not returnable. Damage on arrival must be reported within 48 hours with photos.');

-- ── 3. Bills ───────────────────────────────────────────────────────
-- A bill is issued once per order and then frozen: `snapshot` keeps the
-- JSON of the order exactly as billed. Editing the order afterwards does
-- not silently rewrite a bill the customer already has — the app shows a
-- "re-issue" action instead, which writes a new snapshot and bumps
-- revision. public_token lets a customer open their own bill without a
-- login, the way track.php already works.
CREATE TABLE IF NOT EXISTS bills (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  order_id     INT NOT NULL,
  bill_no      VARCHAR(40) NOT NULL,
  public_token CHAR(32) NOT NULL,
  revision     INT NOT NULL DEFAULT 1,
  snapshot     MEDIUMTEXT NOT NULL,
  issued_by    INT DEFAULT NULL,
  issued_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bill_no (bill_no),
  UNIQUE KEY uq_order   (order_id),
  UNIQUE KEY uq_token   (public_token),
  CONSTRAINT fk_bill_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_bill_admin FOREIGN KEY (issued_by)
    REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. Walk-in orders (no customer details) ────────────────────────
-- `phone` stays NOT NULL; a walk-in simply stores ''. That keeps every
-- existing query, index and foreign key valid — no backfill, no NULL
-- handling to add across 30 pages. Only the DEFAULT changes, so an
-- INSERT that omits the column no longer fails under strict mode.
ALTER TABLE orders MODIFY COLUMN phone VARCHAR(15) NOT NULL DEFAULT '';
ALTER TABLE orders MODIFY COLUMN name  VARCHAR(120) NOT NULL DEFAULT 'Walk-in';

-- Speeds up the app's default "recent orders" screen and the date-range
-- statistics, which both sort on created_at.
CREATE INDEX idx_orders_created_event ON orders (created_at, event_id);
-- ^ If this errors with "Duplicate key name", the index already exists.
--   That is fine: MySQL has no CREATE INDEX IF NOT EXISTS. Ignore and
--   carry on; nothing else in this file depends on it.
