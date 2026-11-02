<article class="hc hc--verso" data-side="verso" data-template="{{ $card['code'] ?? 'AILCT-00' }}" style="--hc-color: {{ $card['tone']['color'] }}; --hc-ink: {{ $card['tone']['ink'] }}" aria-label="Verso de la carte">
    <header class="hc__band"></header>
    <p class="hc__line hc__line--main">{{ $card['verso']['ligne1'] }}</p>
    @if ($card['verso']['ligne2'] !== '')<p class="hc__line">{{ $card['verso']['ligne2'] }}</p>@endif
    <div class="hc__qr">{!! $card['qr'] !!}</div>
    <p class="hc__id hc__id--small">{{ $card['identifier'] }}</p>
    @if ($card['badge_code'])<p class="hc__tech">Carte n° {{ $card['rank'] }} · Badge {{ $card['badge_code'] }}</p>@endif
    <p class="hc__notice">{{ $card['verso']['avis'] }}</p>
    @if ($card['verso']['contact'] !== '')<p class="hc__contact">{{ $card['verso']['contact'] }}</p>@endif
    @if ($card['specimen'])<p class="hc__mark">EXEMPLE · SANS VALEUR</p>@elseif ($card['provisional'])<p class="hc__mark">MODÈLE PROVISOIRE · NON VALIDÉ</p>@endif
</article>
