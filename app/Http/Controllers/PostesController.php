<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\Devices;
use App\Services\Poste\PostCapabilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Administration > Postes : création, code d'enrôlement à usage unique, révocation, réglages et conflits de synchronisation. */
final class PostesController
{
    public function index(): Response
    {
        Gate::authorize('postes.gerer');
        $authorized = DB::table('device_activities')->get(['device_id', 'event_id'])->groupBy('device_id');
        $postes = DB::table('devices as d')->leftJoin('events as e', 'e.id', '=', 'd.event_id')->orderBy('e.name')->orderBy('d.name')->get(['d.id', 'd.name', 'd.station', 'd.point_code', 'd.event_id', 'e.name as event_name', 'd.token_hash', 'd.revoked_at', 'd.enrolled_at', 'd.last_synced_at', 'd.enrollment_expires_at', 'd.wipe_acknowledged_at'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'station' => $d->station, 'point_code' => $d->point_code, 'event_id' => $d->event_id, 'event_name' => $d->event_name ?? 'Aucune activité en cours', 'activities' => ($authorized[$d->id] ?? collect())->pluck('event_id')->values()->all(), 'state' => $d->revoked_at ? 'revoque' : ($d->token_hash ? 'enrole' : 'non_enrole'), 'enrolled_at' => $d->enrolled_at, 'last_synced_at' => $d->last_synced_at, 'wipe_acknowledged_at' => $d->wipe_acknowledged_at, 'code_pending' => $d->enrollment_expires_at !== null && $d->enrollment_expires_at > now()->toDateTimeString()]);
        $counts = DB::table('sync_operations')->selectRaw('device_id, count(*) AS received, count(*) FILTER (WHERE status_code = 200) AS applied, count(*) FILTER (WHERE status_code IS NOT NULL AND status_code <> 200) AS conflicts')->groupBy('device_id')->get()->keyBy('device_id');
        $postes = $postes->map(fn ($p) => [...$p, 'received' => (int) ($counts[$p['id']]->received ?? 0), 'applied' => (int) ($counts[$p['id']]->applied ?? 0), 'conflicts' => (int) ($counts[$p['id']]->conflicts ?? 0)]);
        $setting = fn (string $key) => (int) DB::table('settings')->where('key', $key)->value('value');

        return Inertia::render('Admin/Postes', [
            'events' => DB::table('events')->orderBy('name')->get(['id', 'name']),
            'postes' => $postes, 'points' => PostCapabilities::points(false),
            'settings' => ['inactivity' => $setting('poste_inactivite_minutes'), 'skew' => $setting('poste_ecart_horloge_secondes'), 'pin' => $setting('poste_echecs_pin_max'), 'pointsDelay' => $setting('controle_delai_min_points'), 'burstCount' => $setting('controle_rafale_nombre'), 'burstMinutes' => $setting('controle_rafale_minutes'),
                'offlineCall' => $setting('poste_delai_appel_minutes'), 'offlineMaxHours' => $setting('poste_hors_reseau_max_heures'), 'offlineApproved' => DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->value('value') === '1', 'lowBadgesPercent' => $setting('poste_badges_bas_pourcent'), 'lowBadgesMin' => $setting('poste_badges_bas_minimum'), 'silentMinutes' => $setting('pilotage_poste_silencieux_minutes'), 'lateMinutes' => $setting('visite_retard_minutes'), 'escalationMinutes' => $setting('alerte_escalade_minutes'), 'identityDocument' => DB::table('settings')->where('key', 'identite_piece_active')->value('value') === '1'],
            'newCode' => session()->pull('enrol_code'),
            'conflicts' => DB::table('sync_conflicts as c')->join('devices as d', 'd.id', '=', 'c.device_id')->where('c.status', 'ouvert')->orderByDesc('c.created_at')->limit(50)->get(['c.id', 'c.kind', 'c.reason', 'c.operation_type', 'c.created_at', 'd.name as device_name']),
        ]);
    }

    public function store(Request $r, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $r->validate(['event_id' => 'nullable|uuid|exists:events,id', 'event_ids' => 'nullable|array|max:50', 'event_ids.*' => 'uuid|distinct', 'name' => 'required|string|max:120', 'station' => 'nullable|string|max:120', 'point_code' => ['required', Rule::in(array_keys(PostCapabilities::points()))]]);
        $device = $devices->create($data['event_id'] ?? null, $data['name'], $data['station'] ?? null, $r->user(), $data['point_code']);
        if (! empty($data['event_ids'])) {
            $devices->authorize($device, array_values(array_unique([...$data['event_ids'], ...($device->event_id ? [$device->event_id] : [])])), $r->user());
        }

        return back();
    }

    /** Activités autorisées pour la tablette (liste complète à enregistrer). */
    public function activities(Request $r, Device $device, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $r->validate(['event_ids' => 'present|array|max:50', 'event_ids.*' => 'uuid|distinct']);

        return $this->attempt($device, fn () => $devices->authorize($device, $data['event_ids'], $r->user()));
    }

    /** Activité en cours de la tablette, parmi les activités autorisées. */
    public function current(Request $r, Device $device, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $r->validate(['event_id' => 'required|uuid']);

        return $this->attempt($device, fn () => $devices->setCurrent($device, $data['event_id'], $r->user()));
    }

    public function code(Request $r, Device $device, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');

        return $this->attempt($device, fn () => session()->flash('enrol_code', ['device_id' => $device->id, 'code' => $devices->issueCode($device, $r->user())]));
    }

    /** Un refus métier s'affiche dans la page de la tablette concernée (jamais en JSON brut dans une fenêtre). */
    private function attempt(Device $device, \Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (DeskRefused $e) {
            return back()->withErrors(['poste_'.$device->id => $e->getMessage()]);
        }

        return back();
    }

    public function revoke(Request $r, Device $device, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $devices->revoke($device, $r->user());

        return back();
    }

    public function settings(Request $r): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $r->validate([
            // Le code PIN n'existe plus dans l'espace Agent : « inactivité » et « échecs de PIN » ne sont plus demandés (acceptés s'ils sont envoyés, sans effet sur l'écran).
            'inactivity' => 'sometimes|integer|min:1|max:60', 'skew' => 'required|integer|min:30|max:3600', 'pin' => 'sometimes|integer|min:3|max:30',
            // Alertes du contrôle d'accès (audit global B2, B3) : facultatifs pour rester compatibles avec l'ancien formulaire.
            'identityDocument' => 'sometimes|boolean',
            'pointsDelay' => 'sometimes|integer|min:1|max:3600', 'burstCount' => 'sometimes|integer|min:2|max:100', 'burstMinutes' => 'sometimes|integer|min:1|max:120',
            // Hors réseau, badges bas et tablette silencieuse (audit global F2, C1) : 0 désactive le blocage après N heures.
            'offlineCall' => 'sometimes|integer|min:1|max:240', 'offlineMaxHours' => 'sometimes|integer|min:0|max:168', 'offlineApproved' => 'sometimes|boolean', 'lowBadgesPercent' => 'sometimes|integer|min:1|max:90', 'lowBadgesMin' => 'sometimes|integer|min:0|max:1000', 'silentMinutes' => 'sometimes|integer|min:1|max:1440', 'lateMinutes' => 'sometimes|integer|min:1|max:1440', 'escalationMinutes' => 'sometimes|integer|min:1|max:120',
        ]);
        // S02 : le délai hors ligne n'est jamais « illimité ». Il n'a d'effet que s'il est strictement positif ET approuvé ; modifier le délai retire l'approbation
        // tant qu'elle n'est pas redonnée explicitement dans la même demande.
        $hoursBefore = (int) DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->value('value');
        $hoursAfter = (int) ($data['offlineMaxHours'] ?? $hoursBefore);
        $approve = array_key_exists('offlineApproved', $data) ? (bool) $data['offlineApproved'] : ($hoursAfter === $hoursBefore && DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->value('value') === '1');
        if ($approve && $hoursAfter < 1) {
            throw ValidationException::withMessages(['offlineApproved' => 'Une durée d’au moins une heure est nécessaire pour approuver les décisions hors ligne.']);
        }
        DB::transaction(function () use ($data, $approve): void {
            DB::table('settings')->updateOrInsert(['key' => 'poste_hors_reseau_approuve'], ['value' => $approve ? '1' : '0']);
            if (isset($data['inactivity'])) {
                DB::table('settings')->where('key', 'poste_inactivite_minutes')->update(['value' => (string) $data['inactivity']]);
            }
            DB::table('settings')->where('key', 'poste_ecart_horloge_secondes')->update(['value' => (string) $data['skew']]);
            if (isset($data['pin'])) {
                DB::table('settings')->where('key', 'poste_echecs_pin_max')->update(['value' => (string) $data['pin']]);
            }
            if (isset($data['identityDocument'])) {
                DB::table('settings')->where('key', 'identite_piece_active')->update(['value' => $data['identityDocument'] ? '1' : '0']);
            }
            $control = [];
            foreach (['pointsDelay' => 'controle_delai_min_points', 'burstCount' => 'controle_rafale_nombre', 'burstMinutes' => 'controle_rafale_minutes', 'offlineCall' => 'poste_delai_appel_minutes', 'offlineMaxHours' => 'poste_hors_reseau_max_heures', 'lowBadgesPercent' => 'poste_badges_bas_pourcent', 'lowBadgesMin' => 'poste_badges_bas_minimum', 'silentMinutes' => 'pilotage_poste_silencieux_minutes', 'lateMinutes' => 'visite_retard_minutes', 'escalationMinutes' => 'alerte_escalade_minutes'] as $field => $key) {
                if (isset($data[$field])) {
                    DB::table('settings')->where('key', $key)->update(['value' => (string) $data[$field]]);
                    $control[$key] = (int) $data[$field];
                }
            }
            app(Audit::class)->append('poste.reglage_modifie', auth()->id(), ['piece_identite' => isset($data['identityDocument']) ? (bool) $data['identityDocument'] : null, 'inactivite_minutes' => (int) $data['inactivity'], 'ecart_horloge_s' => (int) $data['skew'], 'echecs_pin_max' => (int) $data['pin'], 'hors_ligne_approuve' => $approve] + $control);
        });

        return back();
    }
}
