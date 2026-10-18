# MadeForU store — Laravel

The new **madeforu.co.in**: a Laravel storefront that replaces the WordPress/WooCommerce site on DigitalOcean and runs on Hostinger shared hosting.

Every product is personalised. The customer picks options (size, frame, colour), uploads as many photos as the product needs, adds names or a message, and pays by UPI, card or cash on delivery. Product and category URLs stay exactly as they are on WordPress, so Google rankings carry over.

## Why Laravel and not a new WordPress theme

| | New WP theme | This Laravel store |
|---|---|---|
| Unique design | Limited by WooCommerce templates | Fully custom, built for phones first |
| Photo uploads per product | Paid plugin, often slow | Built in: N photo slots per product, progress bar, low-quality warning |
| Speed on shared hosting | WordPress + Woo + plugins, heavy | Small pages, no plugins, WebP images |
| SEO | Yoast/RankMath plugin | Built in: Product/Offer/Review/FAQ/Breadcrumb schema, sitemaps, Merchant feed |
| Security & upkeep | Plugin updates every week | One small codebase |

WordPress is the easier choice if non-developers need to build new page layouts every week. For a personalised-gift shop that cares about speed, uploads and SEO, Laravel is the better long-term choice.

## What is built

- **Same URLs as WooCommerce**
  - Products: `/product/{slug}/`
  - Categories, including nested ones: `/product-category/{parent}/{child}/`
  - Also kept: `/shop/` and CMS pages such as `/about-us/`.
  - Old forms redirect with a 301: `?product=`, `?p=123`, `?s=`, `/shop/page/2/`, `/product-tag/x/`, old `/wp-content/uploads/...` image URLs, and Yoast sitemap names.
  - A redirect manager in admin handles anything else.
- **Importer** (`php artisan store:import-woo`) copies all of these from the live site:
  - categories, with their tree
  - products, with regular and sale prices
  - variations, with their own prices, SKUs, stock and images
  - every product image, downloaded and converted to WebP
  - reviews, Yoast SEO titles and descriptions, and pages
  - It can be re-run safely; nothing is duplicated.
- **Personalisation fields**, configured per product in admin:
  - photo upload with a min/max number of photos
  - text, message, dropdown (with an extra price per option), colour and date
  - Customer photos are private: only the customer's own session and the admin can see them. Admin can download all photos for an order as one ZIP.
- **Checkout**:
  - Razorpay and Cashfree (UPI, GPay, PhonePe, cards, netbanking), each checked with the gateway on the server and by webhook; switch either on or off and enter keys in Admin → Settings → Payment gateways
  - cash on delivery, with a fee and a maximum order value
  - coupons, free shipping above a threshold, and PIN-code lookup that fills in the city and state
- **Phone-first design**:
  - an app-style bottom tab bar: Home · Categories · Search · Cart · Account
  - swipeable gallery, a sticky "Personalise" button, and round category shortcuts on the home screen
  - installable to the home screen as a PWA, with an offline page
  - a WhatsApp chat button
- **SEO**:
  - per-product meta title, description, focus keyword, FAQs, canonical URL and noindex
  - JSON-LD: Product, AggregateOffer, Review, FAQPage, BreadcrumbList, OnlineStore and WebSite search box
  - Open Graph tags
  - image sitemaps
  - a Google Merchant Center feed and a Meta/Instagram catalogue feed
  - IndexNow for Bing
  - an SEO score for every product in admin
- **Analytics**:
  - GA4, Google Tag Manager, Google Ads conversion, Meta Pixel and Microsoft Clarity
  - verification tags for Search Console, Bing, Pinterest and Facebook
  - full e-commerce events: `view_item_list`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase` and `search`
  - Admin also logs which searches return no results, so you can see what customers want and don't find.
- **Admin** (`/admin`): dashboard, orders, products, categories, reviews, coupons, banners, pages, redirects, SEO and settings.

## Run it on your computer

```bash
cd store
composer install
cp .env.example .env    # then set DB_CONNECTION=sqlite and APP_ENV=local, APP_DEBUG=true
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan db:seed --class=DemoSeeder        # sample products, or run the importer below instead
php artisan store:admin you@example.com --password=secret123
php artisan serve
php artisan test
```

## Move the catalogue from WordPress

Run this while the WordPress site is still online:

```bash
php artisan store:import-woo https://madeforu.co.in
```

Without keys the importer reads WooCommerce's public Store API, which only shows published products. With a read-only REST API key (WooCommerce → Settings → Advanced → REST API → Add key, permission **Read**) it also brings in draft products (hidden), and with `--customers` your customer accounts and past orders, including the customers' uploaded photos:

```bash
php artisan store:import-woo https://madeforu.co.in --key=ck_… --secret=cs_… --customers
```

Imported customers have no password on the new site: they use **Forgot password** on the login page (by email or mobile) to set one, and find their past orders in My account. Old orders keep their WooCommerce number as `WC-1234`, and importing them sends no emails, WhatsApp or review requests and doesn't touch stock. Delete the API key afterwards.

If a security plugin or Cloudflare blocks the API, use the CSV export instead. If a security plugin or Cloudflare blocks it, use the CSV export instead: WooCommerce → Products → Export (all columns), then run:

```bash
php artisan store:import-woo --csv=wc-product-export.csv
```

Then go to **Admin → Products** and set, for each product:

- **How many photos it takes.** The importer guesses from the name ("Collage of 6 photos" becomes 6 photo slots) and defaults to 1.
- **Any extra fields.** For example a name to print, a dropdown with extra-cost options, or a date.

## Deploy on Hostinger shared hosting

You need a Business plan or higher, for SSH. Set the PHP version to **8.3** under Advanced → PHP Configuration.

1. **Database.** hPanel → Databases → create a MySQL database and user.
2. **Upload the code.** SSH in, then:

   ```bash
   cd ~/domains/madeforu.co.in
   git clone -b store-website https://github.com/PranayReddy10/madeforu-app.git store
   cd store
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && nano .env      # database, mail, Razorpay, analytics
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   php artisan store:admin you@madeforu.co.in
   php artisan store:import-woo https://madeforu.co.in --key=ck_… --secret=cs_… --customers   # while WP is still live
   php artisan optimize
   ```

   The `store-website` branch holds only this website, with the Laravel app at the top level. Later updates: `cd ~/domains/madeforu.co.in/store && git pull && composer install --no-dev -o && php artisan migrate --force && php artisan optimize`.

3. **Point the domain at `store/public`.**
   - **Option A (recommended):** replace public_html with a link:

     ```bash
     cd ~/domains/madeforu.co.in && mv public_html public_html.old && ln -s store/public public_html
     ```

   - **Option B:** clone or upload the branch straight into public_html. The top-level `.htaccess` sends every request to `public/` and blocks `.env`, `vendor/` and the other private folders.
4. **Cron job.** hPanel → Advanced → Cron Jobs, every minute:

   ```
   cd ~/domains/madeforu.co.in/store && php artisan schedule:run
   ```

   This deletes abandoned photo uploads and pings IndexNow.
5. **Payment gateways.** Enter keys in Admin → Settings → Payment gateways (or `.env`).
   - Razorpay: Dashboard → Webhooks → `https://madeforu.co.in/webhooks/razorpay`, events `payment.captured` and `order.paid`; paste the webhook secret in settings.
   - Cashfree: Developers → Webhooks → `https://madeforu.co.in/webhooks/cashfree`, event "Payment success". Cashfree signs webhooks with the secret key, so nothing extra to paste. Try it first with Mode = Test and sandbox keys.
6. **Images on DigitalOcean Spaces.** Imported images stay on your Spaces CDN (`friendsmedia.sgp1.cdn.digitaloceanspaces.com`), so they keep loading after the WordPress server is switched off. Keep the Space itself. To send new admin uploads to the same Space, set `MEDIA_DISK=spaces` and the `DO_SPACES_*` keys in `.env` (Spaces → Access keys).
7. **Switch DNS.** Move the domain's A record from DigitalOcean to Hostinger, then turn on the free SSL. Keep the DigitalOcean droplet for a week, in case anything was missed.

Without SSH: run `composer install` on your computer, zip the whole `store` folder including `vendor/`, upload it with File Manager, and run the artisan commands from hPanel's "Run command" or a one-off cron job.

## After launch: Google and search

- **Search Console.**
  1. Add the domain property.
  2. Submit `https://madeforu.co.in/sitemap.xml`.
  3. Under Pages, watch for 404s, and add a redirect in Admin → Redirects for any that matter.
- **Merchant Center.** Add `https://madeforu.co.in/feeds/google-merchant.xml` as a scheduled feed (daily). This gives free product listings in Google Shopping and enables Shopping/Performance Max ads.
- **Meta Commerce Manager.** Add the data feed `https://madeforu.co.in/feeds/facebook-catalog.csv` to enable Instagram and Facebook shop tags.
- **Bing Webmaster Tools.** Import the site from Search Console, and set `INDEXNOW_KEY` so changed pages get submitted automatically.
- **GA4.** Set the measurement ID in Admin → Settings, then mark `purchase` as a key event.
- **For each product,** write a unique meta description and add two or three FAQs. Admin → SEO lists the products that need work, worst first.

## Invoices and WhatsApp messages

**Invoices.** Every confirmed order (COD placed, or online payment received) gets an invoice number like `MFU/26-27/0001`, counting from 1 each April. The PDF is attached to the order email and can be downloaded from the thank-you page, the customer's account, a link in the email/WhatsApp, and Admin → Orders. With a GSTIN set in Admin → Settings → Invoice it is a tax invoice: prices include GST, CGST + SGST for your state, IGST for other states, per-product HSN and GST rate (Admin → Products). Without a GSTIN it is a plain invoice.

**WhatsApp, by hand (works now).** Each order in admin has buttons that open WhatsApp with the message written: order confirmation (with invoice link), photo check, shipped (with tracking), payment reminder, review request.

**WhatsApp, automatic.** Uses the WhatsApp Business Cloud API from Meta (free tier covers small shops; Meta charges per conversation after that).

1. In [Meta Business Manager](https://business.facebook.com) → WhatsApp Manager, add your business number, and create a permanent access token (System users → token with `whatsapp_business_messaging`).
2. Create these message templates (category **Utility**, language English) and wait for approval. Names can differ; put yours in Admin → Settings → WhatsApp messages.

   **order_confirmed** — header: *Document* (the invoice PDF is attached here)
   ```
   Hi {{1}}, thank you for your order {{2}} at MadeForU! 🎁
   Total: {{3}}. We've started making your personalised gift and will message you when it ships.
   Track your order: {{4}}
   ```
   **order_shipped**
   ```
   Hi {{1}}, your MadeForU order {{2}} is on its way with {{3}} 🚚
   Tracking number: {{4}}
   Track it here: {{5}}
   ```
   **order_delivered**
   ```
   Hi {{1}}, your MadeForU order {{2}} has been delivered 💝 We hope you love it!
   Share a photo or a review with us: {{3}}
   ```
   **new_order_alert** (to you)
   ```
   New order {{1}} from {{2}} — {{3}} ({{4}}).
   ```
3. In Admin → Settings → WhatsApp messages: tick "Send automatically", paste the Phone number ID and token, and your own number for new-order alerts.

Every message is logged on the order (sent / failed with Meta's reason), each step is sent once, and a failed message never blocks an order. Resend from the order page.

## Stock and abandoned carts

**Stock.** In Admin → Products, give a product (or each variation) a stock quantity; leave it blank for made-to-order items that never run out. Stock goes down when an order is confirmed and back up if it is cancelled or returned (each move is in the product's stock history). At 0 the product or option shows as out of stock; customers can't add more than is left, and checkout re-checks. At the low-stock level (Settings, default 3) the product page shows "Only N left", you get an email, and the dashboard lists it. Filter Products by Low stock / Out of stock.

**Abandoned carts.** When a shopper types an email or phone at checkout and doesn't order, the cart is saved. Reminders go after 1 hour and 24 hours (change in Settings → Abandoned cart reminders), the second one optionally with a coupon. The link restores the cart — photos included — and opens checkout filled in. Ordering from any device, or unsubscribing, stops them. Admin → Abandoned carts shows every cart, a one-tap WhatsApp message, and recovered revenue.

For WhatsApp reminders (needs the WhatsApp API above), add a **Marketing** template named `cart_reminder`:
```
Hi {{1}}, you left {{2}} in your MadeForU cart 💝 Your photos are saved — finish your order here: {{3}}
```

## Accounts and reviews

- **Forgot password:** "Forgot password?" on the login page emails a reset link, found by email or mobile number. Mail must be set up in `.env`.
- **Reviews with photos:** customers can add up to 3 photos to a review (resized, location data removed). Reviews from people who bought the product show **Verified buyer**. Customer photos appear in a strip above the reviews.
- **Review requests:** a couple of days after an order is delivered, the customer gets "How was your gift?" by email (and WhatsApp when set up) with a link to review every item on one page. Settings → Reviews: days to wait, and whether verified 4★/5★ reviews publish straight away (otherwise approve them in Admin → Reviews).
- Optional WhatsApp template **review_request** (Utility): `Hi {{1}}, how was your MadeForU order {{2}}? 💝 We'd love a quick review — photos of your gift are extra special: {{3}}`

## Email notifications

Emails go out through the mail account in `.env` (`MAIL_*`, e.g. a Hostinger mailbox `orders@madeforu.co.in`). Every email is logged on the order page under **Messages sent**, and each one goes only once per order.

**To the customer**
- Order confirmed — order summary, photos/text they chose, and the GST invoice PDF attached.
- In production, Shipped (with the Delhivery tracking link), Delivered, Cancelled, Returned and Refunded — sent when the order status changes, whether you change it in admin or Delhivery tracking does.
- Abandoned-cart reminders, review requests and password resets (see above).

**To the shop** (to `NOTIFY_ADMIN_EMAILS`, comma separated, or the store email)
- New order, with a link to the order in admin.
- Delhivery could not book a shipment (with Delhivery's reason), and parcels coming back (RTO).
- Low stock, and a new review waiting for approval.
- Daily summary at 21:00: today's orders and revenue, what to make, parcels to hand over, unpaid orders and shipping problems. Run `php artisan store:daily-summary --force` to see one now.

Turn any of them off, change the admin addresses or the summary time in **Admin → Settings → Email notifications**. Orders imported from WooCommerce never trigger emails. The daily summary needs the scheduler cron from the Hostinger section.
