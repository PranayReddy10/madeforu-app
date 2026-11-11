@php
    $ga4 = setting('analytics.ga4'); $gtm = setting('analytics.gtm'); $ads = setting('analytics.google_ads');
    $pixel = setting('analytics.meta_pixel'); $clarity = setting('analytics.clarity');
@endphp
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
window.MFU = { ga4: @json($ga4), ads: @json($ads), adsLabel: @json(setting('analytics.google_ads_purchase_label')), pixel: @json($pixel), gtm: @json($gtm) };
/* One call per e-commerce event: GTM reads dataLayer; GA4/Ads get gtag; Meta gets fbq. */
window.mfuTrack = function (e) {
    dataLayer.push({ ecommerce: null }); dataLayer.push(e);
    var ec = e.ecommerce || {};
    if (MFU.ga4 && !MFU.gtm) gtag('event', e.event, ec);
    if (MFU.ads && MFU.adsLabel && e.event === 'purchase') gtag('event', 'conversion', { send_to: MFU.ads + '/' + MFU.adsLabel, value: ec.value, currency: 'INR', transaction_id: ec.transaction_id });
    if (window.fbq) {
        var map = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase', search: 'Search', add_to_wishlist: 'AddToWishlist' };
        if (map[e.event]) fbq('track', map[e.event], { value: ec.value, currency: 'INR', search_string: ec.search_term, content_type: 'product', content_ids: (ec.items || []).map(function (i) { return i.item_id; }), num_items: (ec.items || []).length }, ec.transaction_id ? { eventID: ec.transaction_id } : {});
    }
};
gtag('consent', 'default', { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
</script>
@if($gtm)
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@json($gtm));</script>
@endif
@if($ga4 || $ads)
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga4 ?: $ads }}"></script>
<script>gtag('js', new Date());@if($ga4) gtag('config', @json($ga4));@endif @if($ads) gtag('config', @json($ads));@endif</script>
@endif
@if($pixel)
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',@json($pixel));fbq('track','PageView');</script>
@endif
@if($clarity)
<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script",@json($clarity));</script>
@endif
