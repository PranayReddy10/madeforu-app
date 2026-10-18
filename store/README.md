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
  - Razorpay (UPI, GPay, PhonePe, cards, netbanking), verified on the server and by webhook
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

The importer reads WooCommerce's public Store API, so it needs no keys. If a security plugin or Cloudflare blocks it, use the CSV export instead: WooCommerce → Products → Export (all columns), then run:

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
   git clone -b claude/cool-edison-l9ok82 https://github.com/PranayReddy10/madeforu-app.git app
   cd app/store
   composer install --no-dev --optimize-autoloader
   cp .env.example .env && nano .env      # database, mail, Razorpay, analytics
   php artisan key:generate
   php artisan migrate --force
   php artisan storage:link
   php artisan store:admin you@madeforu.co.in
   php artisan store:import-woo https://madeforu.co.in   # while WP is still live
   php artisan optimize
   ```

3. **Point the domain at `store/public`.**
   - **Option A (recommended):** replace public_html with a link:

     ```bash
     cd ~/domains/madeforu.co.in && mv public_html public_html.old && ln -s app/store/public public_html
     ```

   - **Option B:** upload the whole `store` folder into public_html. The `.htaccess` in `store/` sends every request to `public/` and blocks `.env`, `vendor/` and the other private folders.
4. **Cron job.** hPanel → Advanced → Cron Jobs, every minute:

   ```
   cd ~/domains/madeforu.co.in/app/store && php artisan schedule:run
   ```

   This deletes abandoned photo uploads and pings IndexNow.
5. **Razorpay webhook.** Dashboard → Webhooks → `https://madeforu.co.in/webhooks/razorpay`, with the events `payment.captured` and `order.paid`. Copy the webhook secret into `.env`.
6. **Switch DNS.** Move the domain's A record from DigitalOcean to Hostinger, then turn on the free SSL. Keep the DigitalOcean droplet for a week, in case anything was missed.

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
