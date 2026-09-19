# Stall Order Manager — complete file set

This is the full, current system. Everything below is built and verified.
One feature (per-event pricing) is intentionally NOT here — see the end.

## Upload order

1. **Run `schema.sql`** inside your database (`u291217659_sale`).
   - Fresh install: run the whole file.
   - Existing data: run only the `ALTER TABLE` / new-table blocks at the
     bottom (events, product_costs, order columns). Do not re-create tables
     you already have.
2. **Set your DB password** in `config.php` (`DB_PASS`).
3. **Upload every .php file.**
4. First run only: open `setup.php`, create your admin, then **delete
   `setup.php`** from the server.

## The files

| File | What it is |
|---|---|
| `schema.sql` | All tables. Run once. |
| `config.php` | DB connection, catalog, all shared helpers. **Everything depends on this — always upload it when it changes.** |
| `setup.php` | First-admin creation. Delete after use. |
| `login.php` / `logout.php` | Auth. |
| `admins.php` | Manage admins. |
| `index.php` | Dashboard: stats, new order, search, event filter. |
| `save.php` | All order writes. |
| `edit.php` | Edit an order, payments, WhatsApp. |
| `events.php` | Create and manage events. |
| `products.php` | **Add/edit/hide products and prices from the panel.** |
| `costs.php` | Product costs and profit/margin. |
| `export.php` | CSV export. |
| `diagnose.php` | Troubleshooting only. Delete after use. |

## Product prices from the panel

Prices used to be hardcoded in `config.php`. They now live in a `products`
table and are edited from **Products** in the nav. Key points:

- `$ITEMS` in config now loads from the DB, falling back to the old hardcoded
  list if the table does not exist yet — so nothing breaks between uploading
  config and running the migration.
- Editing a price affects **new orders only**. Past orders keep the price
  they were sold at (stored per-line in `order_items`).
- Hiding a product removes it from the order form but keeps its history; the
  costs page still counts its past sales.
- Prices are still read server-side in `save.php`, never from the form — a
  customer cannot be overcharged by editing the page.

## What works

- Multi-item orders, discounts, part-payment ledger with history
- Ready / delivered with the one-way coupling (delivered implies ready)
- Admin login: phone + password, bcrypt, throttled, CSRF on every form
- WhatsApp buttons with prefilled order/payment/pickup messages
- Phone normalisation on entry AND search (+91, spaces, leading 0 all handled)
- Events: create free/paid, dates, filter orders by event, per-event revenue
- **Stats respect the active filter** — an event page shows that event's
  totals, not the global ones
- Costs page: editable costs, profit and margin from real sales
- CSV export including event and discount columns

## What is NOT here (next session)

Per-event **pricing** was deliberately not built. Today every order uses the
one price list in `config.php` ($ITEMS). Per-event prices mean a new
`event_prices` table and rewriting the price lookup in `save.php` — the code
path that decides what a customer is charged. That is not something to bolt
on at the end of a long session; a subtle error there charges wrong amounts
during a live event.

Scope waiting for a fresh start:
- `event_prices` table + a pricing page per event
- order form loads the right prices when an event is picked
- `save.php` resolves price per-event, falling back to the global catalog
- per-event cost/margin view
- surface the per-event `extra_cost` field (column already exists)
- edit.php event selector (change an existing order's event)

Point a new chat at this list and it can start on the schema.
