<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CustomerUpload;
use App\Models\CustomizationField;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Session cart. The session only holds ids, quantities and what the customer typed or uploaded;
 * every price is read from the catalogue each time, so a price edited in admin applies at once
 * and nothing the browser sends can change what an item costs.
 */
class Cart
{
    private const KEY = 'cart.lines';

    private const COUPON = 'cart.coupon';

    /**
     * Validate a customer's selections against the product and add them as a cart line.
     *
     * @param  array<int|string, mixed>  $custom  field id => text value | list of upload tokens
     */
    public function add(Product $product, ?int $variantId, int $qty, array $custom): string
    {
        $product->loadMissing('variants', 'customizationFields');
        $variant = null;
        if ($product->type === 'variable') {
            $variant = $product->variants->firstWhere('id', $variantId);
            if (! $variant) {
                throw ValidationException::withMessages(['variant_id' => 'Please choose an option.']);
            }
            if (! $variant->in_stock) {
                throw ValidationException::withMessages(['variant_id' => 'That option is out of stock.']);
            }
        } elseif (! $product->in_stock) {
            throw ValidationException::withMessages(['qty' => 'This product is out of stock.']);
        }

        $available = Stock::available($product, $variant);
        if ($available !== null) {
            $inCart = collect($this->raw())->filter(fn ($l) => $l['product_id'] === $product->id && $l['variant_id'] === $variant?->id)->sum('qty');
            if ($inCart + $qty > $available) {
                throw ValidationException::withMessages(['qty' => $available - $inCart > 0
                    ? 'Only '.$available.' left'.($inCart ? ' (you have '.$inCart.' in your cart)' : '').'.'
                    : 'Sorry, there are no more of these in stock.']);
            }
        }

        $clean = $this->validateCustomization($product, $custom);

        $lines = $this->raw();
        $id = substr(sha1($product->id.'|'.($variant?->id).'|'.json_encode($clean)), 0, 16);
        if (isset($lines[$id])) {
            $lines[$id]['qty'] = min(99, $lines[$id]['qty'] + $qty);
        } else {
            $lines[$id] = ['product_id' => $product->id, 'variant_id' => $variant?->id, 'qty' => max(1, min(99, $qty)), 'custom' => $clean];
        }
        session([self::KEY => $lines]);

        return $id;
    }

    /**
     * Several of one product, each personalised differently ("Different for each" on the product page).
     * Every item is checked first, so nothing is added unless all of them are complete.
     *
     * @param  array<int, array<int|string, mixed>>  $customs  one entry per item
     */
    public function addEach(Product $product, ?int $variantId, array $customs): void
    {
        $product->loadMissing('variants', 'customizationFields');
        $errors = [];
        foreach ($customs as $i => $custom) {
            try {
                $this->validateCustomization($product, $custom);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    $errors[$i ? 'items.'.$i.'.'.$key : $key] = array_map(fn ($m) => 'Item '.($i + 1).': '.$m, $messages);
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // Stock for the whole set, so a shortage shows before any item goes in.
        $variant = $product->type === 'variable' ? $product->variants->firstWhere('id', $variantId) : null;
        $available = $variant || $product->type !== 'variable' ? Stock::available($product, $variant) : null;
        if ($available !== null) {
            $inCart = collect($this->raw())->filter(fn ($l) => $l['product_id'] === $product->id && $l['variant_id'] === $variant?->id)->sum('qty');
            if ($inCart + count($customs) > $available) {
                throw ValidationException::withMessages(['qty' => max(0, $available - $inCart) > 0 ? 'Only '.($available - $inCart).' more available.' : 'Sorry, there are no more of these in stock.']);
            }
        }
        foreach ($customs as $custom) {
            $this->add($product, $variantId, 1, $custom);
        }
    }

    /** @return array<int, mixed> */
    public function validateCustomization(Product $product, array $custom): array
    {
        $errors = [];
        $clean = [];
        $sessionId = session()->getId();
        foreach ($product->customizationFields as $field) {
            $value = $custom[$field->id] ?? null;
            $name = 'custom.'.$field->id;
            if ($field->type === 'images') {
                $tokens = array_values(array_filter((array) $value, 'is_string'));
                $owned = CustomerUpload::whereIn('token', $tokens)->where('session_id', $sessionId)->pluck('token')->all();
                $tokens = array_values(array_intersect($tokens, $owned));
                $n = count($tokens);
                if ($field->required && $n < max(1, $field->min_files)) {
                    $errors[$name] = $field->min_files > 1
                        ? "Please upload {$field->min_files} photos for “{$field->label}”."
                        : "Please upload your photo for “{$field->label}”.";
                } elseif ($n > $field->max_files) {
                    $errors[$name] = "“{$field->label}” takes at most {$field->max_files} photos.";
                }
                if ($n) {
                    $clean[$field->id] = $tokens;
                }

                continue;
            }
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                if ($field->required) {
                    $errors[$name] = "Please fill in “{$field->label}”.";
                }

                continue;
            }
            if ($field->max_length && mb_strlen($value) > $field->max_length) {
                $errors[$name] = "“{$field->label}” can be at most {$field->max_length} characters.";
            }
            if (in_array($field->type, ['select', 'choice'], true) && ! collect($field->options)->pluck('label')->contains($value)) {
                $errors[$name] = "Please choose a valid option for “{$field->label}”.";
            }
            if ($field->type === 'color' && ! preg_match('/^#[0-9a-f]{6}$/i', $value)) {
                $errors[$name] = 'Please pick a colour.';
            }
            if ($field->type === 'date' && strtotime($value) === false) {
                $errors[$name] = 'Please enter a valid date.';
            }
            $clean[$field->id] = mb_substr($value, 0, 2000);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    public function update(string $lineId, int $qty): void
    {
        $lines = $this->raw();
        if (isset($lines[$lineId]) && $qty > 0 && ($p = Product::with('variants')->find($lines[$lineId]['product_id']))) {
            $available = Stock::available($p, $lines[$lineId]['variant_id'] ? $p->variants->firstWhere('id', $lines[$lineId]['variant_id']) : null);
            if ($available !== null) {
                $qty = min($qty, max(1, $available));
            }
        }
        if (isset($lines[$lineId])) {
            if ($qty < 1) {
                unset($lines[$lineId]);
            } else {
                $lines[$lineId]['qty'] = min(99, $qty);
            }
            session([self::KEY => $lines]);
        }
    }

    public function remove(string $lineId): void
    {
        $this->update($lineId, 0);
    }

    public function clear(): void
    {
        session()->forget([self::KEY, self::COUPON]);
    }

    public function raw(): array
    {
        return session(self::KEY, []);
    }

    public function count(): int
    {
        return (int) array_sum(array_column($this->raw(), 'qty'));
    }

    /**
     * Cart lines priced from the catalogue. Lines whose product or option has gone are dropped.
     *
     * @return Collection<int, object>
     */
    public function lines(): Collection
    {
        $raw = $this->raw();
        if (! $raw) {
            return collect();
        }
        $products = Product::active()->with(['images', 'customizationFields', 'variants'])
            ->whereIn('id', array_column($raw, 'product_id'))->get()->keyBy('id');
        $tokens = collect($raw)->pluck('custom')->flatten()->filter(fn ($t) => is_string($t) && strlen($t) === 36)->all();
        $uploads = CustomerUpload::whereIn('token', $tokens)->get()->keyBy('token');

        $out = collect();
        foreach ($raw as $id => $line) {
            $product = $products->get($line['product_id']);
            if (! $product) {
                continue;
            }
            /** @var ProductVariant|null $variant */
            $variant = $line['variant_id'] ? $product->variants->firstWhere('id', $line['variant_id']) : null;
            if ($product->type === 'variable' && ! $variant) {
                continue;
            }
            $unit = $variant ? $variant->currentPrice() : (float) ($product->isOnSale() ? $product->sale_price : $product->price);
            $details = [];
            foreach ($product->customizationFields as $field) {
                /** @var CustomizationField $field */
                if (! array_key_exists($field->id, $line['custom'])) {
                    continue;
                }
                $v = $line['custom'][$field->id];
                $extra = (float) $field->extra_price;
                $opt = in_array($field->type, ['select', 'choice'], true) ? collect($field->options)->firstWhere('label', $v) : null;
                $extra += (float) ($opt['price'] ?? 0);
                $unit += $extra;
                $details[] = (object) [
                    'field_id' => $field->id,
                    'label' => $field->label,
                    'type' => $field->type,
                    'value' => $field->type === 'images' ? null : $v,
                    'uploads' => $field->type === 'images' ? collect($v)->map(fn ($t) => $uploads->get($t))->filter()->values() : collect(),
                    'slots' => $field->type === 'images' ? collect($field->options ?? [])->pluck('label')->all() : [],
                    'image' => ! empty($opt['image']) ? media_url($opt['image']) : null,
                ];
            }
            $out->push((object) [
                'id' => $id,
                'product' => $product,
                'variant' => $variant,
                'qty' => $line['qty'],
                'unit' => round($unit, 2),
                'total' => round($unit * $line['qty'], 2),
                'details' => $details,
                'image' => $variant?->image ? media_url($variant->image) : $product->mainImageUrl(),
            ]);
        }

        return $out;
    }

    /**
     * Lines asking for more than is left (stock changed after they were added).
     *
     * @return array<int, string> messages
     */
    public function stockProblems(Collection $lines): array
    {
        $want = [];
        foreach ($lines as $l) {
            $key = $l->product->id.'-'.($l->variant?->id ?? 0);
            $want[$key] = ['line' => $l, 'qty' => ($want[$key]['qty'] ?? 0) + $l->qty];
        }
        $out = [];
        foreach ($want as $w) {
            $available = Stock::available($w['line']->product, $w['line']->variant);
            $out = $available !== null && $w['qty'] > $available
                ? [...$out, $available > 0 ? "Only {$available} of “{$w['line']->product->name}” left — please reduce the quantity." : "“{$w['line']->product->name}” has just sold out — please remove it."]
                : $out;
        }

        return $out;
    }

    public function applyCoupon(string $code): ?string
    {
        $coupon = Coupon::whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();
        if (! $coupon) {
            return 'That coupon code is not valid.';
        }
        $result = $coupon->discountFor($this->subtotal($this->lines()));
        if (is_string($result)) {
            return $result;
        }
        session([self::COUPON => $coupon->code]);

        return null;
    }

    public function removeCoupon(): void
    {
        session()->forget(self::COUPON);
    }

    public function subtotal(Collection $lines): float
    {
        return round((float) $lines->sum('total'), 2);
    }

    /** Cash-on-delivery charge: a fixed fee, a % of the order, or the higher of the two (e.g. 2%, at least ₹40). */
    public static function codCharge(float $orderValue): float
    {
        $fixed = (float) setting('cod_fee');
        $pct = (float) setting('cod_fee_percent');

        return round(max($fixed, $pct > 0 ? $orderValue * $pct / 100 : 0), 2);
    }

    /** @return array{subtotal: float, discount: float, coupon: ?string, shipping: float, cod_fee: float, total: float, free_shipping_gap: float} */
    public function totals(?Collection $lines = null, string $paymentMethod = 'razorpay'): array
    {
        $lines ??= $this->lines();
        $subtotal = $this->subtotal($lines);
        $discount = 0.0;
        $code = session(self::COUPON);
        if ($code && ($coupon = Coupon::where('code', $code)->first())) {
            $d = $coupon->discountFor($subtotal);
            if (is_float($d)) {
                $discount = $d;
            } else {
                $code = null;
                $this->removeCoupon();
            }
        } else {
            $code = null;
        }
        $after = $subtotal - $discount;
        $freeOver = (float) setting('free_shipping_over');
        $shipping = $lines->isEmpty() || $after >= $freeOver ? 0.0 : (float) setting('shipping_fee');
        $cod = $paymentMethod === 'cod' ? self::codCharge($after + $shipping) : 0.0;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon' => $code,
            'shipping' => $shipping,
            'cod_fee' => $cod,
            'total' => round($after + $shipping + $cod, 2),
            'free_shipping_gap' => $lines->isEmpty() ? 0.0 : max(0, round($freeOver - $after, 2)),
        ];
    }
}
