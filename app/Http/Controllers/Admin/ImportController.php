<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ImportQueue;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function index()
    {
        return view('admin.import.index', [
            'state' => ImportQueue::state(),
            'log' => ImportQueue::logTail(),
            'counts' => [
                'Products' => Product::count(),
                'Categories' => Category::count(),
                'Customers' => User::where('is_admin', false)->count(),
                'Orders' => Order::count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url:https,http'],
            'key' => ['nullable', 'string', 'starts_with:ck_', 'required_with:secret'],
            'secret' => ['nullable', 'string', 'starts_with:cs_', 'required_with:key'],
            'customers' => ['nullable', 'boolean'],
            'images' => ['nullable', 'boolean'],
        ]);
        if (ImportQueue::busy()) {
            return back()->with('error', 'An import is already waiting or running.');
        }
        ImportQueue::queue(rtrim($data['url'], '/'), $data['key'] ?? null, $data['secret'] ?? null, (bool) ($data['customers'] ?? false), (bool) ($data['images'] ?? false));

        return redirect()->route('admin.import')->with('status', 'Import queued. It starts within a minute if the cron job is set up — or press “Run now”.');
    }

    /** Run the waiting import inside this request, for when the cron job isn't set up yet. */
    public function run()
    {
        ImportQueue::runQueued();
        $state = ImportQueue::state();

        return redirect()->route('admin.import')->with(
            ($state['status'] ?? '') === 'failed' ? 'error' : 'status',
            ($state['status'] ?? '') === 'failed' ? 'Import failed: '.$state['error'] : 'Import finished.'
        );
    }
}
