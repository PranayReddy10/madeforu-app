<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Delhivery B2C API: pincode serviceability, create shipment (manifest), tracking,
 * shipping label, cancellation and pickup requests. Auth is "Authorization: Token …".
 */
class Delhivery
{
    public static function enabled(): bool
    {
        return (bool) setting('delhivery.enabled') && (bool) setting('delhivery.token');
    }

    /** The pickup location (warehouse) name exactly as registered with Delhivery. */
    public static function pickupName(): string
    {
        return trim((string) setting('delhivery.pickup_location'));
    }

    /** Delhivery's own wording, plus what to do about the errors shop owners actually meet. */
    public static function explain(string $why): string
    {
        $name = self::pickupName();
        if (stripos($why, 'ClientWarehouse') !== false || (stripos($why, 'warehouse') !== false && stripos($why, 'does not exist') !== false)) {
            return 'the pickup location “'.($name ?: 'not set').'” is not a warehouse in your Delhivery account. '
                .'The name must match exactly — capital and small letters too (“madeforu” and “MADEFORU” are different names to Delhivery): copy it from Delhivery One → Settings → Pickup locations into '
                .'Admin → Settings → Delhivery shipping, or register your address from Admin → Orders → Delhivery. (Delhivery said: '.$why.')';
        }
        if (stripos($why, 'Unauthorized') !== false || stripos($why, 'token') !== false && stripos($why, 'invalid') !== false) {
            return 'the API token was refused. Check it in Admin → Settings → Delhivery shipping, and that Mode (Live/Test) matches the token. (Delhivery said: '.$why.')';
        }
        if (stripos($why, 'non serviceable') !== false || stripos($why, 'pincode') !== false) {
            return 'Delhivery does not deliver to this PIN code (or it is mistyped). (Delhivery said: '.$why.')';
        }

        return $why;
    }

    /**
     * Register the shop's pickup address as a Delhivery warehouse ("client warehouse").
     *
     * @param  array{name: string, phone: string, email?: string, address: string, city: string, state: string, pin: string}  $w
     */
    public function createWarehouse(array $w): void
    {
        $res = $this->http()->asJson()->post($this->base().'/api/backend/clientwarehouse/create/', [
            'name' => $w['name'], 'registered_name' => $w['name'], 'phone' => $w['phone'], 'email' => $w['email'] ?? config('store.email'),
            'address' => $w['address'], 'city' => $w['city'], 'pin' => $w['pin'], 'country' => 'India',
            'return_address' => $w['address'], 'return_city' => $w['city'], 'return_pin' => $w['pin'], 'return_state' => $w['state'], 'return_country' => 'India',
        ]);
        $body = (string) $res->body();
        // Already registered under this name is fine: we just use it.
        if (stripos($body, 'already exists') !== false) {
            return;
        }
        if (! $res->successful() || $res->json('success') === false) {
            throw new RuntimeException('Delhivery could not register the pickup address: '.Str::limit((string) ($res->json('error') ?: $res->json('message') ?: $body), 300));
        }
    }

    private function base(): string
    {
        return setting('delhivery.mode') === 'staging' ? 'https://staging-express.delhivery.com' : 'https://track.delhivery.com';
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Token '.setting('delhivery.token')])->acceptJson()->timeout(25);
    }

    /**
     * Can Delhivery deliver to this PIN, and collect cash there? Cached for a day.
     * Returns null when the check could not be made (API down, not configured): callers then allow the order.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, city: ?string, state: ?string}|null
     */
    public function pincode(string $pin): ?array
    {
        if (! self::enabled() || ! preg_match('/^[1-9]\d{5}$/', $pin)) {
            return null;
        }

        return Cache::remember('delhivery.pin.'.$pin, 86400, function () use ($pin) {
            try {
                $res = $this->http()->get($this->base().'/c/api/pin-codes/json/', ['filter_codes' => $pin]);
            } catch (\Throwable) {
                return null;
            }
            if (! $res->successful()) {
                return null;
            }
            $pc = $res->json('delivery_codes.0.postal_code');
            if (! $pc) {
                return ['serviceable' => false, 'cod' => false, 'prepaid' => false, 'city' => null, 'state' => null];
            }

            return [
                'serviceable' => ($pc['pre_paid'] ?? 'N') === 'Y' || ($pc['cod'] ?? 'N') === 'Y',
                'cod' => ($pc['cod'] ?? 'N') === 'Y' || ($pc['cash'] ?? 'N') === 'Y',
                'prepaid' => ($pc['pre_paid'] ?? 'N') === 'Y',
                'city' => $pc['district'] ?? null,
                'state' => $pc['state_code'] ?? null,
            ];
        });
    }

    /** Create the shipment in Delhivery and return the AWB (waybill). */
    public function createShipment(Order $order): string
    {
        $order->loadMissing('items');
        $cod = $order->payment_method === 'cod' && $order->payment_status !== 'paid';
        $shipment = [
            'name' => Str::limit($order->name, 100, ''),
            'add' => trim($order->address_line1.', '.$order->address_line2, ', '),
            'pin' => $order->pincode,
            'city' => $order->city,
            'state' => $order->state,
            'country' => 'India',
            'phone' => $order->phone,
            'order' => $order->number,
            'payment_mode' => $cod ? 'COD' : 'Prepaid',
            'cod_amount' => $cod ? (string) round((float) $order->total, 2) : '0',
            'total_amount' => (string) round((float) $order->total, 2),
            'products_desc' => Str::limit($order->items->map(fn ($i) => $i->name.' x'.$i->qty)->implode(', '), 240, ''),
            'quantity' => (string) $order->items->sum('qty'),
            'weight' => (string) $order->shippingWeight(),
            'seller_name' => config('store.name'),
            'shipping_mode' => setting('delhivery.shipping_mode') === 'Express' ? 'Express' : 'Surface',
            'waybill' => '',
            'order_date' => $order->created_at->toDateTimeString(),
        ];
        $payload = ['shipments' => [$shipment], 'pickup_location' => ['name' => self::pickupName()]];

        // Delhivery wants form fields: format=json&data=<json>
        $res = $this->http()->asForm()->post($this->base().'/api/cmu/create.json', [
            'format' => 'json',
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $pkg = $res->json('packages.0');
        if (! $res->successful() || ! $pkg || strtolower((string) ($pkg['status'] ?? '')) !== 'success' || empty($pkg['waybill'])) {
            $why = collect((array) ($pkg['remarks'] ?? []))->filter()->implode(' ') ?: ($res->json('rmk') ?: Str::limit($res->body(), 300));
            throw new RuntimeException('Delhivery did not accept the shipment: '.self::explain($why));
        }

        return (string) $pkg['waybill'];
    }

    /**
     * Latest status and scan history for an AWB.
     *
     * @return array{status: ?string, type: ?string, at: ?string, location: ?string, expected: ?string, scans: array<int, array>}
     */
    public function track(string $awb): array
    {
        $res = $this->http()->get($this->base().'/api/v1/packages/json/', ['waybill' => $awb]);
        $s = $res->json('ShipmentData.0.Shipment');
        if (! $res->successful() || ! $s) {
            throw new RuntimeException('Delhivery tracking failed for '.$awb.': '.Str::limit($res->body(), 200));
        }

        return [
            'status' => $s['Status']['Status'] ?? null,
            'type' => $s['Status']['StatusType'] ?? null,     // UD in transit, DL delivered, RT return, CN cancelled, PP pickup pending
            'at' => $s['Status']['StatusDateTime'] ?? null,
            'location' => $s['Status']['StatusLocation'] ?? null,
            'expected' => $s['ExpectedDeliveryDate'] ?? ($s['PromisedDeliveryDate'] ?? null),
            'scans' => collect($s['Scans'] ?? [])->map(fn ($x) => [
                'status' => $x['ScanDetail']['Scan'] ?? '',
                'at' => $x['ScanDetail']['ScanDateTime'] ?? null,
                'location' => $x['ScanDetail']['ScannedLocation'] ?? '',
                'note' => $x['ScanDetail']['Instructions'] ?? '',
            ])->values()->all(),
        ];
    }

    /** Link to the printable shipping label (PDF). */
    public function labelUrl(string $awb): string
    {
        $res = $this->http()->get($this->base().'/api/p/packing_slip', ['wbns' => $awb, 'pdf' => 'true']);
        $url = $res->json('packages.0.pdf_download_link');
        if (! $url) {
            throw new RuntimeException('Delhivery label not available: '.Str::limit($res->body(), 200));
        }

        return $url;
    }

    public function cancel(string $awb): void
    {
        $res = $this->http()->asJson()->post($this->base().'/api/p/edit', ['waybill' => $awb, 'cancellation' => 'true']);
        if (! $res->successful() || $res->json('status') === false) {
            throw new RuntimeException('Delhivery could not cancel '.$awb.': '.Str::limit($res->body(), 200));
        }
    }

    /** Ask Delhivery to collect today's (or a given day's) parcels from the warehouse. */
    public function requestPickup(string $date, string $time, int $count): array
    {
        $res = $this->http()->asJson()->post($this->base().'/fm/request/new/', [
            'pickup_location' => self::pickupName(),
            'pickup_date' => $date,
            'pickup_time' => $time,
            'expected_package_count' => max(1, $count),
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Delhivery pickup request failed: '.self::explain(Str::limit($res->body(), 300)));
        }

        return $res->json() ?? [];
    }
}
