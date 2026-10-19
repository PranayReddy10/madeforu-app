<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerUpload;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\Delhivery;
use App\Services\Invoice;
use App\Services\OrderNotifier;
use App\Services\Shipping;
use App\Services\Stock;
use App\Services\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::withCount('items')->with('items.uploads', 'items.product')->latest();
        if ($request->query('status') === 'attention') {
            $q->where(fn ($w) => $this->needsAttention($w));
        } elseif ($s = $request->query('status')) {
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
            'counts' => Order::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'attention' => Order::where(fn ($w) => $this->needsAttention($w))->count(),
            'lastError' => Order::whereNotNull('shipment_error')->whereNull('tracking_number')->latest('updated_at')->value('shipment_error'),
        ]);
    }

    /** Confirmed orders that still need a person: Delhivery refused them, or they are not booked yet. */
    private function needsAttention($w)
    {
        $w->whereNull('woo_id')->whereIn('status', ['confirmed', 'in_production'])->whereNull('tracking_number')
            ->where(fn ($x) => $x->whereNotNull('shipment_error')->orWhere('created_at', '<', now()->subDay()));
    }

    /** Ticked orders: book them with Delhivery, or move them to the next step together. */
    public function bulk(Request $request, Shipping $shipping)
    {
        $data = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer', 'action' => 'required|in:book,in_production,shipped,delivered,delete']);
        $orders = Order::whereIn('id', $data['ids'])->get();
        if ($data['action'] === 'delete') {
            $gone = $orders->filter(fn ($o) => $o->status === 'cancelled')->each(fn ($o) => self::wipe($o))->count();
            $kept = $orders->count() - $gone;

            return back()->with($gone ? 'status' : 'error', ($gone ? $gone.' cancelled '.Str::plural('order', $gone).' deleted.' : 'Nothing deleted.')
                .($kept ? ' '.$kept.' not cancelled — only cancelled orders can be deleted.' : ''));
        }
        if ($data['action'] === 'book') {
            $ok = $fail = 0;
            foreach ($orders as $o) {
                if ($o->tracking_number || in_array($o->status, ['pending', 'cancelled'], true)) {
                    continue;
                }
                $o->update(['shipment_error' => null]);
                $shipping->ship($o->fresh()) ? $ok++ : $fail++;
            }
            $msg = $ok.' booked with Delhivery'.($fail ? ', '.$fail.' failed — see the red notes' : '').'.';

            return back()->with($fail && ! $ok ? 'error' : 'status', $msg);
        }
        foreach ($orders as $o) {
            if (in_array($o->status, ['cancelled', 'returned'], true)) {
                continue;
            }
            $changes = ['status' => $data['action']];
            if ($data['action'] === 'shipped' && ! $o->shipped_at) {
                $changes['shipped_at'] = now();
            }
            if ($data['action'] === 'delivered') {
                $changes['delivered_at'] = $o->delivered_at ?? now();
                if ($o->payment_method === 'cod') {
                    $changes['payment_status'] = 'paid';   // cash collected on delivery
                }
            }
            $o->update($changes);
        }

        return back()->with('status', $orders->count().' '.Str::plural('order', $orders->count()).' marked '.strtolower(Order::STATUSES[$data['action']]).'.');
    }

    /** Delete a cancelled order for good: its items, the customer's photos and their files. */
    public function destroy(Order $order)
    {
        if ($order->status !== 'cancelled') {
            return back()->with('error', 'Only cancelled orders can be deleted. Set the status to Cancelled first.');
        }
        $number = $order->number;
        self::wipe($order);

        return redirect()->route('admin.orders.index')->with('status', "Order {$number} deleted.");
    }

    private static function wipe(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $uploads = CustomerUpload::whereIn('order_item_id', $order->items()->pluck('id'))->get();
            foreach ($uploads as $u) {
                Storage::disk('local')->delete(array_filter([$u->path, $u->thumb_path]));
                $u->delete();
            }
            $order->delete();   // items, invoice links and WhatsApp log go with it; reviews and carts keep their row
        });
    }

    /** Register the shop's pickup address with Delhivery and use that name from now on. */
    public function delhiveryWarehouse(Request $request, Delhivery $delhivery)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60', 'phone' => 'required|string|max:20', 'address' => 'required|string|max:250',
            'city' => 'required|string|max:60', 'state' => 'required|string|max:60', 'pin' => 'required|digits:6',
        ]);
        try {
            $delhivery->createWarehouse($data);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        Setting::put(['delhivery.pickup_location' => trim($data['name']), 'delhivery.pickup_pincode' => $data['pin']]);

        return back()->with('status', 'Pickup address “'.$data['name'].'” registered with Delhivery and saved in Settings. Tick the failed orders and press “Book with Delhivery”.');
    }

    public function show(Order $order)
    {
        $order->load('items.uploads', 'items.product', 'user');

        return view('admin.orders.show', ['order' => $order, 'sheet' => self::production($order)]);
    }

    public function update(Request $request, Order $order)
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => preg_replace('/[^\d+]/', '', (string) $request->input('phone'))]);
        }
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Order::STATUSES)),
            'payment_status' => 'required|in:unpaid,paid,failed,refunded',
            'tracking_number' => 'nullable|string|max:100',
            'courier' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            // Optional: fix the customer's details (Edit on the Ship-to card) before booking the courier.
            'name' => 'sometimes|required|string|max:120',
            'phone' => ['sometimes', 'required', 'regex:/^(\+?91)?[6-9]\d{9}$/'],
            'address_line1' => 'sometimes|required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'sometimes|required|string|max:100',
            'state' => 'sometimes|required|string|max:100',
            'pincode' => 'sometimes|required|digits:6',
        ]);
        if (isset($data['phone'])) {
            $data['phone'] = substr(preg_replace('/\D/', '', $data['phone']), -10);
        }
        $wasShipped = in_array($order->status, ['shipped', 'delivered'], true);
        $previous = $order->status;
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
        if ($order->wasChanged('status') && $order->status === 'delivered' && $request->boolean('notify', true)) {
            app(OrderNotifier::class)->delivered($order);
        }
        if (in_array($order->status, ['confirmed', 'in_production', 'shipped', 'delivered'], true)) {
            Invoice::assign($order);
        }
        // Stock follows the order: back on the shelf when cancelled or returned, out again if reopened.
        $stock = app(Stock::class);
        if ($previous !== $order->status && in_array($order->status, ['cancelled', 'returned'], true)) {
            $stock->restore($order, $order->status);
        } elseif (in_array($previous, ['cancelled', 'returned', 'pending'], true) && in_array($order->status, ['confirmed', 'in_production', 'shipped', 'delivered'], true)) {
            $stock->reduce($order);
        }

        return back()->with('status', 'Order updated.');
    }

    public function invoice(Order $order)
    {
        return Invoice::pdf($order)->stream(Invoice::filename($order));
    }

    /** Send an order step's WhatsApp message again (or for the first time). */
    public function whatsapp(Request $request, Order $order, OrderNotifier $notifier)
    {
        $event = $request->validate(['event' => 'required|in:confirmed,shipped,delivered'])['event'];
        abort_unless(WhatsApp::enabled(), 400, 'WhatsApp API is not set up.');
        NotificationLog::where('order_id', $order->id)->where('channel', 'whatsapp')->where('event', $event)->where('status', 'sent')->delete();
        $order->load('items');
        $notifier->{$event}($order);
        $last = NotificationLog::where('order_id', $order->id)->where('channel', 'whatsapp')->where('event', $event)->latest('id')->first();

        return $last?->status === 'sent'
            ? back()->with('status', 'WhatsApp "'.$event.'" message sent.')
            : back()->with('error', 'WhatsApp: '.($last?->error ?: 'not sent (check the template name and the customer number).'));
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

    /**
     * What to make, item by item: the photos (with their slot labels) and the text to print.
     *
     * @return array<int, array{item: OrderItem, n: int, parts: array}>
     */
    public static function production(Order $order): array
    {
        $order->loadMissing('items.uploads', 'items.product');
        $out = [];
        foreach ($order->items->values() as $i => $item) {
            $parts = [];
            foreach ($item->customization ?? [] as $c) {
                if (($c['type'] ?? '') === 'images') {
                    $photos = [];
                    foreach (array_values($c['files'] ?? []) as $k => $token) {
                        $u = $item->uploads->firstWhere('token', $token);
                        if ($u) {
                            $slot = $c['slots'][$k] ?? (count($c['files']) > 1 ? 'Photo '.($k + 1) : 'Photo');
                            $photos[] = ['upload' => $u, 'slot' => $slot, 'filename' => self::photoName($order, $i + 1, $slot, $k + 1, $u)];
                        }
                    }
                    $parts[] = ['type' => 'images', 'label' => $c['label'], 'photos' => $photos];
                } else {
                    $parts[] = ['type' => $c['type'] ?? 'text', 'label' => $c['label'], 'value' => (string) ($c['value'] ?? ''), 'image' => $c['image'] ?? null];
                }
            }
            $out[] = ['item' => $item, 'n' => $i + 1, 'parts' => $parts];
        }

        return $out;
    }

    /** MFU261018001-item1-his-photo.jpg */
    private static function photoName(Order $order, int $item, string $slot, int $k, CustomerUpload $u): string
    {
        $ext = strtolower(pathinfo($u->path, PATHINFO_EXTENSION) ?: 'jpg');

        return $order->number.'-item'.$item.'-'.(Str::slug($slot) ?: 'photo-'.$k).'.'.$ext;
    }

    /** Printable sheet for the workshop. */
    public function productionSheet(Order $order)
    {
        return view('admin.orders.production', ['order' => $order, 'sheet' => self::production($order)]);
    }

    /** One original photo, full resolution, with a name that says where it goes. */
    public function downloadUpload(Order $order, CustomerUpload $upload)
    {
        foreach (self::production($order) as $row) {
            foreach ($row['parts'] as $part) {
                foreach ($part['photos'] ?? [] as $p) {
                    if ($p['upload']->is($upload)) {
                        abort_unless(is_file($upload->absolutePath()), 404, 'The photo file is missing on the server.');

                        return response()->download($upload->absolutePath(), $p['filename']);
                    }
                }
            }
        }
        abort(404);
    }

    public function downloadUploads(Order $order)
    {
        $order->load('items.uploads');
        abort_unless(class_exists(ZipArchive::class), 500, 'ZipArchive is not available on this server.');
        $tmp = tempnam(sys_get_temp_dir(), 'mfu');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $count = 0;
        $notes = ['Order '.$order->number.' — '.$order->name.' — '.$order->created_at->format('d M Y'), ''];
        foreach (self::production($order) as $row) {
            $item = $row['item'];
            $folder = 'item'.$row['n'].'-'.Str::slug(Str::limit($item->name, 40, '')).'/';
            $notes[] = 'Item '.$row['n'].': '.$item->name.' × '.$item->qty.($item->options ? ' ('.collect($item->options)->map(fn ($v, $k) => "$k: $v")->implode(', ').')' : '');
            foreach ($row['parts'] as $part) {
                if ($part['type'] === 'images') {
                    foreach ($part['photos'] as $p) {
                        if (is_file($p['upload']->absolutePath())) {
                            $zip->addFile($p['upload']->absolutePath(), $folder.$p['filename']);
                            $notes[] = '  '.$p['slot'].': '.$p['filename'];
                            $count++;
                        }
                    }
                } elseif ($part['value'] !== '') {
                    $notes[] = '  '.$part['label'].': '.$part['value'];
                }
            }
            $notes[] = '';
        }
        $zip->addFromString('order-details.txt', implode("\r\n", $notes));
        $zip->close();
        if (! $count) {
            @unlink($tmp);

            return back()->with('error', 'This order has no uploaded photos.');
        }

        return response()->download($tmp, $order->number.'-photos.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
