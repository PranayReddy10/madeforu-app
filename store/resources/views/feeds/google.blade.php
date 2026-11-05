{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
<channel>
    <title>{{ config('store.name') }}</title>
    <link>{{ url('/') }}</link>
    <description>{{ config('store.tagline') }}</description>
@foreach($rows as $r)
    <item>
        <g:id>{{ $r['id'] }}</g:id>
        @if($r['group'])<g:item_group_id>{{ $r['group'] }}</g:item_group_id>@endif
        <g:title>{{ \Illuminate\Support\Str::limit($r['title'], 150, '') }}</g:title>
        <g:description>{{ $r['description'] }}</g:description>
        <g:link>{{ $r['link'] }}</g:link>
        <g:image_link>{{ $r['image'] }}</g:image_link>
        @foreach($r['additional_images'] as $img)<g:additional_image_link>{{ $img }}</g:additional_image_link>
        @endforeach
        <g:availability>{{ $r['availability'] }}</g:availability>
        <g:price>{{ number_format($r['price'], 2, '.', '') }} INR</g:price>
        @if($r['sale_price'])<g:sale_price>{{ number_format($r['sale_price'], 2, '.', '') }} INR</g:sale_price>@endif
        <g:condition>new</g:condition>
        <g:brand>{{ $r['brand'] }}</g:brand>
        @if($r['gtin'])<g:gtin>{{ $r['gtin'] }}</g:gtin>@else<g:identifier_exists>no</g:identifier_exists>@endif
        @if($r['google_category'])<g:google_product_category>{{ $r['google_category'] }}</g:google_product_category>@endif
        @if($r['product_type'])<g:product_type>{{ $r['product_type'] }}</g:product_type>@endif
        @if($r['shipping_weight'])<g:shipping_weight>{{ $r['shipping_weight'] }} kg</g:shipping_weight>@endif
        <g:is_bundle>no</g:is_bundle>
        @foreach($r['options'] as $k => $v)
            @if(str_contains(strtolower($k), 'size'))<g:size>{{ $v }}</g:size>@elseif(str_contains(strtolower($k), 'colo'))<g:color>{{ $v }}</g:color>@endif
        @endforeach
        <g:shipping><g:country>IN</g:country><g:price>{{ $r['price'] >= setting('free_shipping_over') ? '0' : number_format((float) setting('shipping_fee'), 2, '.', '') }} INR</g:price></g:shipping>
    </item>
@endforeach
</channel>
</rss>
