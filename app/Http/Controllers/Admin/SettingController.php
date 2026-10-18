<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /** key => [label, type, help] */
    public const FIELDS = [
        'Store' => [
            'announcement' => ['Announcement bar text', 'text', 'Shown at the top of every page. Leave blank to hide.'],
            'home_title' => ['Home page title', 'text', 'The <title> Google shows for the home page (aim for 50–60 characters).'],
            'home_description' => ['Home page meta description', 'textarea', '70–160 characters.'],
        ],
        'Shipping & payment' => [
            'free_shipping_over' => ['Free shipping over (₹)', 'number', ''],
            'shipping_fee' => ['Shipping fee (₹)', 'number', ''],
            'low_stock_threshold' => ['Low-stock warning at (units)', 'number', 'You get an email when a counted product drops to this, and the product page shows “Only N left”.'],
            'cod_enabled' => ['Cash on delivery enabled', 'bool', ''],
            'cod_fee' => ['Cash on delivery charges, fixed (₹)', 'number', 'Shown as its own line, “Cash on delivery charges”.'],
            'cod_fee_percent' => ['Cash on delivery charges, % of order', 'number', 'Optional. The higher of the fixed amount and this % is charged — e.g. ₹40 and 2%.'],
            'cod_max' => ['COD allowed up to (₹)', 'number', 'Orders above this must pay online.'],
        ],
        'Payment gateways' => [
            'payments.default' => ['Pre-selected online gateway at checkout', 'select:razorpay=Razorpay,cashfree=Cashfree', 'When both are on, customers see both; this one is ticked first.'],
            'razorpay.enabled' => ['Razorpay enabled', 'bool', ''],
            'razorpay.key' => ['Razorpay Key ID', 'text', 'Razorpay Dashboard → Account & Settings → API Keys (rzp_live_…).'],
            'razorpay.secret' => ['Razorpay Key Secret', 'secret', ''],
            'razorpay.webhook_secret' => ['Razorpay webhook secret', 'secret', 'Webhook URL: /webhooks/razorpay — events payment.captured and order.paid.'],
            'cashfree.enabled' => ['Cashfree enabled', 'bool', ''],
            'cashfree.mode' => ['Cashfree mode', 'select:production=Live (production),sandbox=Test (sandbox)', 'Use Test with sandbox keys to try it first.'],
            'cashfree.app_id' => ['Cashfree App ID', 'text', 'Cashfree Merchant Dashboard → Developers → API Keys.'],
            'cashfree.secret' => ['Cashfree Secret Key', 'secret', 'Also used to check webhooks. Webhook URL: /webhooks/cashfree (Payment success).'],
        ],
        'Delhivery shipping' => [
            'delhivery.enabled' => ['Delhivery enabled', 'bool', ''],
            'delhivery.auto' => ['Create the Delhivery shipment automatically when an order is confirmed', 'bool', 'COD orders when placed, online orders once paid. Untick to create shipments by hand from each order.'],
            'delhivery.mode' => ['Delhivery account', 'select:production=Live,staging=Test (staging)', ''],
            'delhivery.token' => ['Delhivery API token', 'secret', 'Delhivery One → Settings → API setup.'],
            'delhivery.pickup_location' => ['Pickup location (warehouse) name', 'text', 'Exactly as registered with Delhivery, e.g. MADEFORU HYD.'],
            'delhivery.pickup_pincode' => ['Pickup PIN code', 'text', ''],
            'delhivery.shipping_mode' => ['Shipping mode', 'select:Surface=Surface,Express=Express (air)', ''],
            'delhivery.default_weight' => ['Default weight per item (grams)', 'number', 'Used when a product has no weight set.'],
            'delhivery.check_pincodes' => ['Check PIN codes with Delhivery at checkout', 'bool', 'Stops orders to places Delhivery can’t reach, and COD where it can’t collect cash.'],
        ],
        'Email notifications' => [
            'notify.admin_emails' => ['Shop emails that get notifications', 'text', 'Comma separated, e.g. orders@madeforu.co.in, you@gmail.com. Blank = the store email.'],
            'notify.admin_new_order' => ['Email me every new order', 'bool', ''],
            'notify.admin_reviews' => ['Email me when a review is waiting', 'bool', ''],
            'notify.admin_shipping' => ['Email me about Delhivery problems (booking failed, parcel returned)', 'bool', ''],
            'notify.admin_low_stock' => ['Email me when stock runs low', 'bool', ''],
            'notify.admin_daily_summary' => ['Daily summary email', 'bool', 'Orders and revenue, what to make, parcels to hand over, unpaid online orders, low stock, reviews to approve.'],
            'notify.daily_summary_time' => ['Daily summary time (24h)', 'text', 'e.g. 21:00'],
            'notify.customer_in_production' => ['Customer: “We’re making your gift” (status Being made)', 'bool', 'The order confirmation (with invoice) always goes.'],
            'notify.customer_shipped' => ['Customer: shipped, with tracking', 'bool', ''],
            'notify.customer_delivered' => ['Customer: delivered', 'bool', ''],
            'notify.customer_cancelled' => ['Customer: order cancelled', 'bool', ''],
            'notify.customer_refunded' => ['Customer: refund sent (payment marked Refunded)', 'bool', ''],
            'notify.customer_returned' => ['Customer: parcel returned by courier', 'bool', ''],
        ],
        'Reviews' => [
            'reviews.request_enabled' => ['Ask for a review after delivery', 'bool', 'Email (and WhatsApp when set up) with a link to review each item, with photos.'],
            'reviews.request_days' => ['Days after delivery', 'number', ''],
            'reviews.auto_approve' => ['Publish verified 4★ and 5★ reviews straight away', 'bool', 'Otherwise every review waits for you in Admin → Reviews.'],
            'reviews.whatsapp_template' => ['WhatsApp template for review requests', 'text', 'Optional. {{1}} name, {{2}} order no., {{3}} review link.'],
        ],
        'Abandoned cart reminders' => [
            'abandoned.enabled' => ['Save carts and send reminders', 'bool', 'A cart is saved once the shopper types an email or phone at checkout.'],
            'abandoned.first_hours' => ['First reminder after (hours)', 'number', ''],
            'abandoned.second_hours' => ['Second reminder after (hours)', 'number', 'Counted from when the cart was left.'],
            'abandoned.coupon' => ['Coupon code in the second reminder', 'text', 'Optional. Create it in Coupons first (e.g. COMEBACK10).'],
            'abandoned.whatsapp_template' => ['WhatsApp template for reminders', 'text', 'Optional, needs the WhatsApp API. {{1}} name, {{2}} product, {{3}} cart link.'],
        ],
        'Invoice (GST)' => [
            'invoice.legal_name' => ['Business name on invoices', 'text', ''],
            'invoice.gstin' => ['GSTIN', 'text', 'Leave blank if not GST-registered: invoices then show no tax lines.'],
            'invoice.address' => ['Business address', 'textarea', ''],
            'invoice.state' => ['Business state', 'text', 'Customers in this state get CGST + SGST; other states IGST.'],
            'invoice.gst_rate' => ['Default GST rate %', 'number', 'Prices include GST. A product can have its own rate.'],
            'invoice.hsn' => ['Default HSN/SAC code', 'text', 'Used when a product has none.'],
            'invoice.prefix' => ['Invoice number prefix', 'text', 'Invoices are numbered PREFIX/26-27/0001, starting again each April.'],
            'invoice.footer' => ['Invoice footer note', 'text', ''],
        ],
        'WhatsApp messages' => [
            'whatsapp_api.enabled' => ['Send WhatsApp messages automatically', 'bool', 'Uses the WhatsApp Business Cloud API (Meta). The buttons on each order work even without this.'],
            'whatsapp_api.phone_number_id' => ['Phone number ID', 'text', 'Meta → WhatsApp Manager → API setup.'],
            'whatsapp_api.token' => ['Permanent access token', 'secret', 'System-user token with whatsapp_business_messaging permission.'],
            'whatsapp_api.language' => ['Template language code', 'text', 'en, en_US or hi — as approved.'],
            'whatsapp_api.template_confirmed' => ['Template: order confirmed', 'text', '{{1}} name, {{2}} order no., {{3}} total, {{4}} tracking link.'],
            'whatsapp_api.confirmed_invoice' => ['Order-confirmed template has a document header (sends the invoice PDF)', 'bool', ''],
            'whatsapp_api.template_shipped' => ['Template: order shipped', 'text', '{{1}} name, {{2}} order no., {{3}} courier, {{4}} AWB, {{5}} tracking link.'],
            'whatsapp_api.template_delivered' => ['Template: order delivered', 'text', '{{1}} name, {{2}} order no., {{3}} shop link.'],
            'whatsapp_api.template_admin' => ['Template: new order alert to you', 'text', '{{1}} order no., {{2}} customer, {{3}} total, {{4}} payment.'],
            'whatsapp_api.admin_numbers' => ['Your WhatsApp number(s) for new-order alerts', 'text', 'With country code, comma separated: 919876543210'],
        ],
        'Analytics & search engines' => [
            'analytics.ga4' => ['Google Analytics 4 measurement ID', 'text', 'G-XXXXXXXXXX'],
            'analytics.gtm' => ['Google Tag Manager container', 'text', 'GTM-XXXXXXX'],
            'analytics.google_ads' => ['Google Ads ID', 'text', 'AW-XXXXXXXXX'],
            'analytics.google_ads_purchase_label' => ['Google Ads purchase conversion label', 'text', ''],
            'analytics.meta_pixel' => ['Meta (Facebook/Instagram) Pixel ID', 'text', ''],
            'analytics.clarity' => ['Microsoft Clarity project ID', 'text', 'Free heatmaps and session recordings.'],
            'analytics.google_verification' => ['Google Search Console verification code', 'text', 'Only the content="..." value.'],
            'analytics.bing_verification' => ['Bing Webmaster verification code', 'text', ''],
            'analytics.pinterest_verification' => ['Pinterest verification code', 'text', ''],
            'analytics.facebook_domain_verification' => ['Facebook domain verification code', 'text', ''],
        ],
    ];

    public static function inputName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** 'select:a=Label A,b=Label B' -> ['a' => 'Label A', 'b' => 'Label B'] */
    public static function options(string $type): array
    {
        return collect(explode(',', substr($type, 7)))->mapWithKeys(function ($pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);

            return [$k => $v ?? $k];
        })->all();
    }

    public function edit()
    {
        return view('admin.settings', ['groups' => self::FIELDS]);
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label, $type]) {
                $rules[self::inputName($key)] = match (true) {
                    str_starts_with($type, 'select:') => 'nullable|in:'.implode(',', array_keys(self::options($type))),
                    $type === 'number' => 'nullable|numeric|min:0',
                    $type === 'bool' => 'nullable',
                    $type === 'textarea' => 'nullable|string|max:500',
                    default => 'nullable|string|max:255',
                };
            }
        }
        $request->validate($rules);

        $values = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label, $type]) {
                $name = self::inputName($key);
                // Secrets are never shown again; an empty box keeps the saved one.
                if ($type === 'secret') {
                    if ($request->boolean($name.'_clear')) {
                        $values[$key] = '';
                    } elseif (trim((string) $request->input($name, '')) !== '') {
                        $values[$key] = trim((string) $request->input($name));
                    }

                    continue;
                }
                $values[$key] = $type === 'bool' ? ($request->boolean($name) ? '1' : '0') : trim((string) $request->input($name, ''));
            }
        }
        Setting::put($values);

        return back()->with('status', 'Settings saved.');
    }
}
