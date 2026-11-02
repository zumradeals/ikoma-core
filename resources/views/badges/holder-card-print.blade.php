<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $alignment ? 'Page d’essai d’alignement' : 'Impression de la carte' }} — {{ $institution }}</title>
<link rel="stylesheet" href="/badges/holder-cards.css">
<style nonce="{{ $nonce }}">
* { box-sizing: border-box; }
body { margin: 0; background: #eaf0f6; color: #0f172a; font-family: Arial, sans-serif; }
.toolbar { padding: 24px; background: #fff; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; font-size: 18px; }
.toolbar p { width: 100%; margin: 0; line-height: 1.5; }
.toolbar button, .toolbar a { min-height: 56px; padding: 16px 24px; font: inherit; border-radius: 10px; }
.toolbar button { background: #0B3C6B; color: #fff; border: 0; cursor: pointer; }
.sheet { width: 53.98mm; height: 85.6mm; margin: 24px auto; background: #fff; overflow: hidden; position: relative; }
@media screen { .sheet { zoom: 1.6; box-shadow: 0 2px 12px #0f172a26; } }
@media screen and (max-width: 480px) { .sheet { zoom: 1.25; } }
@if ($alignment)
.align { position: relative; width: 53.98mm; height: 85.6mm; font-size: 2.4mm; }
.align__edge { position: absolute; inset: 0; border: .2mm solid #000; }
.align__safe { position: absolute; inset: 3mm; border: .2mm dashed #475569; }
.align__h, .align__v { position: absolute; background: #000; }
.align__h { left: 0; right: 0; top: 42.8mm; height: .15mm; }
.align__v { top: 0; bottom: 0; left: 26.99mm; width: .15mm; }
.align__ruler { position: absolute; left: 4mm; top: 6mm; display: flex; flex-direction: column; gap: 1mm; }
.align__ruler i { display: block; height: .3mm; background: #000; }
.align__label { position: absolute; left: 0; right: 0; top: 24mm; text-align: center; font-weight: 700; font-size: 3mm; }
@endif
@page { size: 53.98mm 85.6mm; margin: 0; }
@media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; break-after: page; } .sheet:last-child { break-after: auto; } }
</style>
</head>
<body>
<div class="toolbar">
<strong>{{ $alignment ? 'Page d’essai d’alignement (aucun QR, aucune donnée personnelle)' : (isset($cards) ? count($cards).' carte(s)' : ($kind === 'emission' ? 'Émission de la carte' : 'Réimpression de la carte')) }} · CR80 · recto puis verso</strong>
<button id="print" type="button">Imprimer</button><a href="{{ $back }}">Retour</a>
<p>Aperçu agrandi à l’écran pour faciliter la lecture. L’impression conserve la taille réelle CR80.</p>
<p>Format du papier : <strong>CR80, 53,98 × 85,60 mm, portrait</strong>. Échelle <strong>100 % / taille réelle</strong>, sans en-têtes ni pieds de page, arrière-plans activés. Recto-verso : le recto est la page 1, le verso la page 2, retournement sur le <strong>grand côté</strong>.</p>
<p>@if ($alignment)Imprimez cette page en recto-verso puis tenez la carte à contre-jour : les traits du recto et du verso doivent se superposer. La zone en pointillés est la zone de sécurité de 3 mm.@else Faites d’abord un essai sur la page d’alignement. Le navigateur ne confirme pas le tirage physique ; la compatibilité avec une imprimante PVC précise n’est pas garantie sans essai réel sur ce matériel.@endif</p>
<p id="layout-error" role="alert" hidden>Un texte est trop long pour la carte. Revenez à la fiche et raccourcissez-le avant d’imprimer.</p>
<p id="print-error" role="alert" hidden>La fenêtre d’impression n’a pas pu s’ouvrir. Utilisez le menu Imprimer du navigateur.</p>
</div>
@if ($alignment)
@foreach (['RECTO', 'VERSO'] as $face)
<section class="sheet" aria-label="{{ $face }}"><div class="align" data-side="{{ strtolower($face) }}"><div class="align__edge"></div><div class="align__safe"></div><div class="align__h"></div><div class="align__v"></div><div class="align__ruler">@foreach ([10, 8, 6, 4, 2] as $w)<i style="width: {{ $w }}mm"></i>@endforeach</div><div class="align__label">{{ $face }}<br>53,98 × 85,60 mm</div></div></section>
@endforeach
@else
@foreach ($cards ?? [$card] as $c)
<section class="sheet" aria-label="Recto">@include('badges.holder-card-recto', ['card' => $c])</section>
<section class="sheet" aria-label="Verso">@include('badges.holder-card-verso', ['card' => $c])</section>
@endforeach
@endif
<script nonce="{{ $nonce }}">document.getElementById('print').addEventListener('click', function () { try {
 var invalid = Array.from(document.querySelectorAll('.hc, .hc__name, .hc__role, .hc__mention, .hc__category, .hc__line, .hc__notice, .hc__contact, .hc__institution, .hc__validity, .hc__tech')).some(function (t) { return t.scrollHeight > t.clientHeight + 1 || t.scrollWidth > t.clientWidth + 1; });
 document.getElementById('layout-error').hidden = !invalid;
 if (!invalid) window.print();
 } catch (e) { document.getElementById('print-error').hidden = false; } });</script>
</body>
</html>
