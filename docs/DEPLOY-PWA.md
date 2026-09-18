# The web app (PWA) — installing it on an iPhone

`pwa/` is the same system as the Android app, as a web app. It talks to
the same `api/`, so an order taken on an iPhone is on the website and in
the Android app immediately.

It is four files and an icon folder. No build step, no `npm`, nothing to
compile — upload and it runs.

## 1. Upload

Put the whole `pwa/` folder on the server as **`app`**, beside `api/`:

```
public_html/
├── config.php
├── api/          ← already there
└── app/          ← this folder, renamed from pwa/
    ├── index.html
    ├── app.css
    ├── app.js
    ├── manifest.webmanifest
    ├── sw.js
    └── icons/
```

The app finds the API at `../api/` relative to itself, so as long as
`app/` and `api/` are siblings there is nothing to configure. (If you put
it elsewhere, sign-in has a "Use a different server" field.)

## 2. It must be HTTPS

A service worker — the thing that makes it installable and lets it open
offline — only runs on HTTPS. `sale.madeforu.co.in` already has a
certificate, so this is only a warning for anyone testing over plain HTTP:
it will still work, just not install.

## 3. Install it on an iPhone

Safari only. Chrome on iOS cannot add to the Home Screen.

1. Open **https://sale.madeforu.co.in/app/**
2. Tap **Share** (the ↑ box at the bottom)
3. Scroll and tap **Add to Home Screen**
4. Name it **MadeForU** and tap **Add**

It now has the logo on the Home Screen and opens full screen with no
address bar. The app tells iPhone users this itself, on the sign-in
screen, so partners do not need talking through it.

**On Android:** Chrome shows an "Install app" prompt, or use ⋮ → **Add to
Home screen**. Partners with the Android APK do not need this.

## What works, and what does not

Everything a partner does between sales: sign in, the dashboard, orders
with search and filters, taking a payment, marking ready and delivered,
the full new-sale flow including walk-ins, bills (view, print, counter
roll, share), statistics, the investment summary, and expenses with their
line-item breakdown.

Editing products, events and settings is deliberately read-only here —
those are rare, and they are already on the website and in the Android
app. The PWA is for the things done standing up.

**No push notifications**, and no on-device PDF: printing and saving a
PDF go through Safari's own print sheet, which produces the same document
the Android app generates.

## Updating it

Change a file, upload it, and bump `CACHE` in `sw.js`:

```js
const CACHE = 'madeforu-shell-v2';   // was v1
```

Without that bump, phones keep serving the cached copy of the old files
and nobody sees the change. API responses are never cached — a partner
balance served from a stale cache is worse than an error, because it
looks exactly like a fresh one.

Partners can also force it: **Settings → Check for updates** in the app.
