@php
    // Shop admins see what went wrong (to fix it or send it to the developer); customers never do.
    try {
        $isAdmin = (bool) auth()->user()?->is_admin;
    } catch (\Throwable) {
        $isAdmin = false;
    }
    $e = $exception ?? null;
    $inner = $e && method_exists($e, 'getPrevious') && $e->getPrevious() ? $e->getPrevious() : $e;
@endphp
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Something went wrong</title>
<style>
    body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #fdf6f8; color: #1c2844; }
    .box { max-width: 640px; margin: 12vh auto; padding: 0 16px; text-align: center; }
    h1 { font-size: 26px; margin: 0 0 10px; }
    p { color: #4a5470; line-height: 1.5; }
    a.btn { display: inline-block; margin-top: 10px; background: #f04577; color: #fff; padding: 11px 22px; border-radius: 999px; text-decoration: none; font-weight: 600; }
    .admin { text-align: left; background: #fff; border: 2px solid #f3c27a; border-radius: 14px; padding: 14px 16px; margin-top: 24px; font-size: 14px; }
    .admin code { display: block; white-space: pre-wrap; word-break: break-word; background: #fff6e0; border-radius: 8px; padding: 10px; margin: 8px 0; font-size: 13px; }
</style>
</head>
<body>
<div class="box">
    <h1>Something went wrong</h1>
    <p>Sorry — this page hit a problem on our side. Please try again in a minute, or message us on WhatsApp and we’ll help.</p>
    <a class="btn" href="{{ url('/') }}">Go to home</a>
    @if ($isAdmin && $inner)
        <div class="admin">
            <b>Only shop admins see this box.</b> Send this text to whoever maintains the site:
            <code>{{ class_basename($inner) }}: {{ \Illuminate\Support\Str::limit($inner->getMessage(), 600) }}
{{ str_replace(base_path().DIRECTORY_SEPARATOR, '', $inner->getFile()) }}:{{ $inner->getLine() }}
{{ request()->method() }} {{ request()->path() }} · {{ now()->format('d M Y H:i:s') }}</code>
            The full details are in <b>storage/logs/laravel.log</b> on the server.
        </div>
    @endif
</div>
</body>
</html>
