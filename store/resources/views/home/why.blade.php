<section class="section">
    <div class="wrap">
        <ul class="why-grid">
            @foreach([1 => 'hand', 2 => 'sparkle', 3 => 'truck', 4 => 'return'] as $n => $ico)
                @if($s['w'.$n.'_title'])<li>@include('partials.icon', ['i' => $ico])<b>{{ $s['w'.$n.'_title'] }}</b><span>{{ $s['w'.$n.'_text'] }}</span></li>@endif
            @endforeach
        </ul>
    </div>
</section>
