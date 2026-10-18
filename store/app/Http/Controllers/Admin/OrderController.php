<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerUpload;
use App\Models\Order;
use App\Services\Delhivery;
use App\Services\Shipping;
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

        return view('admin.orders.index', [
            'orders' => $q->paginate(30)->withQueryString(),
            'delhivery' => Delhivery::enabled(),
            // Booked with Delhivery but not yet picked up: what the next pickup should collect.
            'awaitingPickup' => Order::whereNotNull('tracking_number')->where('courier', 'Delhivery')
                ->whereIn('status', ['confirmed', 'in_production'])->count(),
        ]);
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
        $wasShipped = in_array($order->status, ['shipped', 'delivered'], true);
        if ($data['status'] === 'shipped' && ! $order->shipped_at) {
            $data['shipped_at'] = now();
        }
        if ($data['status'] === 'delivered' && ! $order->delivered_at) {
            $data['delivered_at'] = now();
        }
        $order->update($data);
        if (! $wasShipped && $order->status === 'shipped' && $order->tracking_number && $request->boolean('notify', true)) {
            app(Shipping::class)->notifyShipped($order);
        }

        return back()->with('status', 'Order updated.');
    }

    /** Book (or retry) the Delhivery shipment. */
    public function ship(Order $order, Shipping $shipping)
    {
        if ($order->tracking_number) {
            return back()->with('error', 'This order already has tracking number '.$order->tracking_number.'.');
        }
        abort_unless(Delhivery::enabled(), 400, 'Delhivery is not set up. Add the API token in Settings.');
        if (in_array($order->status, ['pending', 'cancelled'], true)) {
            return back()->with('error', 'Only confirmed orders can be shipped.');
        }
        $order->update(['weight_grams' => request()->integer('weight_grams') ?: $order->weight_grams]);

        return $shipping->ship($order->fresh())
            ? back()->with('status', 'Delhivery shipment created: AWB '.$order->fresh()->tracking_number.'.')
            : back()->with('error', 'Delhivery: '.$order->fresh()->shipment_error);
    }

    public function track(Order $order, Shipping $shipping)
    {
        try {
            $shipping->sync($order);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Tracking updated: '.($order->fresh()->shipment_status ?: 'no scans yet').'.');
    }

    public function label(Order $order, Delhivery $delhivery)
    {
        abort_unless($order->tracking_number, 404);
        try {
            return redirect()->away($delhivery->labelUrl($order->tracking_number));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancelShipment(Order $order, Delhivery $delhivery)
    {
        abort_unless($order->tracking_number, 404);
        try {
            $delhivery->cancel($order->tracking_number);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $order->update(['shipment_status' => 'Cancelled (AWB '.$order->tracking_number.')', 'tracking_number' => null, 'shipment_scans' => null]);

        return back()->with('status', 'Delhivery shipment cancelled. You can create a new one.');
    }

    public function pickup(Request $request, Delhivery $delhivery)
    {
        $data = $request->validate([
            'pickup_date' => 'required|date|after_or_equal:today',
            'pickup_time' => 'required|date_format:H:i',
            'count' => 'required|integer|min:1|max:500',
        ]);
        try {
            $delhivery->requestPickup($data['pickup_date'], $data['pickup_time'].':00', (int) $data['count']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Delhivery pickup requested for '.$data['pickup_date'].' at '.$data['pickup_time'].'.');
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
