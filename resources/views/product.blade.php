@extends('layouts.app')
@section('body_class', 'page-product')
@section('content')
@php
    $crumbs = [['Home', url('/')]];
    if ($cat = $product->primaryCategory) { foreach ($cat->ancestors() as $a) $crumbs[] = [$a->name, $a->url()]; $crumbs[] = [$cat->name, $cat->url()]; }
    $crumbs[] = [$product->name, $product->url()];
    $off = $product->discountPercent();
    $variationAttrs = $product->productAttributes->where('is_variation', true)->values();
    $infoAttrs = $product->productAttributes->where('is_variation', false)->values();
    $ready = now()->addWeekdays(max(1, $product->production_days));
    $deliverFrom = $ready->copy()->addDays(2); $deliverTo = $ready->copy()->addDays(6);
@endphp
<div class="wrap">
    @include('partials.breadcrumbs', ['crumbs' => $crumbs])

    <div class="pdp">
        @php $many = $product->images->count() > 1; @endphp
        <div class="gallery @if($many) has-thumbs @endif" data-gallery>
            <div class="gallery-stage">
                <div class="gallery-main" data-gallery-track>
                    @forelse($product->images as $img)
                        <figure class="slide" data-full="{{ $img->url() }}" data-zoom>
                            @include('partials.picture', ['path' => $img->path, 'alt' => $img->alt ?: $product->name, 'lazy' => !$loop->first, 'w' => 900, 'h' => 900, 'sizes' => '(max-width: 900px) 100vw, 560px'])
                        </figure>
                    @empty
                        <figure class="slide"><img src="{{ asset('images/placeholder.svg') }}" alt="{{ $product->name }}" width="900" height="900"></figure>
                    @endforelse
                </div>
                @if($many)
                    <button type="button" class="gal-arrow prev" data-gal-step="-1" aria-label="Previous image"><svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg></button>
                    <button type="button" class="gal-arrow next" data-gal-step="1" aria-label="Next image"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></button>
                    <span class="gallery-count" data-gallery-count>1 / {{ $product->images->count() }}</span>
                @endif
                @if($product->images->isNotEmpty())<button type="button" class="gal-zoom" data-gal-open aria-label="View full screen"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M11 8v6M8 11h6"/></svg><span class="hide-mobile">Click to zoom</span></button>@endif
                @if($off)<span class="tag sale big">{{ $off }}% off</span>@endif
            </div>
            @if($many)
                <div class="gallery-thumbs" role="tablist" aria-label="Product images">
                    @foreach($product->images as $img)
                        <button type="button" data-goto="{{ $loop->index }}" aria-label="Show image {{ $loop->iteration }}"><img src="{{ $img->url() }}" alt="" loading="lazy" width="80" height="80"></button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="pdp-info">
            @if($product->primaryCategory)<a href="{{ $product->primaryCategory->url() }}" class="pdp-cat">{{ $product->primaryCategory->name }}</a>@endif
            <h1>{{ $product->name }}</h1>
            <div class="pdp-meta">
                @if($product->rating_count)
                    <a href="#reviews" class="stars-line"><span class="stars-sm"><span style="--r: {{ $product->rating_avg }}"></span></span> <b>{{ number_format($product->rating_avg, 1) }}</b> · {{ $product->rating_count }} {{ \Illuminate\Support\Str::plural('review', $product->rating_count) }}</a>
                @else
                    <a href="#reviews" class="stars-line muted">☆ Be the first to review</a>
                @endif
                @if($product->sales_count >= 5)<span class="sold">🔥 {{ number_format($product->sales_count) }}+ sold</span>@endif
            </div>

            <div class="pdp-price" data-price-box>
                <b data-price>{{ money($product->currentPrice()) }}</b>
                <span class="each" data-each hidden></span>
                <s data-regular @if(!$off) hidden @endif>{{ money($product->regularPrice()) }}</s>
                <span class="save" data-save @if(!$off) hidden @endif>You save {{ money($product->regularPrice() - $product->currentPrice()) }} ({{ $off }}% off)</span>
                <small class="muted">Inclusive of all taxes @if((float) setting('free_shipping_over') > 0)· Free delivery over {{ money(setting('free_shipping_over')) }}@endif</small>
            </div>
            @php $left = \App\Services\Stock::available($product); $low = \App\Services\Stock::lowThreshold(); @endphp
            @php $showLeft = $left !== null && $left > 0 && $left <= $low; @endphp
            <p class="stock-note" data-stock-note data-low="{{ $low }}" @unless($showLeft) hidden @endunless>@if($showLeft) 🔥 Only {{ $left }} left — order soon @endif</p>

            <ul class="pdp-chips">
                @if($product->needsUpload())<li>@include('partials.icon', ['i' => 'camera']) {{ $product->uploadCount() > 1 ? 'Add '.$product->uploadCount().' photos' : 'Add your photo' }}</li>@endif
                <li>@include('partials.icon', ['i' => 'hand']) Made in {{ $product->production_days }} {{ \Illuminate\Support\Str::plural('day', $product->production_days) }}</li>
                @if(setting('cod_enabled'))<li>@include('partials.icon', ['i' => 'cash']) Cash on delivery</li>@endif
            </ul>

            @if($product->short_description)<div class="prose short">{!! $product->short_description !!}</div>@endif

            <form action="{{ route('cart.add', $product) }}" method="post" class="buy-form" data-buy-form data-upload-url="{{ route('upload.store') }}" data-variants='@json($variants)'>
                @csrf
                @if($product->type === 'variable')
                    <input type="hidden" name="variant_id" value="" data-variant-input>
                    @foreach($variationAttrs as $attr)
                        <fieldset class="opt-group" data-attr="{{ $attr->name }}">
                            <legend>{{ $attr->name }}: <b data-attr-chosen></b></legend>
                            <div class="opts">
                                @foreach($attr->values as $val)
                                    <label class="opt"><input type="radio" name="attr[{{ $attr->name }}]" value="{{ $val }}" required><span>{{ $val }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    @error('variant_id')<p class="err">{{ $message }}</p>@enderror
                @endif

                @if($product->customizationFields->isNotEmpty())
                <div class="personalise" data-item-block="0">
                    <div class="pz-head"><span class="pz-step">@include('partials.icon', ['i' => 'sparkle'])</span><div><h2 class="h-sm">Personalise it <span class="item-tag" data-item-tag hidden>Item 1</span></h2><small class="muted">Your photos are private and used only to make your gift.</small></div></div>
                    @include('partials.personalise-fields', ['prefix' => 'custom', 'suffix' => ''])
                </div>
                {{-- Ordering more than one: same personalisation for all, or a box per item. --}}
                <div class="each-choice" data-each-choice hidden>
                    <label><input type="radio" name="custom_each" value="0" checked> <span><b>Same for all <span data-qty-label></span></b><small>One design, repeated</small></span></label>
                    <label><input type="radio" name="custom_each" value="1"> <span><b>Different for each</b><small>Own photos &amp; text per item</small></span></label>
                </div>
                <div data-item-blocks></div>
                <template data-item-template>
                    <div class="personalise extra" data-item-block="__N__">
                        <h2 class="h-sm">Personalise it <span class="item-tag">Item __NUM__</span></h2>
                        @include('partials.personalise-fields', ['prefix' => 'items[__N__][custom]', 'suffix' => '-__N__'])
                    </div>
                </template>
                @endif

                <div class="buy-row">
                    <div class="qty" data-qty>
                        <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                        <input type="number" name="qty" value="1" min="1" max="99" inputmode="numeric" aria-label="Quantity">
                        <button type="button" data-step="1" aria-label="Increase quantity">+</button>
                    </div>
                    @php $stock = $product->type === 'variable' ? $product->variants->contains('in_stock', true) : $product->in_stock; @endphp
                    <button class="btn primary grow" data-add @disabled(!$stock)>{{ $stock ? 'Add to cart' : 'Out of stock' }}</button>
                </div>
                <button class="btn dark block" name="buy_now" value="1" @disabled(!$stock)>Buy now</button>
                @if($shipNudge)<p class="ship-nudge {{ $shipNudge['ok'] ? 'ok' : '' }}">@include('partials.icon', ['i' => 'truck']) {{ $shipNudge['text'] }}</p>@endif
                <p class="err" data-form-error hidden></p>
                @if(config('store.whatsapp'))
                    <a class="help-line" href="https://wa.me/{{ config('store.whatsapp') }}?text={{ rawurlencode('Hi! I need help personalising: '.$product->name.' '.$product->url()) }}" target="_blank" rel="noopener">@include('partials.icon', ['i' => 'whatsapp']) Need help with your design? <b>Chat with us</b></a>
                @endif
            </form>

            <div class="delivery-card">
                @if($festNote = \App\Support\Campaign::productNote($product))<div class="camp-note {{ $festNote['ok'] ? 'ok' : 'late' }}">🪔 {{ $festNote['text'] }}</div>@endif
                <div class="dc-eta">@include('partials.icon', ['i' => 'truck'])<span>Get it by <b>{{ $deliverTo->format('D, j M') }}</b><small>Made in {{ $product->production_days }} {{ \Illuminate\Support\Str::plural('day', $product->production_days) }}, then shipped with tracking</small></span></div>
                <form class="pin-check" data-pin-check="{{ route('delivery.check') }}" data-days="{{ $product->production_days }}" onsubmit="return false">
                    <label for="pinbox" class="sr">Check delivery to your PIN code</label>
                    <div><input id="pinbox" inputmode="numeric" maxlength="6" placeholder="Your PIN code" autocomplete="postal-code"><button type="submit" class="btn small">Check</button></div>
                    <p class="small" data-pin-result aria-live="polite"></p>
                </form>
            </div>

            @if($together->isNotEmpty())
                <div class="together">
                    <p class="together-h">Often bought together</p>
                    @foreach($together as $t)
                        <a href="{{ $t->url() }}" class="together-item">
                            <img src="{{ $t->thumbUrl() }}" alt="" width="56" height="56" loading="lazy">
                            <span>{{ \Illuminate\Support\Str::limit($t->name, 60) }}<b>{{ money($t->currentPrice()) }}</b></span>
                        </a>
                    @endforeach
                </div>
            @endif

            <ul class="trust-tiles">
                <li>@include('partials.icon', ['i' => 'shield'])<b>Secure payment</b><small>UPI, cards{{ setting('cod_enabled') ? ', COD' : '' }}</small></li>
                <li>@include('partials.icon', ['i' => 'return'])<b>Damage-free</b><small>Free remake if broken</small></li>
                <li>@include('partials.icon', ['i' => 'hand'])<b>Handmade</b><small>In {{ config('store.city') }}</small></li>
            </ul>

            <div class="share-row">
                @auth
                    <form method="post" action="{{ route('wishlist.toggle', $product) }}" data-wishlist>@csrf<button class="link-btn">♡ Save to wishlist</button></form>
                @endauth
                <a class="link-btn" href="https://wa.me/?text={{ rawurlencode($product->name.' '.$product->url()) }}" rel="noopener" target="_blank">Share on WhatsApp</a>
            </div>
        </div>
    </div>

    <section class="pdp-details">
        <details open>
            <summary><h2>Description</h2></summary>
            <div class="prose">{!! $product->description ?: e($product->name) !!}</div>
        </details>
        @if($infoAttrs->isNotEmpty() || $product->sku || $product->weight_kg || $product->length_cm || $product->packaging)
        <details>
            <summary><h2>Specifications</h2></summary>
            <table class="specs">
                @foreach($infoAttrs as $a)<tr><th>{{ $a->name }}</th><td>{{ implode(', ', $a->values) }}</td></tr>@endforeach
                @if($product->sku)<tr><th>SKU</th><td>{{ $product->sku }}</td></tr>@endif
                @if($product->weight_kg)<tr><th>Weight</th><td>{{ \App\Services\Parcel::label(\App\Services\Parcel::itemGrams($product)) }}</td></tr>@endif
                @if($product->length_cm && $product->width_cm && $product->height_cm)<tr><th>Packed size</th><td>{{ collect([$product->length_cm, $product->width_cm, $product->height_cm])->map(fn ($v) => rtrim(rtrim(number_format($v, 1), '0'), '.'))->implode(' × ') }} cm</td></tr>@endif
                @if($product->packaging)<tr><th>Ships in</th><td>{{ $product->packaging === 'box' ? 'A sturdy carton box' : 'A padded courier flyer' }}</td></tr>@endif
                <tr><th>Brand</th><td>{{ $product->brand }}</td></tr>
            </table>
        </details>
        @endif
        <details>
            <summary><h2>How personalisation works</h2></summary>
            <ol class="mini-steps">
                <li><b>1</b><span><strong>Upload &amp; type</strong> Add your {{ $product->needsUpload() ? 'photos' : 'details' }} and any names or message above.</span></li>
                <li><b>2</b><span><strong>We make it</strong> Printed and finished by hand in {{ $product->production_days }} {{ \Illuminate\Support\Str::plural('day', $product->production_days) }}, checked before packing.</span></li>
                <li><b>3</b><span><strong>Delivered</strong> Shipped with Delhivery tracking to your door, anywhere in India.</span></li>
            </ol>
        </details>
        <details>
            <summary><h2>Delivery &amp; returns</h2></summary>
            <div class="prose">
                <ul>
                    <li>Made to order in {{ $product->production_days }} {{ \Illuminate\Support\Str::plural('day', $product->production_days) }}, then delivered in 2–7 days with tracking.</li>
                    @if((float) setting('free_shipping_over') > 0)<li>Free delivery on orders over {{ money(setting('free_shipping_over')) }}; otherwise {{ money(setting('shipping_fee')) }}.</li>@endif
                    @if(setting('cod_enabled'))<li>Cash on delivery available on most PIN codes.</li>@endif
                    <li>Personalised items can’t be returned for change of mind, but if it arrives damaged or wrong we replace or refund it — tell us within 48 hours.</li>
                </ul>
                <p><a href="{{ url('legal/shipping-delivery-policy') }}/">Shipping policy</a> · <a href="{{ url('legal/refund-cancellation-policy') }}/">Refund policy</a></p>
            </div>
        </details>
        @if($product->faqs)
        <details open>
            <summary><h2>Frequently asked questions</h2></summary>
            <div class="faqs">
                @foreach($product->faqs as $faq)
                    @if(!empty($faq['q']))<details class="faq"><summary>{{ $faq['q'] }}</summary><p>{{ $faq['a'] }}</p></details>@endif
                @endforeach
            </div>
        </details>
        @endif
    </section>

    <section class="reviews" id="reviews">
        <div class="section-head"><h2>Customer reviews</h2></div>
        <div class="rv-summary">
            @if($product->rating_count)
                <div class="rv-score"><b>{{ number_format($product->rating_avg, 1) }}</b><span class="stars-sm"><span style="--r: {{ $product->rating_avg }}"></span></span><small class="muted">{{ $product->rating_count }} {{ \Illuminate\Support\Str::plural('review', $product->rating_count) }}</small></div>
                <div class="rv-bars">
                    @for($i = 5; $i >= 1; $i--)
                        @php $c = $ratingBars[$i] ?? 0; $tot = max(1, array_sum($ratingBars)); @endphp
                        <div><span>{{ $i }}★</span><i><em style="width: {{ round(100 * $c / $tot) }}%"></em></i><small>{{ $c }}</small></div>
                    @endfor
                </div>
            @else
                <div class="rv-empty">@include('partials.icon', ['i' => 'star'])<span><b>No reviews yet</b><small>Bought this? Share a photo of your gift — it helps other shoppers.</small></span></div>
            @endif
        </div>
        @php $reviewPhotos = $product->reviews->flatMap(fn ($r) => $r->photoUrls())->take(12); @endphp
        @if($reviewPhotos->isNotEmpty())
            <div class="review-photos" aria-label="Customer photos">
                @foreach($reviewPhotos as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="Customer photo of {{ $product->name }}" loading="lazy" width="120" height="120"></a>@endforeach
            </div>
        @endif
        @foreach($product->reviews->take(10) as $r)
            <article class="review">
                <div class="stars-sm"><span style="--r: {{ $r->rating }}"></span></div>
                @if($r->body)<p>{{ $r->body }}</p>@endif
                @if($r->photos)
                    <div class="review-photos small">@foreach($r->photoUrls() as $src)<a href="{{ $src }}" target="_blank" rel="noopener"><img src="{{ $src }}" alt="Photo from {{ $r->name }}" loading="lazy" width="80" height="80"></a>@endforeach</div>
                @endif
                <small class="muted">{{ $r->name }}@if($r->is_verified) · <span class="verified">✓ Verified buyer</span>@endif · {{ $r->created_at->format('j M Y') }}</small>
            </article>
        @endforeach
        <details class="review-form">
            <summary class="btn ghost">Write a review</summary>
            <form method="post" action="{{ route('product.review', $product) }}" enctype="multipart/form-data">
                @csrf
                <div class="rate-input" role="radiogroup" aria-label="Rating">
                    @for($i = 5; $i >= 1; $i--)<input type="radio" id="r{{ $i }}" name="rating" value="{{ $i }}" required><label for="r{{ $i }}" title="{{ $i }} stars">★</label>@endfor
                </div>
                <input type="text" name="name" placeholder="Your name" required maxlength="80" value="{{ auth()->user()?->name }}">
                <textarea name="body" rows="3" placeholder="How did you like it?" maxlength="2000"></textarea>
                <label class="small">Add up to 3 photos of your gift (optional)<input type="file" name="photos[]" accept="image/*" multiple></label>
                <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
                <button class="btn primary">Submit review</button>
            </form>
        </details>
    </section>

    @if($related->isNotEmpty())
    <section class="section">
        <div class="section-head"><h2>You may also like</h2>@if($product->primaryCategory)<a href="{{ $product->primaryCategory->url() }}">More {{ $product->primaryCategory->name }}</a>@endif</div>
        @include('partials.product-group', ['list' => $related, 'layout' => $related->count() >= 6 ? 'grid6' : 'grid4'])
    </section>
    @endif
</div>

{{-- Phone: price + add-to-cart stays reachable above the tab bar --}}
<div class="sticky-buy only-mobile" data-sticky-buy>
    <img src="{{ $product->thumbUrl() }}" alt="" width="44" height="44" class="sb-thumb">
    <div class="sb-text"><small class="muted">{{ \Illuminate\Support\Str::limit($product->name, 28) }}</small><b data-price>{{ money($product->currentPrice()) }}</b></div>
    <button class="btn primary" data-scroll-buy>{{ $product->customizationFields->isNotEmpty() ? 'Personalise' : 'Add to cart' }}</button>
</div>
@endsection
