<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Impression des badges — IKOMA ACCESS</title>
<link rel="stylesheet" href="/badges/cards.css">
<style nonce="{{ $nonce }}">
* { box-sizing: border-box; }
body { margin: 0; background: #eaf0f6; color: #0B3C6B; font-family: Arial, sans-serif; }
.toolbar { padding: 24px; background: white; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; font-size: 18px; }
.toolbar p { width: 100%; margin: 0; line-height: 1.5; }
button, a { min-height: 56px; padding: 16px 24px; font: inherit; border-radius: 10px; }
button { background: #0066B3; color: white; border: 0; cursor: pointer; }
.sheet { background: white; margin: 24px auto; width: {{ $options['format'] === 'pvc' ? $dimensions[0] : 210 }}mm; height: {{ $options['format'] === 'pvc' ? $dimensions[1] : 297 }}mm; padding: {{ $options['format'] === 'pvc' ? 0 : 10 }}mm; }
.grid { display: grid; grid-template-columns: repeat({{ $options['format'] === 'pvc' ? 1 : (int) $options['cols'] }}, {{ $dimensions[0] }}mm); grid-auto-rows: {{ $dimensions[1] }}mm; }
@page { size: {{ $options['format'] === 'pvc' ? $dimensions[0].'mm '.$dimensions[1].'mm' : 'A4 portrait' }}; margin: 0; }
@media print { body { background: white; } .toolbar { display: none; } .sheet { margin: 0; break-after: page; } .sheet:last-child { break-after: auto; } }
</style>
</head>
<body>
<div class="toolbar">
<strong>{{ $count }} badge(s) · {{ strtoupper($options['format']) }} · {{ $options['side'] }}</strong>
<button id="print" type="button">Imprimer les badges</button><a href="/administration/badges/modeles">Retour à l’atelier</a>
<p>Réglez l’échelle sur <strong>100 % / taille réelle</strong>, sans en-têtes ni pieds de page. Activez les arrière-plans. {{ $options['format'] === 'pvc' ? 'Choisissez le papier CR80 dans le pilote de votre imprimante PVC.' : 'Choisissez du papier A4.' }} Faites d’abord un essai avec un seul badge.</p>
<p>Le navigateur ne confirme pas le tirage physique. Si rien ne sort, vérifiez l’imprimante sélectionnée, le papier et sa connexion, puis relancez. Réimprimer conserve le même QR et les mêmes règles d’accès.</p>
@if ($options['side'] === 'verso')<p>Verso générique seul. Pour un recto-verso, imprimez le recto séparément et vérifiez le sens de réinsertion sur une feuille d’essai.</p>@endif
<p id="layout-error" role="alert" hidden>Certains textes sont trop longs pour ce format. Revenez à l’atelier, choisissez le paysage ou masquez les champs facultatifs, puis vérifiez l’aperçu.</p>
<p id="print-error" role="alert" hidden>La fenêtre d’impression n’a pas pu s’ouvrir. Utilisez le menu Imprimer du navigateur.</p>
</div>
@foreach ($sheets as $sheet)
<section class="sheet" aria-label="Feuille {{ $loop->iteration }}"><div class="grid">@foreach ($sheet as $card){!! $card !!}@endforeach</div></section>
@endforeach
<script nonce="{{ $nonce }}">document.getElementById('print').addEventListener('click', function () { try {
 var overlap = function (a, b) { return Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1; };
 var invalid = Array.from(document.querySelectorAll('.access-card')).some(function (card) {
   var qr = card.querySelector('.access-card__qr'), details = card.querySelector('.access-card__details'), footer = card.querySelector('.access-card__footer');
   if (qr && (overlap(qr.getBoundingClientRect(), footer.getBoundingClientRect()) || (details && overlap(qr.getBoundingClientRect(), details.getBoundingClientRect())))) return true;
   if (details && overlap(details.getBoundingClientRect(), footer.getBoundingClientRect())) return true;
   return Array.from(card.querySelectorAll('.access-card__category, .access-card__identity p')).some(function (text) { return text.scrollHeight > text.clientHeight + 1; });
 });
 document.getElementById('layout-error').hidden = !invalid;
 if (!invalid) window.print();
 } catch (e) { document.getElementById('print-error').hidden = false; } });</script>
</body>
</html>
