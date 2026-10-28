<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Étiquettes de badges</title>
<style nonce="{{ $nonce }}">
* { box-sizing: border-box; }
body { margin: 0; background: #e2e8f0; color: #000; font-family: system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; }
.toolbar { background: #fff; padding: 16px; border-bottom: 2px solid #0B3C6B; display: flex; flex-wrap: wrap; gap: 16px; align-items: end; font-size: 18px; }
.toolbar label { display: block; font-weight: 600; }
.toolbar input, .toolbar select { display: block; min-height: 48px; min-width: 96px; margin-top: 4px; font-size: 18px; padding: 6px 10px; }
.toolbar button { min-height: 56px; padding: 8px 24px; font-size: 18px; font-weight: 700; background: #0F5AA6; color: #fff; border: 0; border-radius: 10px; cursor: pointer; }
.toolbar a { font-size: 18px; }
.notice { margin: 16px; padding: 16px; background: #fff; border: 2px solid #D64545; font-size: 18px; }
.sheet { width: 210mm; height: 297mm; margin: 12px auto; background: #fff; padding: {{ $margin }}mm; box-shadow: 0 2px 8px rgba(0,0,0,.25); overflow: hidden; }
.grid { display: grid; width: 100%; height: 100%; grid-template-columns: repeat({{ $cols }}, 1fr); grid-template-rows: repeat({{ $rows }}, 1fr); }
.label { border: 0.3mm dashed #666; display: flex; flex-direction: column; overflow: hidden; }
.band { height: {{ $size['band'] }}mm; display: flex; align-items: center; justify-content: center; gap: 2mm; font-size: {{ $size['bandFont'] }}mm; font-weight: 800; text-transform: uppercase; letter-spacing: .3mm; white-space: nowrap; background: var(--c); color: var(--fg); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.body { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: space-evenly; padding: 1mm; min-height: 0; }
.code { font-size: {{ $size['code'] }}mm; font-weight: 900; line-height: 1; letter-spacing: .1mm; white-space: nowrap; }
.row { display: flex; align-items: center; justify-content: center; gap: 3mm; }
.qr svg { display: block; width: {{ $size['qr'] }}mm; height: {{ $size['qr'] }}mm; }
.num { font-size: {{ $size['numFont'] }}mm; font-weight: 700; line-height: 1; }
@page { size: A4 portrait; margin: 0; }
@media print {
  body { background: #fff; }
  .toolbar, .notice { display: none; }
  .sheet { height: 296mm; margin: 0; box-shadow: none; break-after: page; page-break-after: always; }
  .sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
</head>
<body>
<form class="toolbar" method="get" action="/administration/badges/etiquettes">
  <input type="hidden" name="batch" value="{{ $query['batch'] }}">
  <label>Du badge n°<input type="number" name="from" min="1" value="{{ $query['from'] ?? '' }}" placeholder="premier"></label>
  <label>Au badge n°<input type="number" name="to" min="1" value="{{ $query['to'] ?? '' }}" placeholder="dernier"></label>
  <label>Colonnes<select name="cols">@foreach (range(1, 4) as $n)<option value="{{ $n }}" @selected($cols === $n)>{{ $n }}</option>@endforeach</select></label>
  <label>Lignes<select name="rows">@foreach (range(1, 10) as $n)<option value="{{ $n }}" @selected($rows === $n)>{{ $n }}</option>@endforeach</select></label>
  <button type="submit">Mettre à jour la page</button>
  <button type="button" id="imprimer">Imprimer ({{ $count }} étiquette(s))</button>
  <a href="/administration/badges">Retour aux badges</a>
</form>
@if ($tooMany)
  <p class="notice" role="alert">Plus de {{ $max }} étiquettes demandées. Choisissez une plage de badges (du n° … au n° …) de {{ $max }} étiquettes au plus.</p>
@elseif ($count === 0)
  <p class="notice" role="alert">Aucun badge dans cette plage.</p>
@endif
@foreach ($sheets as $sheet)
<section class="sheet" aria-label="Feuille {{ $loop->iteration }}">
  <div class="grid">
    @foreach ($sheet as $label)
    <article class="label" style="--c: {{ $label['color'] }}; --fg: {{ $label['fg'] }}">
      <div class="band"><span aria-hidden="true">{{ $label['symbol'] }}</span><span>{{ $label['category'] }}</span></div>
      <div class="body">
        <div class="code">{{ $label['code'] }}</div>
        <div class="row"><div class="qr">{!! $label['svg'] !!}</div>@if ($label['number'] !== null)<div class="num">N° {{ $label['number'] }}</div>@endif</div>
      </div>
    </article>
    @endforeach
  </div>
</section>
@endforeach
<script nonce="{{ $nonce }}">document.getElementById('imprimer').addEventListener('click', function () { window.print(); });</script>
</body>
</html>
