<?php

namespace App\Services\Poste;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Copie de travail envoyée à la tablette : uniquement ce dont le poste a besoin, jamais de photo ni de jeton QR en clair. */
final class ListSnapshot
{
    public function version(Device $poste): string
    {
        $e = $poste->event_id;
        $row = DB::selectOne("SELECT concat_ws('|',
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM visitors WHERE event_id = ?),
            (SELECT count(*) || ':' || coalesce(md5(string_agg(code || status, ',' ORDER BY code)), '') FROM badges WHERE event_id = ?),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM badge_assignments WHERE event_id = ?),
            (SELECT count(*) FROM identity_checks i JOIN visitors v ON v.id = i.visitor_id WHERE v.event_id = ?),
            (SELECT count(*) FROM photos p JOIN visitors v ON v.id = p.visitor_id WHERE v.event_id = ?),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM access_events WHERE event_id = ? AND type <> 'controle'),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM categories WHERE event_id = ?),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM zones WHERE event_id = ?),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM hosts WHERE event_id = ?),
            (SELECT count(*) FROM category_zone WHERE event_id = ?),
            (SELECT count(*) || ':' || coalesce(max(updated_at)::text, '') FROM devices WHERE event_id = ?),
            ?::text) AS v", [$e, $e, $e, $e, $e, $e, $e, $e, $e, $e, $e, $poste->id]);

        return sha1((string) $row->v);
    }

    /** @return array<string, mixed> */
    public function build(Device $poste): array
    {
        $e = $poste->event_id;
        $setting = fn (string $key, int $default): int => (int) (DB::table('settings')->where('key', $key)->value('value') ?? $default);
        $visitors = DB::select("SELECT v.id, v.full_name, v.organisation, v.host_id, v.category_id, v.valid_from, v.valid_until, v.source, v.status, v.host_validation, v.access_note, v.updated_at AS rev,
            (v.status IS DISTINCT FROM 'sorti' AND v.status IS DISTINCT FROM 'refuse' AND EXISTS (SELECT 1 FROM badge_assignments x WHERE x.visitor_id = v.id AND x.cancelled_at IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM badge_assignments x WHERE x.visitor_id = v.id AND x.returned_at IS NULL AND x.cancelled_at IS NULL)) AS a_rebadger,
            EXISTS (SELECT 1 FROM identity_checks i WHERE i.visitor_id = v.id) AS identity_done,
            EXISTS (SELECT 1 FROM photos p WHERE p.visitor_id = v.id) AS photo_done,
            EXISTS (SELECT 1 FROM access_events a WHERE a.visitor_id = v.id AND a.type = 'validation') AS validated,
            (SELECT b.code FROM badge_assignments x JOIN badges b ON b.id = x.badge_id WHERE x.visitor_id = v.id AND x.returned_at IS NULL AND x.cancelled_at IS NULL LIMIT 1) AS badge_code
            FROM visitors v WHERE v.event_id = ? ORDER BY v.full_name, v.id", [$e]);
        $iso = fn (?string $value): ?string => $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');

        return [
            'version' => $this->version($poste),
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'poste' => ['id' => $poste->id, 'name' => $poste->name, 'station' => $poste->station, 'event_id' => $e],
            'event' => DB::table('events')->where('id', $e)->first(['id', 'name', 'starts_at', 'ends_at']),
            'settings' => ['inactivity_minutes' => $setting('poste_inactivite_minutes', 5), 'clock_skew_seconds' => $setting('poste_ecart_horloge_secondes', 300), 'pin_max_failures' => $setting('poste_echecs_pin_max', 15)],
            'categories' => DB::table('categories')->where('event_id', $e)->orderBy('name')->get(['id', 'name', 'color', 'priority_circuit']),
            'zones' => DB::table('zones')->where('event_id', $e)->orderBy('name')->get(['id', 'name', 'est_accueil']),
            'category_zone' => DB::table('category_zone')->where('event_id', $e)->get(['category_id', 'zone_id']),
            'hosts' => DB::table('hosts')->where('event_id', $e)->orderBy('name')->get(['id', 'name', 'service']),
            'visitors' => array_map(fn ($v) => ['id' => $v->id, 'full_name' => $v->full_name, 'organisation' => $v->organisation, 'host_id' => $v->host_id, 'category_id' => $v->category_id, 'valid_from' => $iso($v->valid_from), 'valid_until' => $iso($v->valid_until), 'source' => $v->source, 'status' => $v->status ?? 'attendu', 'host_validation' => $v->host_validation, 'access_note' => $v->access_note, 'a_rebadger' => (bool) $v->a_rebadger, 'rev' => $iso($v->rev), 'identity_done' => (bool) $v->identity_done, 'photo_done' => (bool) $v->photo_done, 'validated' => (bool) $v->validated, 'badge_code' => $v->badge_code], $visitors),
            'postes' => DB::table('devices')->where('event_id', $e)->orderBy('name')->get(['id', 'name', 'station'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->station ?? $d->name])->all(),
            // Les jetons QR ne quittent jamais le serveur en clair : la tablette compare le hachage du jeton scanné. Tous les badges de l'événement
            // sont copiés pour le contrôle hors ligne ; `mine` indique ceux que ce poste peut associer (réservés à lui).
            'badges' => DB::table('badges')->where('event_id', $e)->orderBy('code')->get(['code', 'qr_token', 'category_id', 'status', 'reserved_device_id'])->map(fn ($b) => ['code' => $b->code, 'token_hash' => hash('sha256', $b->qr_token), 'category_id' => $b->category_id, 'status' => $b->status, 'mine' => $b->reserved_device_id === $poste->id])->all(),
        ];
    }
}
