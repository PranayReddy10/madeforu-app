{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($maps as $m)
    <sitemap><loc>{{ $m['loc'] }}</loc>@if($m['lastmod'])<lastmod>{{ $m['lastmod'] }}</lastmod>@endif</sitemap>
@endforeach
</sitemapindex>
