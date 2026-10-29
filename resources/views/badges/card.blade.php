<article class="access-card access-card--{{ $options['design'] }} access-card--{{ $options['orientation'] }} {{ $person ? 'access-card--nominatif' : '' }} {{ $portrait ? 'access-card--photo' : '' }}" style="--badge-primary: {{ $options['primary'] }}; --badge-accent: {{ $options['accent'] }}" aria-label="Badge {{ $badge->code }}">
    <header class="access-card__identity">
        <img src="/brand/ikoma-access-logo.svg" alt="IKOMA ACCESS" width="140" height="34">
        <p @class(['access-card__title-long' => mb_strlen($options['title']) > 35])>{{ $options['title'] }}</p>
    </header>
    @if (($options['side'] ?? 'recto') === 'verso')
        <div class="access-card__instructions">
            <strong>Consignes de visite</strong>
            <p>Portez votre badge de façon visible.</p>
            <p>Respectez les consignes du personnel d’accueil.</p>
            <p>En cas de perte, prévenez immédiatement l’accueil.</p>
        </div>
    @else
        <div class="access-card__type">{{ $options['design'] === 'vip' ? 'VIP' : ($options['design'] === 'prestataire' ? (mb_stripos($badge->category ?? '', 'stagiaire') !== false ? 'STAGIAIRE' : 'PRESTATAIRE') : 'VISITEUR') }}</div>
        <div class="access-card__content">
            @if ($portrait)<img class="access-card__portrait" src="{{ $portrait }}" alt="Portrait autorisé">@endif
            <div class="access-card__details"><span class="access-card__caption">BADGE D’ACCÈS</span>@if ($person)<strong class="access-card__name">{{ $person->full_name }}</strong>@if (!empty($options['show_org']))<span class="access-card__personal">{{ $person->position ?: $person->organisation }}</span>@endif @if (!empty($options['show_period']))<span class="access-card__personal">{{ $person->valid_from->format('d/m/Y') }} – {{ $person->valid_until->format('d/m/Y') }}</span>@endif @endif<strong @class(['access-card__code', 'access-card__code-long' => strlen($badge->code) > 7])>{{ $badge->code }}</strong><span class="access-card__category">{{ $badge->category ?? 'Sans catégorie' }}</span></div>
            <div class="access-card__qr">{!! $svg !!}</div>
        </div>
    @endif
    <footer class="access-card__footer">Badge à restituer à la sortie</footer>
</article>
