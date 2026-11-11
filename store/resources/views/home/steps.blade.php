<section class="section how" id="how">
    <div class="wrap">
        <div class="center">@if($s['eyebrow'])<span class="eyebrow">{{ $s['eyebrow'] }}</span>@endif<h2>{{ $s['title'] }}</h2></div>
        <ol class="steps steps-cards">
            @foreach([1 => 'gift', 2 => 'camera', 3 => 'truck'] as $n => $ico)
                <li><span class="step-n">{{ $n }}</span>@include('partials.icon', ['i' => $ico, 'class' => 'step-ico'])<h3>{{ $s['s'.$n.'_title'] }}</h3><p>{{ $s['s'.$n.'_text'] }}</p></li>
            @endforeach
        </ol>
        @if($s['button'])<div class="center"><a class="btn primary" href="{{ $s['link'] ?: url('shop').'/' }}">{{ $s['button'] }} @include('partials.icon', ['i' => 'arrow'])</a></div>@endif
    </div>
</section>
