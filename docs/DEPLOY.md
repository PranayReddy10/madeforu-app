# Making a change actually appear

Five things update separately, and none of them tells you when it is the
one that is behind. This is the whole checklist.

| Thing | Lives where | How it updates |
|---|---|---|
| **The website** (`sale/*.php`) | `sale.madeforu.co.in/` | You upload the files |
| **The API** (`api/*.php`) | `sale.madeforu.co.in/api/` | You upload the files |
| **The database** | the MySQL database | You run `api/migrations/*.sql` in phpMyAdmin |
| **The web app** (`pwa/*`) | `sale.madeforu.co.in/app/` | You upload the files |
| **The Android app** | On each partner's phone | You rebuild and install the APK |

`git pull` updates the copy on **your Mac**. It does not touch the server.
Nothing in this repository can reach `sale.madeforu.co.in` — until the
files are uploaded, the website and the web app are running whatever was
uploaded last time.

## Check what is live before anything else

Open the web app → **Settings → Version**. It prints three lines:

- **App build** — which copy of `app.js` this phone is running
- **Offline cache** — which copy the browser has saved for offline use
- **Server API** — what version `api/` on the server is

If it says *"The server is running older API files"*, the upload below has
not happened yet. The Android app says the same thing in its own
Settings → Version.

## 1. Upload the API

Hostinger → hPanel → **File Manager** → `public_html/api/`, and upload
everything from this repo's `api/` folder, overwriting.

**Do not upload `config.php`.** It is not in this repository — it holds
the database password and the copy already on the server is the right one.

Check it worked:

    https://sale.madeforu.co.in/api/auth.php?action=ping

should answer with `"api_version":"1.1.0"` and a `features` list. If it
still says `1.0.0`, the files did not land.

## 1b. Run the database migration

`api/migrations/` holds `.sql` files. Run any you have not run yet, in
hPanel → **Databases → phpMyAdmin** → pick `u291217659_sale` → **SQL** tab
→ paste the file → Go.

They are written to be safe to run twice, so if you are unsure whether one
has been applied, run it again.

The API works with or without them — it checks what the database actually
has and adapts — so the upload order does not matter. Until
`2026-09-price-history.sql` is run, price history is empty and Stats falls
back to live costs, which is what it did before.

## 1c. Upload the website

`sale/` is the website itself — the same PHP that runs
`sale.madeforu.co.in` today, with the price fix applied. Upload its
contents to `public_html/`.

**Do not upload `config.php`** — it is not in this repository (it holds
the database password, and this repository is public). The copy already on
the server is the right one. `sale/config.example.php` shows its shape if
you ever need to recreate it.

`sale/uploads/` is not in the repository either: it holds receipts and
photos people have uploaded, and the server's copy is the real one.

## 2. Upload the web app

Upload this repo's `pwa/` folder to `public_html/app/` (the folder on the
server is called `app`, the one here is called `pwa` — same files).

Then, on each phone that already has it: **Settings → Force a fresh copy**.
A web app saves its own files for offline use, so a phone can keep showing
the old screen for days after a good upload. That button clears the saved
copy and reloads. It is the first thing to try whenever an update does not
show up.

### If an update still does not show

The web app is versioned: `index.html` asks for `app.js?v=2026-09-19.1`,
and a new version is a new URL that no cache can answer from. If you edit
these files yourself, bump the version in all four places and run
`pwa/check-versions.sh` — it fails if they disagree. `pwa/.htaccess` also
tells the server never to cache `index.html` or `sw.js`; upload it with
the rest.

Settings → Version shows which build the phone is actually running, and
**Force a fresh copy** clears everything and reloads.

## 3. Rebuild the Android app

    git pull
    cd android
    ./gradlew installDebug          # a phone plugged in by USB
    ./gradlew assembleRelease       # an APK to share with the partners

The APK lands in `android/app/build/outputs/apk/`. To push it to the other
partners, upload it somewhere they can download from, then put the link in
**Settings → App updates for partners** and raise the version code — their
apps check it on launch and offer the update.

Confirm the build that installed is the new one in **Settings → Version**:
it should read 1.1.0 (code 2), not 1.0.0.

## If a screen is still blank after all that

A screen that needs something the server does not have now says so in
place rather than rendering nothing — that message names the file to
upload. If a screen is blank with no message, that is a bug; say which
screen and what you expected.
