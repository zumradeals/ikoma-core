<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Page d’essai d’alignement — IKOMA ACCESS</title>
<style nonce="{{ $nonce }}">
* { box-sizing: border-box; }
body { margin: 0; background: #eaf0f6; color: #0f172a; font-family: Arial, sans-serif; }
.toolbar { padding: 24px; background: #fff; display: flex; flex-wrap: wrap; gap: 16px; align-items: center; font-size: 18px; }
.toolbar p { width: 100%; margin: 0; line-height: 1.5; }
.toolbar button, .toolbar a { min-height: 56px; padding: 16px 24px; font: inherit; border-radius: 10px; }
.toolbar button { background: #0B3C6B; color: #fff; border: 0; cursor: pointer; }
.sheet { position: relative; margin: 24px auto; background: #fff; overflow: hidden; width: {{ $format === 'pvc' ? $dimensions[0] : 210 }}mm; height: {{ $format === 'pvc' ? $dimensions[1] : 297 }}mm; }
.cell { position: absolute; border: .2mm solid #000; width: {{ $dimensions[0] }}mm; height: {{ $dimensions[1] }}mm; }
.cell i { position: absolute; background: #000; }
.cell .h { left: 0; right: 0; top: 50%; height: .15mm; }
.cell .v { top: 0; bottom: 0; left: 50%; width: .15mm; }
.cell .safe { position: absolute; inset: 3mm; border: .2mm dashed #475569; background: none; }
.cell b { position: absolute; left: 0; right: 0; top: 40%; text-align: center; font-size: 3mm; }
@page { size: {{ $format === 'pvc' ? $dimensions[0].'mm '.$dimensions[1].'mm' : 'A4 portrait' }}; margin: 0; }
@media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; break-after: page; } .sheet:last-child { break-after: auto; } }
</style>
</head>
<body>
<div class="toolbar">
<strong>Page d’essai d’alignement (aucun QR, aucune donnée personnelle, aucune trace) · {{ $format === 'pvc' ? 'PVC CR80' : 'Planche A4' }} · {{ $orientation }}</strong>
<button id="print" data-print type="button">Imprimer l’essai</button><a href="/administration/atelier">Retour à l’atelier</a>
<p>Échelle <strong>100 % / taille réelle</strong>, sans en-têtes ni pieds de page, arrière-plans activés. @if ($format === 'pvc')Imprimez recto-verso puis tenez le support à contre-jour : les traits du recto et du verso doivent se superposer. La zone en pointillés est la zone de sécurité de 3 mm.@else Mesurez un cadre : il doit faire {{ $dimensions[0] }} × {{ $dimensions[1] }} mm. La grille compte {{ $cols }} colonne(s) × {{ $rows }} ligne(s).@endif</p>
</div>
@if ($format === 'pvc')
@foreach (['RECTO', 'VERSO'] as $face)
<section class="sheet" aria-label="{{ $face }}"><div class="cell" style="left:0;top:0"><i class="h"></i><i class="v"></i><i class="safe"></i><b>{{ $face }}</b></div></section>
@endforeach
@else
<section class="sheet" aria-label="Planche A4">
@foreach (range(0, $rows - 1) as $r)@foreach (range(0, $cols - 1) as $c)
<div class="cell" style="left: {{ 10 + $c * $dimensions[0] }}mm; top: {{ 10 + $r * $dimensions[1] }}mm"><i class="h"></i><i class="v"></i><i class="safe"></i><b>{{ $r * $cols + $c + 1 }}</b></div>
@endforeach @endforeach
</section>
@endif
@include('badges.partials.print-trace', ['runId' => null, 'count' => 0, 'check' => 'none', 'nature' => 'officiel'])
</body>
</html>
