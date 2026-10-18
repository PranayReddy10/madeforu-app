<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCart;
use App\Models\Order;
use Illuminate\Http\Request;

class AbandonedCartController extends Controller
{
    public function index(Request $request)
    {
        $q = AbandonedCart::with('recoveredOrder')->latest('updated_at');
        match ($request->query('show')) {
            'recovered' => $q->whereNotNull('recovered_at'),
            'open' => $q->whereNull('recovered_at')->whereNull('unsubscribed_at'),
            default => null,
        };
        $since = now()->subDays(30);
        $recent = AbandonedCart::where('created_at', '>=', $since);

        return view('admin.abandoned.index', [
            'carts' => $q->paginate(30)->withQueryString(),
            'stats' => [
                'Carts left (30 days)' => (clone $recent)->count(),
                'Value left' => money((clone $recent)->whereNull('recovered_at')->sum('value')),
                'Recovered after a reminder' => (clone $recent)->whereNotNull('recovered_at')->where('reminders_sent', '>', 0)->count(),
                'Recovered revenue' => money(Order::whereIn('id', (clone $recent)->where('reminders_sent', '>', 0)->whereNotNull('recovered_order_id')->pluck('recovered_order_id'))->sum('total')),
            ],
        ]);
    }
}
