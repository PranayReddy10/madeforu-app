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
                @elseif ($type === 'textarea')
                    <label>{{ $label }} @if ($help)<small class="muted">{{ $help }}</small>@endif<textarea name="{{ $name }}" rows="3">{{ $val }}</textarea></label>
                @else
                    <label>{{ $label }} @if ($help)<small class="muted">{{ $help }}</small>@endif<input type="{{ $type === 'number' ? 'number' : 'text' }}" @if ($type === 'number') step="0.01" min="0" @endif name="{{ $name }}" value="{{ $val }}"></label>
                @endif
            @endforeach
        </section>
    @endforeach
    <p class="muted">Payment keys (Razorpay), mail and database settings live in the <code>.env</code> file on the server, not here.</p>
    <button class="btn">Save settings</button>
</form>
@endsection
