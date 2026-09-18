# MadeForU — Android app for sale.madeforu.co.in

A native Android app for the MadeForU order, sales and partner-finance
system, plus the JSON API layer it talks to. The app and the website share
one database: an order taken at a stall is on the website before the
partner has put the phone down, and a price changed on the website is in
the app at the next refresh.

Two new things this adds beyond what the website does:

- **Bills.** A proper invoice for every order — shareable as a PDF on
  WhatsApp, printable, with a UPI QR for the balance, and a link the
  customer can open without signing in.
- **Walk-in sales.** An order with no customer details at all. At a stall
  most people do not want to give a phone number, and the website's save
  path refuses to store an order without one.

```
madeforu-app/
├── api/           PHP JSON API — upload beside config.php on the server
├── android/       Kotlin + Jetpack Compose app — open in Android Studio
├── pwa/           the same system as a web app — for iPhones, and any browser
├── web-patch/     one function in save.php the website needs changed
└── docs/          deploy, build and API reference
```

## Start here

1. **[docs/DEPLOY-API.md](docs/DEPLOY-API.md)** — run one SQL file, upload
   one folder. Ten minutes.
2. **[web-patch/README.md](web-patch/README.md)** — replace one function in
   `save.php` so the website can still edit a walk-in order.
3. **[docs/BUILD-ANDROID.md](docs/BUILD-ANDROID.md)** — open `android/` in
   Android Studio and press Run.
4. **[docs/DEPLOY-PWA.md](docs/DEPLOY-PWA.md)** — upload `pwa/` as `app/`
   for the partner on an iPhone. Safari → Share → Add to Home Screen.

Partners sign in with the **same phone and password as the website**. No
new accounts, no separate password to forget.

## What is in the app

**Home** — today's takings, the range you pick (today / 7 days / this month
/ 90 days / this year), revenue against the previous period, a revenue
trend chart, and the three queues that mean work: orders to make, orders
waiting to be handed over, and money still owed.

**New sale** — the fast path. Tap products, watch the total, take the
money. Customer details are one collapsed section that stays shut unless
someone wants a bill in their name. Discounts and extra charges are behind
a second collapsed section, so the common sale is four taps.

**Orders** — search by order number, name, phone, item or tracking number;
filter by payment and by status; the header totals cover the whole filtered
set rather than the visible page.

**Order** — payments ledger, ready/delivered, Delhivery tracking, the same
WhatsApp messages the website sends, and the bill.

**Bill** — preview, send as PDF, print (or save as PDF through Android's
print dialog), share the customer link, or print to an 80mm counter roll.
Re-issuing after an edit bumps a revision rather than rewriting the copy
the customer already has.

**Stats** — what sold and at what margin, how customers paid, which stalls
earned, the busiest hours, and the top customers.

**Money** — what each partner has put in, what they are owed, and the
shortest set of transfers that makes everyone square. Crediting offline
sales and event revenue, expenses, and the business profit position.

## Seeing the screens without running the app

There are no XML layouts — this is Compose, so the UI is Kotlin. Open
`android/app/src/main/java/com/madeforu/sales/ui/Previews.kt` and switch
the editor to **Split**: the bill, the order rows, the product picker, the
KPI cards and the charts all render there against sample data, in light
and dark.

## How it fits together

```
     Android app  ──HTTPS/JSON──►  api/*.php  ──requires──►  config.php
                                       │                        │
  sale.madeforu.co.in (website) ───────┴────────────────────────┘
                                       │
                                  u291217659_sale
```

`api/_bootstrap.php` requires the website's own `config.php`, so
`recalc_total`, `recalc_paid`, `pay_status`, `clamp_discount`,
`normalise_status` and `generate_order_no` have exactly one definition. The
API cannot drift from the website's arithmetic, because it *is* the
website's arithmetic. Change `config.php` and both follow.

The order rules are the ones `save.php` already enforced: prices come from
the catalogue and never from the request, duplicate lines merge, the extra
charge is added before the discount, an order cannot be overpaid, a total
cannot drop below what has been collected, delivered implies ready, and an
AWB means dispatched.

## What was verified, and what was not

The API was diffed against the current server code you supplied: `config.php`
and `save.php` are byte-identical to what it was built on, so every helper it
re-uses is the live one. The only drift since was `products.image_url` and
`products.product_url` (added for the public `menu.php` catalogue and
`share.php`); the API and app now carry both, the order picker shows the
photo, and the app's WhatsApp product share sends character-for-character
what `share.php` sends.

The API was run end to end against a restore of the production dump — 98
real orders, four partners, MariaDB with `ONLY_FULL_GROUP_BY` and
`STRICT_TRANS_TABLES` on, exactly as the live server runs. Orders, walk-in
sales, payment guards, bills, statistics and the partner ledger were all
exercised against that data; the settle-up was checked to move contribution
gaps without touching account balances, and the partner gaps to sum to
zero. That run found and fixed a real bug: `products` uses a different
collation from `product_costs`, so the catalogue join needed an explicit
`COLLATE` or it would have failed on the live server.

The Android sources were parse-checked with the Kotlin 2.0.21 compiler and
the money formatting was compiled and unit-tested (Indian digit grouping:
₹12,34,567.50, not ₹1,234,567.50). **The app itself has not been compiled
or run** — that needs the Android SDK, which this environment could not
download. Expect the ordinary first-build friction of a project that has
not been through Android Studio yet: a missing import, a version nudge in
`libs.versions.toml`. Nothing structural.
