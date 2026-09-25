# Superseded by DEPLOY.md

The API used to live in a top-level `api/` folder. It is now `sale/api/`,
inside the one folder that maps to `public_html/`, so there is a single
upload rather than three. See **`docs/DEPLOY.md`** — it covers the
website, the API, the database migrations, the web app and the Android
build in the order they need doing.

## The app gets HTTP 403 while the web app works

`sale.madeforu.co.in` is served through Cloudflare. Cloudflare's bot
protection (Bot Fight Mode, "Under Attack" mode, or a challenge rule)
recognises the Android app as "not a browser" from how it connects, not
from its User-Agent. It answers with a 403 challenge page before PHP runs.
Chrome passes that check silently, which is why the web app works.

The app now handles this itself. When Cloudflare asks, a "Checking the
connection" screen opens and passes the check once in a WebView, and the
app keeps the cookie. The clean fix, though, is to not challenge the API
at all. It is JSON for the apps, not pages for people:

- **Cloudflare dashboard** → the domain → Security → WAF → Custom rules →
  create a rule for *URI Path starts with `/api/`* with action **Skip**,
  ticking all the managed/bot/challenge features it offers.
  On the free plan **Bot Fight Mode cannot be skipped by a rule**. Turn
  it off under Security → Bots instead.
- **Hostinger CDN** (if the domain's Cloudflare is managed by Hostinger):
  hPanel → Websites → the site → Performance → CDN → turn off bot
  protection / "Under attack" mode, or turn the CDN off for this subdomain.
