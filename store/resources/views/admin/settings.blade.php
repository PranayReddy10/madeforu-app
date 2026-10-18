@extends('admin.layout')
@section('title', 'Settings')
@section('content')
@php use App\Http\Controllers\Admin\SettingController as S; @endphp
<form method="post" action="{{ url('admin/settings') }}" class="settings" data-settings>
    @csrf @method('PUT')
    {{-- One group at a time (tabs); the search box looks through every group. All groups save together. --}}
    <nav class="set-nav">
        <input type="search" placeholder="Search settings…" data-set-search aria-label="Search settings">
        @foreach ($groups as $group => $fields)
            <a href="#{{ \Illuminate\Support\Str::slug($group) }}" data-set-tab="{{ \Illuminate\Support\Str::slug($group) }}">{{ $group }}</a>
        @endforeach
    </nav>
    <div class="set-body">
    @foreach ($groups as $group => $fields)
        <section class="card set-group" id="{{ \Illuminate\Support\Str::slug($group) }}" data-set-group>
            <h2>{{ $group }}</h2>
            @foreach ($fields as $key => [$label, $type, $help])
                @php $name = S::inputName($key); $val = old($name, setting($key)); @endphp
                @if ($type === 'bool')
                    <input type="hidden" name="{{ $name }}" value="0">
                    <label class="check"><input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $val)> {{ $label }}</label>
                @elseif (str_starts_with($type, 'select:'))
                    <label>{{ $label }} @if ($help)<small class="muted">{{ $help }}</small>@endif
                        <select name="{{ $name }}">@foreach (S::options($type) as $ov => $ol)<option value="{{ $ov }}" @selected($val === $ov)>{{ $ol }}</option>@endforeach</select>
                    </label>
                @elseif ($type === 'secret_json')
                    @php $saved = (bool) \App\Models\Setting::where('key', $key)->whereNotNull('value')->where('value', '!=', '')->exists(); $cred = $saved ? json_decode((string) setting($key), true) : null; @endphp
                    <label>{{ $label }}
                        <small class="muted">{{ $saved ? 'Saved ✓'.(! empty($cred['client_email']) ? ' — '.$cred['client_email'] : '').'. Leave blank to keep it.' : 'Not set.' }} {{ $help }}</small>
                        <textarea name="{{ $name }}" rows="5" class="mono" spellcheck="false" autocomplete="off" placeholder="{{ $saved ? 'Paste a new key only to replace the saved one' : '{ &quot;type&quot;: &quot;service_account&quot;, &quot;client_email&quot;: …, &quot;private_key&quot;: … }' }}"></textarea>
                    </label>
                    @if ($saved)<label class="check"><input type="checkbox" name="{{ $name }}_clear" value="1"> Remove saved key</label>@endif
                @elseif ($type === 'secret')
                    @php $saved = (bool) \App\Models\Setting::where('key', $key)->whereNotNull('value')->where('value', '!=', '')->exists(); $fromEnv = !$saved && setting($key); @endphp
                    <label>{{ $label }}
                        <small class="muted">{{ $saved ? 'Saved ✓ — leave blank to keep it.' : ($fromEnv ? 'Set in .env ✓ — type here to override.' : 'Not set.') }} {{ $help }}</small>
                        <input type="password" name="{{ $name }}" value="" autocomplete="new-password" placeholder="{{ $saved || $fromEnv ? '••••••••••••' : '' }}">
                    </label>
                    @if ($saved)<label class="check"><input type="checkbox" name="{{ $name }}_clear" value="1"> Remove saved {{ strtolower($label) }}</label>@endif
                @elseif ($type === 'textarea')
                    <label>{{ $label }} @if ($help)<small class="muted">{{ $help }}</small>@endif<textarea name="{{ $name }}" rows="3">{{ $val }}</textarea></label>
                @else
                    <label>{{ $label }} @if ($help)<small class="muted">{{ $help }}</small>@endif<input type="{{ $type === 'number' ? 'number' : 'text' }}" @if ($type === 'number') step="0.01" min="0" @endif name="{{ $name }}" value="{{ $val }}"></label>
                @endif
            @endforeach
        </section>
    @endforeach
    <p class="muted small">Mail and database settings live in the <code>.env</code> file on the server. Payment keys can be set here or in <code>.env</code>; the ones saved here win.</p>
    <p class="muted set-none" data-set-none hidden>No setting matches that search.</p>
    </div>
    <div class="save-bar"><span class="muted small" data-set-dirty hidden>Unsaved changes</span><button class="btn">Save settings</button></div>
</form>
@endsection
