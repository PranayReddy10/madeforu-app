# Deploying the API to sale.madeforu.co.in

Ten minutes, two steps, no downtime. The API only **adds** files and
tables; nothing the website already does changes.

## 1. Run the migration

Open **phpMyAdmin → `u291217659_sale` → SQL**, paste the whole of
`api/api_schema.sql`, and run it.

It creates three tables (`api_tokens`, `app_settings`, `bills`) and
relaxes two column defaults on `orders` so a walk-in sale can be stored.
Every statement is idempotent, so running it twice is harmless.

One statement can fail harmlessly:

```
CREATE INDEX idx_orders_created_event ON orders (created_at, event_id);
```

If phpMyAdmin says **"Duplicate key name"**, the index already exists.
Ignore it — nothing else depends on it.

## 2. Upload the folder

Upload the whole `api/` directory into the same folder as `config.php`:

```
public_html/
├── config.php          ← already there
├── index.php           ← already there
├── save.php            ← already there
└── api/                ← new
    ├── .htaccess
    ├── _bootstrap.php
    ├── auth.php
    ├── bills.php
    ├── catalog.php
    ├── expenses.php
    ├── finance.php
    ├── orders.php
    └── api_schema.sql
```

`api/_bootstrap.php` does `require __DIR__ . '/../config.php'`, so the
folder must sit directly beside it. Do not copy `config.php` into `api/` —
two copies of the database credentials is exactly the problem this avoids.

**Delete `api/api_schema.sql` from the server after the migration.** The
bundled `.htaccess` already denies `.sql` requests, but a file that is not
there cannot be served by a misconfiguration.

## 3. Check it

```bash
curl https://sale.madeforu.co.in/api/auth.php?action=ping
# {"ok":true,"api_version":"1.0.0","server_time":"..."}
```

Then sign in with a real admin phone and password:

```bash
curl -X POST https://sale.madeforu.co.in/api/auth.php?action=login \
  -H 'Content-Type: application/json' \
  -d '{"phone":"9XXXXXXXXX","password":"...","device":"curl"}'
```

A token in the reply means everything works.

## If the app says "Sign in to continue" on every screen

Hostinger runs PHP through CGI, which drops the `Authorization` header
before PHP sees it. `api/.htaccess` re-injects it. If the file did not
upload (dot-files are hidden in some FTP clients) the API never sees the
token.

Check it is there, and if `mod_rewrite` is unavailable on the plan the API
also accepts `?token=…` as a fallback, which the app uses for bill pages
already.

## Security notes

- Tokens are stored as SHA-256 hashes; the plaintext token exists only on
  the phone. A database dump cannot be replayed as a login.
- Tokens last 90 days. Changing a password revokes every other device.
- The login throttle is shared with the website: 5 failures per phone+IP
  buys a 15-minute lockout, whichever door was used.
- Bill links (`?action=html&token=…`) use a separate 32-character random
  token that grants access to that one bill and nothing else. This is the
  same trust model `track.php` already uses.
