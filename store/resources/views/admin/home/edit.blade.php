@extends('admin.layout')
@section('title', 'Home page')
@section('actions')<a class="btn ghost" href="{{ url('/') }}" target="_blank" rel="noopener">View home page ↗</a>@endsection
@section('content')
<p class="muted">Turn sections on or off with the switch, move them with ▲ ▼, and click a section to edit its texts. Add your own promo banners, product rows, category spotlights and text blocks below. Nothing changes on the site until you press <b>Save home page</b>.</p>

<form method="post" action="{{ route('admin.home.update') }}" class="hb" data-hb>
    @csrf @method('PUT')
    <div class="hb-list" data-hb-list>
        @foreach ($sections as $s)
            @include('admin.home.section', ['s' => $s])
        @endforeach
    </div>

    <div class="card hb-add">
        <b>Add a section</b>
        <select data-hb-type>
            @foreach (\App\Support\HomeLayout::TYPES as $type => [$name, $addable])
                @if ($addable)<option value="{{ $type }}">{{ $name }}</option>@endif
            @endforeach
        </select>
        <button type="button" class="btn small" data-hb-add>+ Add</button>
    </div>
    @foreach (\App\Support\HomeLayout::TYPES as $type => [$name, $addable])
        @if ($addable)
            <template data-hb-template="{{ $type }}">@include('admin.home.section', ['s' => \App\Support\HomeLayout::make($type, ['id' => 'xxidxx'])])</template>
        @endif
    @endforeach

    <div class="save-bar"><span class="muted small" data-hb-dirty hidden>Unsaved changes</span><button class="btn">Save home page</button></div>
</form>

<form method="post" action="{{ route('admin.home.update') }}" class="card narrow danger-zone">
    @csrf @method('PUT')
    <input type="hidden" name="reset" value="1">
    <button class="btn ghost" data-confirm="Go back to the standard home page? Your sections and texts here will be replaced.">Reset to the standard home page</button>
</form>
@endsection
