-- ─────────────────────────────────────────────────────────────────
-- Which account the money landed in.
--
-- account_movements already recorded which PARTNER a credit belonged
-- to, and a free-text `source` saying things like "Meesho payout". What
-- was never recorded is the account it actually went into, so there was
-- no way to answer "how much is in the current account" without opening
-- the bank app.
--
-- An account may belong to a partner (a personal UPI that business money
-- sometimes lands in) or to nobody in particular (the shared current
-- account), hence the nullable partner_id.
--
-- That is the whole change. Orders are untouched, revenue is untouched,
-- and nothing else on the site behaves differently.
--
-- Additive and safe to run twice.
-- ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS accounts (
  id         INT(11) NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,
  kind       ENUM('bank','upi','cash','wallet','other') NOT NULL DEFAULT 'bank',
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

-- Left NULL on the movements already recorded. Those are real entries
-- whose account nobody wrote down at the time, and guessing one would
-- put a number on a screen that nobody measured. They show as "not
-- assigned" until someone says where they went.
ALTER TABLE account_movements
  ADD COLUMN IF NOT EXISTS account_id INT(11) DEFAULT NULL AFTER partner_id;

ALTER TABLE account_movements
  ADD KEY IF NOT EXISTS idx_mov_account (account_id);
