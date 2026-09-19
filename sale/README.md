# Stall Order Manager

PHP + MySQLi. Password-protected, multi-item orders, part payments.

## Setup

**1. Drop the old tables.** The schema changed. In phpMyAdmin, select your
database (e.g. `u291217659_sale`) and run:

```sql
DROP TABLE IF EXISTS payments, order_items, orders;
```

**2. Run `schema.sql`** inside that same database. It has no `CREATE DATABASE`,
which shared hosting forbids.

**3. Edit `config.php`:**

```php
define('DB_HOST', 'localhost');   // try '127.0.0.1' if this fails
define('DB_USER', 'u291217659_sale');
define('DB_PASS', 'your_password');
define('DB_NAME', 'u291217659_sale');
```

**4. Upload, then open `setup.php`** to create the first admin.

**5. Delete `setup.php` from the server.** It locks itself once an admin
exists, but there is no reason to leave it there.

Add further admins from the Admins page.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | admins, login_attempts, orders, order_items, payments |
| `config.php` | DB, catalog, auth helpers, CSRF, status rules |
| `setup.php` | First admin only. Delete after use. |
| `login.php` | Phone + password sign-in |
| `logout.php` | Destroys the session |
| `admins.php` | Add, disable, delete admins; change passwords |
| `index.php` | Dashboard, new order, search |
| `save.php` | All writes |
| `edit.php` | Payments, history, edit products |
| `export.php` | CSV export |

## Ready and delivered

You cannot hand over something that was never made. So the two flags are
coupled, one way:

- Ticking **handed over** sets **ready** automatically.
- Unticking **ready** clears **handed over**.
- Both can be set when the order is created — for a walk-up customer who
  pays and leaves with the item, tick both.

Enforced in three places, because each alone is insufficient:

1. **JavaScript** (`syncStatus`) — immediate feedback in the browser.
2. **PHP** (`normalise_status`) — the guarantee. Anyone can disable JS.
3. **MySQL** `CHECK (is_delivered = 0 OR is_ready = 1)` — a backstop.
   Note this is enforced only on MySQL 8.0.16+ and MariaDB 10.2+.
   Older versions parse it and silently ignore it, which is exactly why
   the PHP layer exists and is not merely defensive duplication.

The dashboard shows **Awaiting pickup** — ready but not yet handed over.
That is the queue to work through.

## Admin accounts

Sign in with a **10-digit phone number and password**.

- Passwords are stored as bcrypt hashes via `password_hash()`. Nobody,
  including you, can read them back out of the database.
- **5 failed attempts** per phone+IP triggers a **15-minute lockout**.
  Adjust `MAX_ATTEMPTS` and `LOCKOUT_MINS` in `config.php`.
- Changing your own password requires your current one.
- You cannot disable or delete your own account, and the last active
  admin cannot be removed. Either would lock everyone out.
- Deleting an admin keeps their orders. `created_by` becomes NULL.

Orders and payments record who created them, shown in the table and the CSV.

## Two security details worth knowing

**Login timing.** When the phone number does not exist, the code still runs
`password_verify()` against a dummy hash before failing. Returning early
would make a missing account measurably faster to reject than a wrong
password — and that timing difference is enough to enumerate which phone
numbers are registered admins. Both failure modes also return the identical
message for the same reason.

**CSRF.** Every form carries a token checked with `hash_equals()`, which is
timing-safe. `==` is not. Without this, a page on another site could make
your browser submit a delete request to this app using your live session.

## Discounts

A flat rupee amount off the order. Enter it on the new-order form or the
edit page. The 10% / 20% buttons are a shortcut that fills in the amount —
what gets stored is always a flat figure, never a percentage.

Three quantities now, not two:

```
subtotal   sum of the line items
discount   flat amount off
total      subtotal - discount   <- what is owed
```

`total` keeps its old meaning, so every payment comparison stayed correct.
Payments settle against `total`, never `subtotal`.

An optional reason ("bulk order", "friend") appears in the orders table,
the CSV, and the customer's WhatsApp message.

### Discounting after a payment

An order at ₹1,100 where ₹900 has been collected can take at most a ₹200
discount. Beyond that the total falls below what was paid, the balance goes
negative, and the customer is owed a refund the app cannot express.

The edit page shows the cap and warns live as you type. The server rejects
it independently, because the browser is not to be trusted:

```
New total ₹899.00 is less than the ₹900.00 already collected.
Reduce the discount, or delete a payment first.
```

### The 100% discount

A free order has `total = 0`. This broke the original status logic:

```php
if ($paid <= 0.001) return 'unpaid';   // 0 <= 0  -> "unpaid" forever
```

Nothing was owed, yet the order sat in the unpaid list and the edit page
offered to collect ₹0. The zero case is now checked first:

```php
if ($total <= 0.001) return 'paid';
```

The SQL filters mirror it. `paid` became `(total <= 0 OR paid_amount >= total)`
and `unpaid` became `(total > 0 AND paid_amount <= 0)`. Without the second
change a free order would have matched *both* buckets. Every order now falls
into exactly one.

### Shrinking a discounted order

Remove products from an order that already carries a discount and the
subtotal can drop below it. `recalc_total()` clamps before subtracting:

```sql
UPDATE orders SET discount = LEAST(discount, subtotal) WHERE id = ?;
UPDATE orders SET total = GREATEST(subtotal - discount, 0) WHERE id = ?;
```

Two statements, because MySQL cannot read a column it is writing in the
same one.

## WhatsApp

Every order row has a WhatsApp icon beside the phone number and a green
**WhatsApp** button next to Edit. The edit page has a Contact card with up
to three prefilled messages:

- **Send order summary** — items, total, paid, balance, current status.
- **"Ready for pickup"** — appears only when the order is ready but not
  yet handed over.
- **Payment reminder** — appears only when a balance is outstanding.

The link opens WhatsApp with the message typed out. **Nothing sends until
you press send.** You can edit the text first.

### The country code

`wa.me/9876543210` does not resolve. WhatsApp needs the country code, so
`whatsapp_link()` prefixes it:

```php
define('COUNTRY_CODE', '91');   // India
```

Change that one line if you sell elsewhere. The helper normalises what it
is given — it strips spaces, dashes and a leading zero, and leaves numbers
that already carry a country code alone:

| Stored | Link |
|---|---|
| `9876543210` | `wa.me/919876543210` |
| `09876543210` | `wa.me/919876543210` |
| `+91 98765 43210` | `wa.me/919876543210` |
| `919876543210` | `wa.me/919876543210` |
| `12345` | *(button hidden)* |

A number it cannot make sense of returns `null` and the button simply does
not render, rather than producing a link that fails when clicked.

### One encoding detail

The message uses `rawurlencode()`, not `urlencode()`. The latter encodes a
space as `+`, and WhatsApp shows that literally — every space in the message
would appear as a plus sign.

## Part payments

A ₹100 product, customer pays ₹40. Create the order with `40`. It shows
**Part paid**, balance ₹60. Open Edit:

```
Order total     ₹100.00
Paid so far      ₹40.00
Remaining        ₹60.00
[████░░░░░░] 40% collected
```

Each further payment is a new row in the history with its own date, mode
and the admin who took it. Nothing is overwritten. At ₹100 the status
becomes **Fully paid** by itself — there is no checkbox.

Payment status is never stored. It is computed:

```php
if ($paid <= 0.001)          return 'unpaid';
if ($paid >= $total - 0.001) return 'paid';
return 'partial';
```

The `0.001` tolerance is load-bearing. `DECIMAL` returns a PHP float, and a
strict `$paid >= $total` can report a fully-settled order as still partial
on certain amounts. That bug would only appear in production, on some
orders, and look inexplicable.

## Other guarantees

- Prices come from `$ITEMS` in `config.php`, never the form. Editing the
  page HTML cannot change what a customer is charged.
- Creating and updating orders run in a transaction, so a mid-way failure
  rolls back rather than leaving an order holding half its products.
- An order cannot be overpaid.
- An order's total cannot drop below what has already been collected. It
  tells you to remove a payment first.
- Duplicate products merge server-side: two "Cup ×2" lines become "Cup ×4".
- Deleting an order cascades to its items and payments.
- All output escaped with `e()`.

## Still missing

There is no HTTPS enforcement here. On a public server, passwords cross the
wire in plaintext without it. Hostinger provides free SSL — turn it on, and
the session cookie will set its `secure` flag automatically.
