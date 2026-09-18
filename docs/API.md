# API reference

Base: `https://sale.madeforu.co.in/api/`

Every call is `<file>.php?action=<action>`. Reads are GET, writes are POST
with a JSON body. Authentication is `Authorization: Bearer <token>`, with
`?token=` accepted as a fallback for hosts that strip the header.

Every response is an envelope:

```jsonc
{ "ok": true,  "orders": [ … ] }
{ "ok": false, "error": { "code": "invalid_input", "message": "Add at least one product." } }
```

`message` is written for a partner to read on a phone screen; the app
shows it verbatim. `code` is the stable string to branch on.

| code | meaning |
|---|---|
| `no_token` / `bad_token` / `expired_token` | sign in again |
| `bad_credentials` | wrong phone or password |
| `locked_out` | 5 failures; wait 15 minutes |
| `invalid_input` | the request can be fixed and retried (422) |
| `not_found` | the row is gone (404) |
| `db_error` / `server_error` | nothing was saved (500) |

---

## auth.php

| action | method | body / query | returns |
|---|---|---|---|
| `ping` | GET | — | version, server time |
| `login` | POST | `phone`, `password`, `device` | `token`, `expires_at`, `admin` |
| `me` | GET | — | `admin`, `settings` |
| `logout` | POST | — | revokes this device's token |
| `logout_all` | POST | — | revokes every device |
| `change_password` | POST | `current_password`, `new_password` | revokes other devices |

Tokens last 90 days and are stored only as a SHA-256 hash.

## catalog.php

| action | method | notes |
|---|---|---|
| `bootstrap` | GET | products + events + partners + categories + modes + settings, in one call |
| `products` | GET | `include_hidden=true` for the full list |
| `add_product` | POST | `name`, `price`, `unit_cost` |
| `update_product` | POST | `id` plus any of `price`, `unit_cost`, `is_active`, `image_url`, `product_url` |
| `price_history` | GET | `item` — what it has sold for, newest first, plus `past_orders` |
| `reorder_products` | POST | `ids: [..]` in the new order |
| `events` | GET | `active_only=true` to filter |
| `add_event` | POST | `name`, `is_paid`, `entry_cost`, `start_date`, `end_date`, `notes` |
| `set_event_active` | POST | `id`, `is_active` |
| `save_settings` | POST | any of the `app_settings` keys |

### A price change never reaches a sale that already happened

`update_product` changes what the product sells for **from now on**. Every
order already taken keeps the price it was sold at, because
`order_items.unit_price` is written at the moment of sale and is never
recalculated from the catalogue — including when an old order is edited,
where only lines genuinely new to the order get today's price.

The response says so: `price_changed`, `was_price`, `now_price`,
`past_orders` (how many orders hold this product and are therefore
untouched), and a `message` written for a partner to read.

`order_items.unit_cost` does the same for the cost side, so raising what
an item costs us does not restate the profit on every sale ever made.
Both arrive with `api/migrations/2026-09-price-history.sql`; the API
checks whether the column exists and works either way.

`orders.php` `update` returns `repriced`: the lines still charged at what
they sold for while the catalogue has since moved. It is not an error —
it is the guarantee, said out loud.

Products carry `image_url` and `product_url` — the same two columns the
website's Products page writes, which feed the public `menu.php` catalogue
and `share.php`. The app shows the photo in the order picker and offers the
same WhatsApp share. Both are selected with `COALESCE`, so an install that
has not added those columns reports empty strings rather than failing.

**Renaming and deleting products is not exposed.** The database joins these
items by name across seven tables, so a rename must propagate through all
of them in one transaction — that lives on the website's products page.

## orders.php

| action | method | notes |
|---|---|---|
| `list` | GET | `q`, `pay`, `status`, `event`, `from`, `to`, `limit`, `offset` |
| `get` | GET | `id` — items, payments, WhatsApp texts, bill flag |
| `create` | POST | see below |
| `update` | POST | `id` plus the same fields |
| `add_payment` | POST | `id`, `amount`, `payment_mode`, `note` |
| `delete_payment` | POST | `id`, `payment_id` |
| `toggle` | POST | `id`, `field` (`is_ready` \| `is_delivered`) |
| `dispatch` | POST | `id`, `awb`, `dispatch_date` |
| `delete` | POST | `id` |

`list` filters: `pay` ∈ all/paid/partial/unpaid, `status` ∈
all/pending/ready/delivered/dispatched, `event` ∈ all/offline/`<id>`.
`summary` in the reply covers the **whole filtered set**, not the page.

### create

```jsonc
{
  "name": "",                      // optional — empty makes it a walk-in
  "phone": "",                     // optional — if given, must be 10 digits
  "notes": "Name on the mug: Asha",
  "event_id": 3,                   // omit or null for a direct sale
  "items": [ { "item": "MDF Magnet", "quantity": 4 } ],
  "extra_charge": 40, "extra_charge_reason": "Gift wrap",
  "discount": 50,     "discount_reason": "Stall offer",
  "paid_amount": 300, "payment_mode": "cash",
  "is_ready": false,  "is_delivered": false
}
```

Rules the server enforces, all shared with `save.php`:

- **Prices come from the catalogue**, never from the request.
- **Duplicate lines merge** — `Cup ×2` twice becomes `Cup ×4`.
- **extra_charge is added before the discount**, so a discount can cancel a
  delivery fee.
- An order **cannot be overpaid**, and on update the total **cannot drop
  below what was already collected**.
- **Delivered implies ready**; un-readying clears delivered.
- **An AWB forces ready** and only direct orders can have one.
- A **credited** order cannot be deleted or moved to another event.

## bills.php

| action | method | notes |
|---|---|---|
| `get` | GET | `order_id` — issues the bill on first read |
| `issue` | POST | `order_id`, `refresh=true` to re-snapshot |
| `list` | GET | the bill book |
| `html` | GET | printable page; `size=thermal` for an 80mm roll |

`html` takes either an authenticated `order_id` **or** a `token` — the
bill's own public token, which is how a customer opens their copy without
a login.

A bill is **frozen at issue**: the row keeps a JSON snapshot of the order
as billed. Editing the order afterwards does not rewrite it; `refresh`
writes a new snapshot and bumps `revision`, so two printed copies are
always distinguishable. Numbering is `MFU/26-27/0001`, restarting each
April.

## stats.php

| action | query | returns |
|---|---|---|
| `dashboard` | `from`, `to`, `event` | headline, change vs the previous window, today, work queues |
| `series` | + `bucket` = day/week/month | points for the chart, zero-filled |
| `breakdown` | `from`, `to` | products, payment modes, channels, hours, top customers |

Two profit figures, deliberately:

- **`product_profit`** = revenue − (units × unit cost), using the per-event
  cost override where one exists. The day-to-day trading number.
- **`business_profit`** = revenue − (expenses − discounts). The basis
  `investment.php` distributes to partners. It counts one-off purchases
  like machinery, so a month with a big purchase reads negative even when
  the stall did well. Both are reported so the app and the website never
  disagree about either.

## finance.php

| action | method | notes |
|---|---|---|
| `overview` | GET | partner rows, totals, settle-up plans, uncredited offline |
| `movements` | GET | `partner_id`, `direction`, `from`, `to` |
| `add_movement` | POST | `partner_id`, `direction`, `amount`, `mov_date`, `source`, `note` |
| `credit_offline` | POST | `partner_id`, `from`, `to` |
| `credit_event` | POST | `event_id`, `partner_id` |
| `settle` | POST | `from_partner_id`, `to_partner_id`, `amount` |
| `distribute_profit` | POST | `amount`, split equally across active partners |

The equal-share basis, which must stay identical to `investment.php` and
`movements.php`:

```
contribution    = paid − credited + invest_adjust
fair share      = Σ contribution ÷ number of partners
gap             = contribution − fair share      (negative owes, positive is owed)
account balance = credited − debited
```

`settle` writes the same paired rows `movements.php` writes: two `invest`
rows sharing a `transfer_id`, amount 0, `invest_adjust` ±amount. Account
balances do not move, because the cash passes between partners rather than
in or out of the business. Overshooting a gap is refused with the correct
figure in the message.

`credit_offline` stamps `orders.credited_mov_id`, so the same sale can
never be credited twice. `credit_event` credits net of anything already
credited from that event.

## expenses.php

| action | method | notes |
|---|---|---|
| `list` | GET | `from`, `to`, `category`, `partner_id`, `q` |
| `get` | GET | `id` — line items and who paid |
| `create` | POST | `exp_date`, `item`, `amount`, `discount`, `paid_by`, `category`, `paid_to`, `details`, optional `payments[]` split |
| `delete` | POST | `id` |
| `categories` | GET | — |

`create` always writes at least one `expense_payments` row. That table is
what `investment.php` reads as a partner's `paid`, so an expense recorded
without one would silently distort every partner's fair share. Passing
`payments` splits the net amount between partners; the split must add up.
