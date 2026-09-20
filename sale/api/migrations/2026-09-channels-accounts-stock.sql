-- ─────────────────────────────────────────────────────────────────
-- Where a sale came from, where the money landed, and what it used up.
--
-- Three separate ideas that the database had no room for:
--
--   1. CHANNEL. An order knew about `events` -- a stall, a weekend
--      exhibition -- and the screens already called that dimension
--      "All channels", but Amazon is not an event. It has no entry
--      cost and no end date, and a sale made over WhatsApp at a Diwali
--      stall is honestly both. So channel is its own column, free to
--      combine with event, and it carries its own price list the way
--      event_prices already does -- Amazon's price is not the counter
--      price.
--
--   2. ACCOUNT. account_movements recorded which PARTNER a credit
--      belonged to, and a free-text `source` that said things like
--      "Meesho payout". Which actual account the money landed in was
--      not recorded anywhere, so no balance could be worked out. An
--      account may belong to a partner (a personal UPI) or to nobody
--      in particular (the shared current account), hence the nullable
--      partner_id.
--
--   3. WHAT A SALE CONSUMED. purchases/stock_ledger/product_stock have
--      existed and gone unused: stock_lib.php says "and (later)
--      save.php", and later never came. save.php never consumed stock,
--      so nothing connected raw material bought to raw material used.
--      stock_draws is that missing link -- one row per FIFO batch a
--      sale drew from -- and it is what makes the purchase audit
--      answerable and an order edit reversible.
--
-- Additive and safe to run twice. Nothing existing is rewritten: see
-- the notes on channel_id and stock_done below, both of which are
-- deliberately left blank/0 on the rows that are already here.
-- ─────────────────────────────────────────────────────────────────

-- ── 1. Channels ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS channels (
  id            INT(11) NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60) NOT NULL,
  slug          VARCHAR(40) NOT NULL,
  -- Money now (a counter sale) or money later (a marketplace that pays
  -- out in a lump). This is what tells the Money screen that an Amazon
  -- payout is not new revenue but the settlement of orders already on
  -- the books.
  settles_later TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT(11) NOT NULL DEFAULT 0,
  notes         VARCHAR(255) DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A starting list, all editable on the Channels page. Slugs are the
-- stable key; names can be renamed freely.
INSERT IGNORE INTO channels (name, slug, settles_later, sort_order) VALUES
  ('Direct',    'direct',    0, 10),
  ('WhatsApp',  'whatsapp',  0, 20),
  ('Instagram', 'instagram', 0, 30),
  ('Website',   'website',   1, 40),
  ('Amazon',    'amazon',    1, 50),
  ('Meesho',    'meesho',    1, 60);

-- Per-channel selling price, mirroring event_prices. Absent row means
-- "use the catalogue price", so a channel only needs the items whose
-- price actually differs.
--
-- The collation is stated rather than inherited. event_prices is
-- utf8mb4_uca1400_ai_ci while products is utf8mb4_unicode_ci, and
-- joining those two by name fails outright on MariaDB 11.8 with
-- "Illegal mix of collations". This table joins products, so it must
-- match products.
CREATE TABLE IF NOT EXISTS channel_prices (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  channel_id INT(11) NOT NULL,
  item       VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_item (channel_id, item),
  KEY idx_cp_channel (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Left NULL on the orders already in the table. Guessing "Direct" for
-- 99 historical orders would put a number on a screen that nobody
-- measured; "not recorded" is the truth about them.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS channel_id INT(11) DEFAULT NULL AFTER event_id;

ALTER TABLE orders
  ADD KEY IF NOT EXISTS idx_orders_channel (channel_id);

-- ── 2. Accounts ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS accounts (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,
  kind       ENUM('bank','upi','cash','wallet','other') NOT NULL DEFAULT 'bank',
  -- NULL means the business holds it rather than one partner. A
  -- partner's own UPI is still an account money can land in, and the
  -- settlement maths needs to tell the two apart.
  partner_id INT(10) UNSIGNED DEFAULT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  notes      VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_accounts_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One obvious account so the movement form is never an empty dropdown.
-- Real bank and UPI accounts are yours to add; this invents nothing.
INSERT INTO accounts (name, kind, partner_id)
  SELECT 'Cash', 'cash', NULL
   WHERE NOT EXISTS (SELECT 1 FROM accounts);

ALTER TABLE account_movements
  ADD COLUMN IF NOT EXISTS account_id INT(11) DEFAULT NULL AFTER partner_id;

ALTER TABLE account_movements
  ADD COLUMN IF NOT EXISTS channel_id INT(11) DEFAULT NULL AFTER account_id;

ALTER TABLE account_movements
  ADD KEY IF NOT EXISTS idx_mov_account (account_id);

ALTER TABLE account_movements
  ADD KEY IF NOT EXISTS idx_mov_channel (channel_id);

-- 'payout' joins the kinds: a marketplace settling up for orders that
-- are already on the books. It is money ARRIVING, so it moves an
-- account balance, but it is NOT new revenue -- the orders it pays for
-- were counted when they were written. revenue_sources() excludes it
-- for exactly that reason.
--
-- Existing rows keep whatever kind they have, so the 18,455 of credits
-- already recorded as 'normal' stay revenue and no past total moves.
-- MODIFY is naturally repeatable.
ALTER TABLE account_movements
  MODIFY COLUMN kind
    ENUM('normal','personal','transfer','profit','invest','payout')
    NOT NULL DEFAULT 'normal';

-- ── 3. What a sale consumed ──────────────────────────────────────

-- One row per FIFO batch a sale drew from. stock_consume() already
-- worked out this split to price the sale; it just threw it away,
-- keeping only the total. Keeping it buys two things:
--
--   the audit -- which purchase, from which dealer, at what price,
--   ended up in which order;
--
--   reversibility -- editing an order can put the exact units back in
--   the exact batches. Without it, an edit could only guess, and a
--   guess in a FIFO ledger compounds.
--
-- purchase_id is NULL for units sold with no open batch to draw from.
-- Those are costed at the last known price and stock goes negative,
-- which is stock_lib's documented behaviour: a sale is never blocked
-- because the paperwork is behind.
CREATE TABLE IF NOT EXISTS stock_draws (
  id          INT(11) NOT NULL AUTO_INCREMENT,
  ledger_id   INT(11) DEFAULT NULL,
  purchase_id INT(11) DEFAULT NULL,
  item        VARCHAR(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  qty         INT(11) NOT NULL,
  unit_cost   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ref_type    VARCHAR(20) DEFAULT NULL,
  ref_id      INT(11) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_draws_ref (ref_type, ref_id),
  KEY idx_draws_purchase (purchase_id),
  KEY idx_draws_item (item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marks the orders whose stock has actually been drawn down. The 99
-- orders already in the table were written before save.php touched
-- stock, so their raw material was never taken out -- and the stock
-- replay tool, if it is ever run, accounts for them separately.
--
-- Editing one of those old orders must therefore NOT consume stock:
-- there is nothing recorded to give back first, so a release-then-
-- reconsume would take the material twice. save.php checks this flag
-- and leaves pre-existing orders alone.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS stock_done TINYINT(1) NOT NULL DEFAULT 0;
