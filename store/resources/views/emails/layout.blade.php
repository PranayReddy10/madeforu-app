{{-- Shared look for every email: logo, white card, contact footer. Inline styles only (email apps ignore <style>). --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ config('store.name') }}</title></head>
<body style="margin:0;padding:0;background:#fffafc;font-family:Arial,Helvetica,sans-serif;color:#1c2844;-webkit-text-size-adjust:100%">
@hasSection('preheader')<div style="display:none;max-height:0;overflow:hidden;opacity:0">@yield('preheader')</div>@endif
<table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td align="center" style="padding:24px 12px">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%">
    <tr><td style="padding:0 4px 14px"><a href="{{ url('/') }}"><img src="{{ asset('images/logo.png') }}" alt="{{ config('store.name') }}" height="44" style="height:44px;border:0"></a></td></tr>
    <tr><td style="background:#ffffff;border-radius:14px;padding:26px 24px;border:1px solid #f2e6eb">
        @yield('body')
    </td></tr>
    <tr><td style="padding:16px 8px;font-size:12px;color:#858ca3;text-align:center;line-height:1.6">
        {{ config('store.name') }} · {{ config('store.tagline') }}<br>
        @if(config('store.whatsapp'))WhatsApp <a href="https://wa.me/{{ config('store.whatsapp') }}" style="color:#858ca3">+{{ config('store.whatsapp') }}</a> · @endif
        <a href="mailto:{{ config('store.email') }}" style="color:#858ca3">{{ config('store.email') }}</a> · <a href="{{ url('/') }}" style="color:#858ca3">{{ parse_url(url('/'), PHP_URL_HOST) }}</a>
        @yield('footer')
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
