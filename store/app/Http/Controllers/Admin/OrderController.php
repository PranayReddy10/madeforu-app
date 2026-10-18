<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerUpload;
use App\Models\Order;
use Illuminate\Http\Request;
use ZipArchive;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::withCount('items')->latest();
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($p = $request->query('payment_status')) {
            $q->where('payment_status', $p);
        }
        if ($t = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")
                ->orWhere('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"));
        }

        return view('admin.orders.index', ['orders' => $q->paginate(30)->withQueryString()]);
    }

    public function show(Order $order)
    {
        $order->load('items.uploads', 'items.product', 'user');

        return view('admin.orders.show', compact('order'));
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Order::STATUSES)),
            'payment_status' => 'required|in:unpaid,paid,failed,refunded',
            'tracking_number' => 'nullable|string|max:100',
            'courier' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);
        $order->update($data);

        return back()->with('status', 'Order updated.');
    }

    public function downloadUploads(Order $order)
    {
        $order->load('items.uploads');
        abort_unless(class_exists(ZipArchive::class), 500, 'ZipArchive is not available on this server.');
        $tmp = tempnam(sys_get_temp_dir(), 'mfu');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $count = 0;
        foreach ($order->items->values() as $i => $item) {
            foreach ($item->uploads->values() as $n => $u) {
                /** @var CustomerUpload $u */
                $file = $u->absolutePath();
                if (is_file($file)) {
                    $ext = pathinfo($u->path, PATHINFO_EXTENSION) ?: 'jpg';
                    $zip->addFile($file, $order->number.'-'.($i + 1).'-'.($n + 1).'.'.$ext);
                    $count++;
                }
            }
        }
        $zip->close();
        if (! $count) {
            @unlink($tmp);

            return back()->with('error', 'This order has no uploaded photos.');
        }

        return response()->download($tmp, $order->number.'-photos.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
