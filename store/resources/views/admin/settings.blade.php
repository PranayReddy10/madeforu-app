@extends('admin.layout')
@section('title', 'Settings')
@section('content')
@php use App\Http\Controllers\Admin\SettingController as S; @endphp
<form method="post" action="{{ url('admin/settings') }}" class="narrow">
    @csrf @method('PUT')
    @foreach ($groups as $group => $fields)
        <section class="card">
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
    <p class="muted">Mail and database settings live in the <code>.env</code> file on the server. Payment keys can be set here or in <code>.env</code>; the ones saved here win.</p>
    <button class="btn">Save settings</button>
</form>
@endsection
