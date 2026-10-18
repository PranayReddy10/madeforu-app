<?php

return [
    'name' => env('STORE_NAME', 'MadeForU'),
    'tagline' => env('STORE_TAGLINE', 'Made for your moments'),
    'phone' => env('STORE_PHONE', ''),
    'whatsapp' => env('STORE_WHATSAPP', ''),
    'email' => env('STORE_EMAIL', 'hello@madeforu.co.in'),
    'address' => env('STORE_ADDRESS', 'Hyderabad, Telangana, India'),
    'city' => env('STORE_CITY', 'Hyderabad'),
    'region' => env('STORE_REGION', 'Telangana'),
    'postcode' => env('STORE_POSTCODE', ''),
    'currency' => 'INR',
    'logo' => env('STORE_LOGO', 'images/logo-round.png'),
    'social' => array_filter([
        'instagram' => env('SOCIAL_INSTAGRAM'),
        'facebook' => env('SOCIAL_FACEBOOK'),
        'youtube' => env('SOCIAL_YOUTUBE'),
        'pinterest' => env('SOCIAL_PINTEREST'),
    ]),

    // Shipping & charges
    'free_shipping_over' => (float) env('FREE_SHIPPING_OVER', 499),
    'shipping_fee' => (float) env('SHIPPING_FEE', 60),
    'cod_enabled' => (bool) env('COD_ENABLED', true),
    'cod_fee' => (float) env('COD_FEE', 50),                    // fixed part of the COD charge
    'cod_fee_percent' => (float) env('COD_FEE_PERCENT', 0),     // % of the order; the higher of the two is charged
    'cod_max' => (float) env('COD_MAX', 5000),

    // Where product, category and banner images are stored: 'public' (this server) or 'spaces' (DigitalOcean Spaces).
    'media_disk' => env('MEDIA_DISK', 'public'),
    // Images already on these hosts are used where they are; the importer does not copy them.
    'media_keep_hosts' => array_filter(array_map('trim', explode(',', env('MEDIA_KEEP_HOSTS', 'digitaloceanspaces.com')))),

    // Delhivery (B2C). Also editable in Admin → Settings → Delhivery shipping.
    'delhivery' => [
        'enabled' => (bool) env('DELHIVERY_ENABLED', false),
        'auto' => (bool) env('DELHIVERY_AUTO', true),             // create the shipment as soon as an order is confirmed
        'mode' => env('DELHIVERY_MODE', 'production'),            // production | staging
        'token' => env('DELHIVERY_TOKEN'),
        'pickup_location' => env('DELHIVERY_PICKUP_LOCATION'),    // warehouse name exactly as registered with Delhivery
        'pickup_pincode' => env('DELHIVERY_PICKUP_PINCODE'),
        'shipping_mode' => env('DELHIVERY_SHIPPING_MODE', 'Surface'), // Surface | Express
        'default_weight' => (int) env('DELHIVERY_DEFAULT_WEIGHT', 300), // grams per item when a product has no weight
        'check_pincodes' => (bool) env('DELHIVERY_CHECK_PINCODES', true),
    ],

    // Customer photo uploads
    'upload_max_kb' => (int) env('UPLOAD_MAX_KB', 15360),
    'upload_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'],

    // Payments
    // Payments. Everything here can also be set in Admin → Settings → Payment gateways (that wins over .env).
    'payments' => [
        'default' => env('PAYMENT_DEFAULT', 'razorpay'),   // which online gateway is pre-selected at checkout
    ],
    'razorpay' => [
        'enabled' => (bool) env('RAZORPAY_ENABLED', true),
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],
    'cashfree' => [
        'enabled' => (bool) env('CASHFREE_ENABLED', true),
        'mode' => env('CASHFREE_MODE', 'production'),       // production | sandbox
        'app_id' => env('CASHFREE_APP_ID'),
        'secret' => env('CASHFREE_SECRET_KEY'),            // also signs webhooks
    ],

    // Analytics & search engines. All optional; anything left blank is simply not printed.
    'analytics' => [
        'ga4' => env('GA4_MEASUREMENT_ID'),          // G-XXXXXXX
        'gtm' => env('GTM_CONTAINER_ID'),            // GTM-XXXXXX
        'google_ads' => env('GOOGLE_ADS_ID'),        // AW-XXXXXXX
        'google_ads_purchase_label' => env('GOOGLE_ADS_PURCHASE_LABEL'),
        'meta_pixel' => env('META_PIXEL_ID'),
        'clarity' => env('MS_CLARITY_ID'),
        'google_verification' => env('GOOGLE_SITE_VERIFICATION'),
        'bing_verification' => env('BING_SITE_VERIFICATION'),
        'pinterest_verification' => env('PINTEREST_VERIFICATION'),
        'facebook_domain_verification' => env('FACEBOOK_DOMAIN_VERIFICATION'),
        'indexnow_key' => env('INDEXNOW_KEY'),
    ],

    // WooCommerce site the importer reads from
    'woo' => [
        'url' => env('WOO_URL', 'https://madeforu.co.in'),
        'consumer_key' => env('WOO_CONSUMER_KEY'),
        'consumer_secret' => env('WOO_CONSUMER_SECRET'),
    ],
];
