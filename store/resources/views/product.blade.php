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
        <div class="gallery" data-gallery>
            <div class="gallery-main" data-gallery-track>
                @forelse($product->images as $img)
                    <figure class="slide">
                        @include('partials.picture', ['path' => $img->path, 'alt' => $img->alt ?: $product->name, 'lazy' => !$loop->first, 'w' => 900, 'h' => 900, 'sizes' => '(max-width: 900px) 100vw, 560px'])
                    </figure>
                @empty
                    <figure class="slide"><img src="{{ asset('images/placeholder.svg') }}" alt="{{ $product->name }}" width="900" height="900"></figure>
                @endforelse
            </div>
            @if($product->images->count() > 1)
                <div class="gallery-dots only-mobile">@foreach($product->images as $img)<span></span>@endforeach</div>
                <div class="gallery-thumbs hide-mobile">
                    @foreach($product->images as $img)
                        <button type="button" data-goto="{{ $loop->index }}" aria-label="Show image {{ $loop->iteration }}"><img src="{{ $img->url() }}" alt="" loading="lazy" width="80" height="80"></button>
                    @endforeach
                </div>
            @endif
            @if($off)<span class="tag sale big">{{ $off }}% off</span>@endif
        </div>

        <div class="pdp-info">
            @if($product->primaryCategory)<a href="{{ $product->primaryCategory->url() }}" class="pdp-cat">{{ $product->primaryCategory->name }}</a>@endif
            <h1>{{ $product->name }}</h1>
            @if($product->rating_count)
                <a href="#reviews" class="stars-line"><span class="stars-sm"><span style="--r: {{ $product->rating_avg }}"></span></span> {{ number_format($product->rating_avg, 1) }} · {{ $product->rating_count }} {{ \Illuminate\Support\Str::plural('review', $product->rating_count) }}</a>
            @endif

            <div class="pdp-price" data-price-box>
                <b data-price>{{ money($product->currentPrice()) }}</b>
                <s data-regular @if(!$off) hidden @endif>{{ money($product->regularPrice()) }}</s>
                <span class="save" data-save @if(!$off) hidden @endif>You save {{ money($product->regularPrice() - $product->currentPrice()) }} ({{ $off }}% off)</span>
                <small class="muted">Inclusive of all taxes @if((float) setting('free_shipping_over') > 0)· Free delivery over {{ money(setting('free_shipping_over')) }}@endif</small>
            </div>
            @php $left = \App\Services\Stock::available($product); $low = \App\Services\Stock::lowThreshold(); @endphp
            @php $showLeft = $left !== null && $left > 0 && $left <= $low; @endphp
            <p class="stock-note" data-stock-note data-low="{{ $low }}" @unless($showLeft) hidden @endunless>@if($showLeft) 🔥 Only {{ $left }} left — order soon @endif</p>

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
                <div class="personalise">
                    <h2 class="h-sm">Personalise it</h2>
                    @foreach($product->customizationFields as $f)
                        <div class="field" data-field="{{ $f->id }}">
                            @if($f->type === 'images')
                                <div class="upload" data-upload data-min="{{ $f->min_files }}" data-max="{{ $f->max_files }}" data-name="custom[{{ $f->id }}][]" data-required="{{ $f->required ? 1 : 0 }}">
                                    <div class="upload-head">
                                        <label>{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                                        <span class="upload-count"><b data-count>0</b> / {{ $f->max_files }}</span>
                                    </div>
                                    @if($f->help)<p class="help">{{ $f->help }}</p>@endif
                                    <div class="upload-slots">
                                        @for($i = 0; $i < $f->max_files; $i++)
                                            <label class="slot" data-slot>
                                                <input type="file" accept="image/*,.heic,.heif" @if($f->max_files > 1) multiple @endif hidden>
                                                <span class="slot-empty"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><small>{{ $f->options[$i]['label'] ?? ($f->max_files > 1 ? 'Photo '.($i + 1) : 'Add photo') }}</small></span>
                                            </label>
                                        @endfor
                                    </div>
                                </div>
                            @elseif($f->type === 'text')
                                <label for="cf{{ $f->id }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                                <input id="cf{{ $f->id }}" type="text" name="custom[{{ $f->id }}]" value="{{ old('custom.'.$f->id) }}" @if($f->max_length) maxlength="{{ $f->max_length }}" @endif @required($f->required) data-count-chars>
                            @elseif($f->type === 'textarea')
                                <label for="cf{{ $f->id }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                                <textarea id="cf{{ $f->id }}" name="custom[{{ $f->id }}]" rows="3" @if($f->max_length) maxlength="{{ $f->max_length }}" @endif @required($f->required) data-count-chars>{{ old('custom.'.$f->id) }}</textarea>
                            @elseif($f->type === 'select')
                                <label for="cf{{ $f->id }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                                <select id="cf{{ $f->id }}" name="custom[{{ $f->id }}]" @required($f->required) data-extra-select>
                                    <option value="">Choose…</option>
                                    @foreach($f->options ?? [] as $o)
                                        <option value="{{ $o['label'] }}" data-price="{{ (float) ($o['price'] ?? 0) }}">{{ $o['label'] }}@if(!empty($o['price']) && $o['price'] > 0) (+{{ money($o['price']) }})@endif</option>
                                    @endforeach
                                </select>
                            @elseif($f->type === 'color')
                                <label for="cf{{ $f->id }}">{{ $f->label }}</label>
                                <input id="cf{{ $f->id }}" type="color" name="custom[{{ $f->id }}]" value="{{ old('custom.'.$f->id, '#f04577') }}">
                            @elseif($f->type === 'date')
                                <label for="cf{{ $f->id }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                                <input id="cf{{ $f->id }}" type="date" name="custom[{{ $f->id }}]" value="{{ old('custom.'.$f->id) }}" @required($f->required)>
                            @endif
                            @if($f->extra_price > 0)<p class="help">+{{ money($f->extra_price) }}</p>@endif
                            @error('custom.'.$f->id)<p class="err">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
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
                <p class="err" data-form-error hidden></p>
            </form>

            <form class="pin-check" data-pin-check="{{ route('delivery.check') }}" data-days="{{ $product->production_days }}" onsubmit="return false">
                <label for="pinbox">Check delivery</label>
                <div><input id="pinbox" inputmode="numeric" maxlength="6" placeholder="Enter PIN code" autocomplete="postal-code"><button type="submit" class="btn small">Check</button></div>
                <p class="small" data-pin-result aria-live="polite"></p>
            </form>

            <ul class="assure">
                <li><svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg><span>Get it by <b>{{ $deliverFrom->format('D, j M') }} – {{ $deliverTo->format('D, j M') }}</b><small>Made in {{ $product->production_days }} {{ \Illuminate\Support\Str::plural('day', $product->production_days) }}, then shipped</small></span></li>
                <li><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg><span><b>Secure payments</b><small>UPI, cards, netbanking{{ setting('cod_enabled') ? ', cash on delivery' : '' }}</small></span></li>
                <li><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-3-6.2"/><path d="M20 4v5h-5"/></svg><span><b>Damage-free promise</b><small>Arrived damaged? We remake it free.</small></span></li>
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
        @if($infoAttrs->isNotEmpty() || $product->sku || $product->weight_kg)
        <details>
            <summary><h2>Specifications</h2></summary>
            <table class="specs">
                @foreach($infoAttrs as $a)<tr><th>{{ $a->name }}</th><td>{{ implode(', ', $a->values) }}</td></tr>@endforeach
                @if($product->sku)<tr><th>SKU</th><td>{{ $product->sku }}</td></tr>@endif
                @if($product->weight_kg)<tr><th>Weight</th><td>{{ rtrim(rtrim(number_format($product->weight_kg, 3), '0'), '.') }} kg</td></tr>@endif
                <tr><th>Brand</th><td>{{ $product->brand }}</td></tr>
            </table>
        </details>
        @endif
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
        @if($product->rating_count)
            <p class="big-rating"><b>{{ number_format($product->rating_avg, 1) }}</b> <span class="stars-sm"><span style="--r: {{ $product->rating_avg }}"></span></span> <span class="muted">based on {{ $product->rating_count }} {{ \Illuminate\Support\Str::plural('review', $product->rating_count) }}</span></p>
        @endif
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
        <div class="section-head"><h2>You may also like</h2></div>
        <div class="rail">@foreach($related as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
    </section>
    @endif
</div>

{{-- Phone: price + add-to-cart stays reachable above the tab bar --}}
<div class="sticky-buy only-mobile" data-sticky-buy>
    <div><small class="muted">{{ \Illuminate\Support\Str::limit($product->name, 28) }}</small><b data-price>{{ money($product->currentPrice()) }}</b></div>
    <button class="btn primary" data-scroll-buy>{{ $product->customizationFields->isNotEmpty() ? 'Personalise' : 'Add to cart' }}</button>
</div>
@endsection
