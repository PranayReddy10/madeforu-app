# Superseded

This folder held patches to apply by hand to the website, back when the
website's PHP was not in this repository. It is now: see **`sale/`**,
which is the whole site with both fixes already applied —

- **walk-in orders are editable** (`save.php`'s `customer()` no longer
  demands a name and a 10-digit phone);
- **a price change never reaches a completed sale** (`save.php` keeps the
  price each line was sold at when an order is edited, and
  `products.php` records every price change).

Upload `sale/` as described in `docs/DEPLOY.md`. Nothing in this folder
needs applying separately any more.
