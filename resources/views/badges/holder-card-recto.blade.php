@php($qrRecto = ($card['qrSide'] ?? 'verso') === 'recto')
<article class="hc hc--recto {{ $qrRecto ? 'hc--qr-recto' : '' }}" data-side="recto" data-template="{{ $card['code'] ?? 'AILCT-00' }}" style="--hc-color: {{ $card['tone']['color'] }}; --hc-ink: {{ $card['tone']['ink'] }}" aria-label="Recto de la carte">
    <header class="hc__head">
        @if ($card['institution']['logo'])<img class="hc__logo" src="{{ $card['institution']['logo'] }}" alt="">@endif
        <span class="hc__institution">{{ $card['institution']['fullName'] }}</span>
    </header>
    @if ($qrRecto)<div class="hc__row">@endif
    <div class="hc__photo">@if ($card['portrait'])<img src="{{ $card['portrait'] }}" alt="Portrait du titulaire">@else<span aria-hidden="true">{{ $card['symbol'] }}</span>@endif</div>
    @if ($qrRecto)<div class="hc__qr hc__qr--recto">{!! $card['qr'] !!}</div></div>@endif
    <p class="hc__name {{ $card['nameClass'] }}">{{ $card['name'] }}</p>
    @if (($card['role'] ?? '') !== '')<p class="hc__role">{{ $card['role'] }}</p>@endif
    <p class="hc__mention"><span class="hc__symbol">{{ $card['symbol'] }}</span><span>{{ $card['mention'] }}</span></p>
    <p class="hc__id">{{ $card['identifier'] }}</p>
    @if ($card['validity'])<p class="hc__validity">{{ $card['validity'] }}</p>@endif
    @if ($card['specimen'])<p class="hc__mark">EXEMPLE · SANS VALEUR</p>@elseif ($card['provisional'])<p class="hc__mark">MODÈLE PROVISOIRE · NON VALIDÉ</p>@endif
</article>
