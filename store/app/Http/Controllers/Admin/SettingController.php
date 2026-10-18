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
            'cod_enabled' => ['Cash on delivery enabled', 'bool', ''],
            'cod_fee' => ['COD fee (₹)', 'number', ''],
            'cod_max' => ['COD allowed up to (₹)', 'number', 'Orders above this must pay online.'],
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

    public function edit()
    {
        return view('admin.settings', ['groups' => self::FIELDS]);
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label, $type]) {
                $rules[self::inputName($key)] = match ($type) {
                    'number' => 'nullable|numeric|min:0',
                    'bool' => 'nullable',
                    'textarea' => 'nullable|string|max:500',
                    default => 'nullable|string|max:255',
                };
            }
        }
        $request->validate($rules);

        $values = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label, $type]) {
                $name = self::inputName($key);
                $values[$key] = $type === 'bool' ? ($request->boolean($name) ? '1' : '0') : trim((string) $request->input($name, ''));
            }
        }
        Setting::put($values);

        return back()->with('status', 'Settings saved.');
    }
}
