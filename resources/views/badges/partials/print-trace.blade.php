{{-- Suivi de tirage commun aux pages d'impression de l'Atelier. Variables : $nonce, $runId (nullable), $count, $check ('cards' | 'none'), $nature ('officiel' | 'essai'). --}}
@if (!empty($runId))
<style nonce="{{ $nonce }}">
.trace { margin: 16px; padding: 20px; background: #fff; border: 2px solid #0B3C6B; border-radius: 12px; font-size: 18px; color: #0f172a; }
.trace[hidden] { display: none; }
.trace p { margin: 8px 0; line-height: 1.5; }
.trace label { display: inline-block; margin-right: 16px; font-weight: 600; }
.trace input { display: block; min-height: 48px; min-width: 110px; margin-top: 4px; font-size: 18px; padding: 6px 10px; }
.trace input[type=text] { min-width: 320px; }
.trace button { min-height: 56px; margin-top: 12px; padding: 12px 24px; font-size: 18px; font-weight: 700; background: #0B3C6B; color: #fff; border: 0; border-radius: 10px; cursor: pointer; }
.trace-state { margin: 16px; padding: 12px 20px; background: #fff; border-left: 6px solid #0066B3; font-size: 18px; color: #0f172a; }
.trace-essai { margin: 16px; padding: 12px 20px; background: #fffbeb; border: 2px solid #b45309; font-size: 18px; font-weight: 700; color: #92400e; }
@media print { .trace, .trace-state, .trace-essai { display: none; } }
</style>
@if (($nature ?? 'officiel') === 'essai')<p class="trace-essai" role="note">ESSAI — exemple sans valeur : badge fictif et QR d’exemple, aucun vrai badge. Ne le remettez à personne et ne le confirmez pas comme une émission officielle.</p>@endif
<p class="trace-state" id="trace-state" role="status">Document généré. L’impression n’est pas encore demandée.</p>
<section class="trace" id="trace" data-run="{{ $runId }}" data-count="{{ $count }}" hidden aria-label="Confirmer l’impression">
<strong>Après l’impression : confirmer le résultat</strong>
<p>Le navigateur ne peut pas savoir si les supports sont réellement sortis de l’imprimante. Contrôlez-les, puis déclarez ce que vous avez constaté : cette déclaration est enregistrée à votre nom.</p>
<label>Badges imprimés<input id="trace-printed" type="number" min="0" max="{{ $count }}" value="{{ $count }}"></label>
<label>Badges gâchés<input id="trace-spoiled" type="number" min="0" max="{{ $count }}" value="0"></label>
<label>Note facultative<input id="trace-note" type="text" maxlength="200"></label>
<button type="button" id="trace-confirm">Confirmer l’impression</button>
<p id="trace-message" role="status"></p>
</section>
@endif
<script nonce="{{ $nonce }}">(function () {
var run = document.getElementById('trace') ? document.getElementById('trace').getAttribute('data-run') : null;
function token() { var c = document.cookie.split('; ').find(function (x) { return x.indexOf('XSRF-TOKEN=') === 0; }); return c ? decodeURIComponent(c.slice(11)) : ''; }
function post(path, body) { return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': token() }, body: JSON.stringify(body || {}) }); }
function show(id, text) { var el = document.getElementById(id); if (el) { el.textContent = text; } }
function overlap(a, b) { return Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1; }
function layoutInvalid() {
 @if (($check ?? 'none') === 'cards')
 return Array.from(document.querySelectorAll('.access-card')).some(function (card) {
   var qr = card.querySelector('.access-card__qr'), details = card.querySelector('.access-card__details'), footer = card.querySelector('.access-card__footer');
   if (qr && footer && (overlap(qr.getBoundingClientRect(), footer.getBoundingClientRect()) || (details && overlap(qr.getBoundingClientRect(), details.getBoundingClientRect())))) return true;
   if (details && footer && overlap(details.getBoundingClientRect(), footer.getBoundingClientRect())) return true;
   return Array.from(card.querySelectorAll('.access-card__category, .access-card__identity p')).some(function (text) { return text.scrollHeight > text.clientHeight + 1; });
 });
 @else
 return false;
 @endif
}
var button = document.querySelector('[data-print]');
if (button) { button.addEventListener('click', function () {
 var layout = document.getElementById('layout-error'), failed = document.getElementById('print-error');
 try {
  if (layout) { layout.hidden = !layoutInvalid(); if (!layout.hidden) { return; } } else if (layoutInvalid()) { return; }
  var go = function () { window.print(); var panel = document.getElementById('trace'); if (panel) { panel.hidden = false; } };
  if (run) {
   post('/administration/atelier/tirages/' + run + '/demande').then(function (r) { show('trace-state', r.ok ? 'Impression demandée. Le navigateur ne confirme pas l’impression physique : confirmez-la ci-dessous une fois les supports contrôlés.' : 'La demande d’impression n’a pas pu être enregistrée (session expirée ?). L’impression continue ; rechargez la page pour la tracer.'); go(); }).catch(function () { show('trace-state', 'La demande d’impression n’a pas pu être enregistrée (réseau). L’impression continue.'); go(); });
  } else { go(); }
 } catch (e) { if (failed) { failed.hidden = false; } }
}); }
var confirmButton = document.getElementById('trace-confirm');
if (confirmButton && run) { confirmButton.addEventListener('click', function () {
 confirmButton.disabled = true;
 post('/administration/atelier/tirages/' + run + '/confirmation', { printed: Number(document.getElementById('trace-printed').value), spoiled: Number(document.getElementById('trace-spoiled').value), note: document.getElementById('trace-note').value }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (x) {
  if (x.ok) { show('trace-message', 'Impression confirmée et enregistrée à votre nom.'); show('trace-state', 'Impression confirmée par l’opérateur.'); } else { confirmButton.disabled = false; var errors = x.d && x.d.errors ? Object.keys(x.d.errors).map(function (k) { return x.d.errors[k][0]; }).join(' ') : 'Confirmation refusée.'; show('trace-message', errors); }
 }).catch(function () { confirmButton.disabled = false; show('trace-message', 'Confirmation impossible (réseau). Réessayez.'); });
}); }
})();</script>
