<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Setting;
use App\Support\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Admin → Campaign: one festival at a time (Diwali, Christmas…), with its coupon. */
class CampaignController extends Controller
{
    public function edit()
    {
        return view('admin.campaign', [
            'categories' => Category::orderBy('path')->get(['id', 'name', 'path']),
            'presets' => Campaign::presets(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'festival' => 'required|date',
            'deadline' => 'required|date|before_or_equal:festival',
            'headline' => 'nullable|string|max:120',
            'coupon' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount' => 'nullable|numeric|min:0|max:90',
            'min_order' => 'nullable|numeric|min:0',
            'category' => 'nullable|integer|exists:categories,id',
        ], ['deadline.before_or_equal' => 'The order-by date must be on or before the festival.', 'coupon.regex' => 'Coupon codes use letters and numbers only.']);
        $on = $request->boolean('enabled');

        Setting::put([
            'campaign.enabled' => $on ? '1' : '0',
            'campaign.name' => $data['name'],
            'campaign.festival' => Carbon::parse($data['festival'])->toDateString(),
            'campaign.deadline' => Carbon::parse($data['deadline'])->toDateString(),
            'campaign.headline' => $data['headline'] ?? '',
            'campaign.coupon' => strtoupper($data['coupon'] ?? ''),
            'campaign.discount' => (string) ($data['discount'] ?? ''),
            'campaign.min_order' => (string) ($data['min_order'] ?? ''),
            'campaign.category' => (string) ($data['category'] ?? ''),
            'campaign.slug' => '',
        ]);

        // The coupon lives as long as the campaign: until the end of the festival day.
        if (! empty($data['coupon']) && (float) ($data['discount'] ?? 0) > 0) {
            Coupon::updateOrCreate(['code' => strtoupper($data['coupon'])], [
                'type' => 'percent', 'value' => $data['discount'], 'min_subtotal' => $data['min_order'] ?? 0,
                'starts_at' => now()->startOfDay(), 'expires_at' => Carbon::parse($data['festival'])->endOfDay(), 'is_active' => $on,
            ]);
        }

        return back()->with('status', $on ? $data['name'].' campaign is live: countdown strip, product notes, landing page'.(! empty($data['coupon']) ? ' and coupon '.strtoupper($data['coupon']) : '').'.' : 'Campaign saved but switched off.');
    }
}
