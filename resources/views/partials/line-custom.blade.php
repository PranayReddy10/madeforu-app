{{-- What the customer chose for one cart line: their photos (with slot names) and text. $compact: checkout summary. --}}
@foreach($l->details as $d)
    @if($d->type === 'images' && $d->uploads->isNotEmpty())
        <div class="line-photos {{ ! empty($compact) ? 'compact' : '' }}">
            @foreach($d->uploads as $i => $u)
                <a href="{{ $u->fullUrl() }}" class="lp" data-photo-view title="{{ $d->slots[$i] ?? $d->label }}">
                    <img src="{{ $u->thumbUrl() }}" alt="{{ $d->slots[$i] ?? 'Your photo' }}" width="64" height="64" loading="lazy">
                    @if(empty($compact) && ! empty($d->slots[$i]))<small>{{ $d->slots[$i] }}</small>@endif
                </a>
            @endforeach
        </div>
    @elseif($d->type === 'color')
        <p class="small line-opt">{{ $d->label }}: <span class="swatch" style="background: {{ $d->value }}"></span></p>
    @elseif($d->type !== 'images')
        <p class="small line-opt">{{ $d->label }}: <b>{{ \Illuminate\Support\Str::limit($d->value, ! empty($compact) ? 40 : 80) }}</b></p>
    @endif
@endforeach
