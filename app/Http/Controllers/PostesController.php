<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use App\Services\Poste\Devices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Administration > Postes : création, code d'enrôlement à usage unique, révocation, réglages et conflits de synchronisation. */
final class PostesController
{
    public function index(): Response
    {
        Gate::authorize('postes.gerer');
        $postes = DB::table('devices as d')->join('events as e', 'e.id', '=', 'd.event_id')->orderBy('e.name')->orderBy('d.name')->get(['d.id', 'd.name', 'd.station', 'd.event_id', 'e.name as event_name', 'd.token_hash', 'd.revoked_at', 'd.enrolled_at', 'd.last_synced_at', 'd.enrollment_expires_at'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'station' => $d->station, 'event_id' => $d->event_id, 'event_name' => $d->event_name, 'state' => $d->revoked_at ? 'revoque' : ($d->token_hash ? 'enrole' : 'non_enrole'), 'enrolled_at' => $d->enrolled_at, 'last_synced_at' => $d->last_synced_at, 'code_pending' => $d->enrollment_expires_at !== null && $d->enrollment_expires_at > now()->toDateTimeString()]);
        $counts = DB::table('sync_operations')->selectRaw('device_id, count(*) AS received, count(*) FILTER (WHERE status_code = 200) AS applied, count(*) FILTER (WHERE status_code IS NOT NULL AND status_code <> 200) AS conflicts')->groupBy('device_id')->get()->keyBy('device_id');
        $postes = $postes->map(fn ($p) => [...$p, 'received' => (int) ($counts[$p['id']]->received ?? 0), 'applied' => (int) ($counts[$p['id']]->applied ?? 0), 'conflicts' => (int) ($counts[$p['id']]->conflicts ?? 0)]);
        $setting = fn (string $key) => (int) DB::table('settings')->where('key', $key)->value('value');

        return Inertia::render('Admin/Postes', [
            'events' => DB::table('events')->orderBy('name')->get(['id', 'name']),
            'postes' => $postes,
            'settings' => ['inactivity' => $setting('poste_inactivite_minutes'), 'skew' => $setting('poste_ecart_horloge_secondes'), 'pin' => $setting('poste_echecs_pin_max'), 'pointsDelay' => $setting('controle_delai_min_points'), 'burstCount' => $setting('controle_rafale_nombre'), 'burstMinutes' => $setting('controle_rafale_minutes')],
            'newCode' => session()->pull('enrol_code'),
            'conflicts' => DB::table('sync_conflicts as c')->join('devices as d', 'd.id', '=', 'c.device_id')->where('c.status', 'ouvert')->orderByDesc('c.created_at')->limit(50)->get(['c.id', 'c.kind', 'c.reason', 'c.operation_type', 'c.created_at', 'd.name as device_name']),
        ]);
    }

    public function store(Request $r, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');
        $data = $r->validate(['event_id' => 'required|uuid|exists:events,id', 'name' => 'required|string|max:120', 'station' => 'nullable|string|max:120']);
        $devices->create($data['event_id'], $data['name'], $data['station'] ?? null, $r->user());

        return back();
    }

    public function code(Request $r, Device $device, Devices $devices): RedirectResponse
    {
        Gate::authorize('postes.gerer');

        return back()->with('enrol_code', ['device_id' => $device->id, 'code' => $devices->issueCode($device, $r->user())]);
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
            'inactivity' => 'required|integer|min:1|max:60', 'skew' => 'required|integer|min:30|max:3600', 'pin' => 'required|integer|min:3|max:30',
            // Alertes du contrôle d'accès (audit global B2, B3) : facultatifs pour rester compatibles avec l'ancien formulaire.
            'pointsDelay' => 'sometimes|integer|min:1|max:3600', 'burstCount' => 'sometimes|integer|min:2|max:100', 'burstMinutes' => 'sometimes|integer|min:1|max:120',
        ]);
        DB::transaction(function () use ($data): void {
            DB::table('settings')->where('key', 'poste_inactivite_minutes')->update(['value' => (string) $data['inactivity']]);
            DB::table('settings')->where('key', 'poste_ecart_horloge_secondes')->update(['value' => (string) $data['skew']]);
            DB::table('settings')->where('key', 'poste_echecs_pin_max')->update(['value' => (string) $data['pin']]);
            $control = [];
            foreach (['pointsDelay' => 'controle_delai_min_points', 'burstCount' => 'controle_rafale_nombre', 'burstMinutes' => 'controle_rafale_minutes'] as $field => $key) {
                if (isset($data[$field])) {
                    DB::table('settings')->where('key', $key)->update(['value' => (string) $data[$field]]);
                    $control[$key] = (int) $data[$field];
                }
            }
            app(Audit::class)->append('poste.reglage_modifie', auth()->id(), ['inactivite_minutes' => (int) $data['inactivity'], 'ecart_horloge_s' => (int) $data['skew'], 'echecs_pin_max' => (int) $data['pin']] + $control);
        });

        return back();
    }
}
