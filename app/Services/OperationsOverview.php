<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Vue globale du site (V1, chantier Opérations) : LECTURE SEULE, toutes activités et contexte permanent confondus.
 *
 * Aucune table, aucun moteur, aucune règle : les chiffres sont calculés à partir des événements réels (`access_events`, `refusals`, `alerts`, `checkpoints`, `devices`)
 * et des services existants (Presence pour les présents, Pilotage pour le silence des postes). Une activité n'est qu'un contexte de consultation : les passages des titulaires
 * permanents et des personnes sans activité ouverte ne disparaissent donc jamais de cette vue. Aucun nom de personne : les noms restent affichés par le centre de supervision.
 */
final class OperationsOverview
{
    public function __construct(private readonly Presence $presence) {}

    /**
     * @return array{as_of: string, since: string, mouvements: array{entrees: int, sorties: int, controles: int, refus: int}, presents: array{total: int, permanents: int, activites: int},
     *     alertes: array{a_traiter: int, critiques: int}, litiges: int, postes: array{total: int, actifs: int, silencieux: int}, par_heure: list<array{heure: string, entrees: int, sorties: int}>}
     */
    public function general(): array
    {
        $now = now()->utc();
        // Fenêtre alignée sur les heures pleines : 24 tranches horaires, de la plus ancienne à l'heure en cours.
        $since = $now->copy()->startOfHour()->subHours(23);
        $sinceSql = $since->format('Y-m-d H:i:sP');

        // Mouvements des 24 dernières tranches horaires : une entrée ou une sortie corrigée n'est plus comptée (la correction est un événement distinct).
        $m = DB::selectOne(
            "SELECT count(*) FILTER (WHERE e.type = 'entree') AS entrees, count(*) FILTER (WHERE e.type = 'sortie') AS sorties, count(*) FILTER (WHERE e.type = 'controle') AS controles
             FROM access_events e WHERE e.occurred_at >= ? AND e.type IN ('entree', 'sortie', 'controle') AND NOT EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id)",
            [$sinceSql],
        );
        $refus = (int) DB::table('refusals')->where('refused_at', '>=', $sinceSql)->count();

        $permanent = 0;
        $activities = 0;
        $withEntries = DB::table('access_events')->whereIn('type', ['entree', 'sortie'])->distinct()->pluck('event_id');
        $permanentIds = DB::table('events')->where('is_permanent', true)->pluck('id')->all();
        foreach ($withEntries as $eventId) {
            $n = $this->presence->count((string) $eventId);
            if (in_array($eventId, $permanentIds, true)) {
                $permanent += $n;
            } else {
                $activities += $n;
            }
        }

        $alerts = DB::selectOne("SELECT count(*) AS n, count(*) FILTER (WHERE severity = 'critique') AS critiques FROM alerts WHERE acknowledged_at IS NULL AND resolved_at IS NULL");
        $litiges = (int) DB::selectOne('SELECT count(*) AS n FROM refusals WHERE decision IS NULL')->n;
        $checkpoints = DB::selectOne('SELECT count(*) AS total, count(*) FILTER (WHERE active) AS actifs FROM checkpoints');
        $silent = (int) DB::table('devices')->whereNotNull('token_hash')->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(Pilotage::silentAfterMinutes())))->count();

        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $hour = $since->copy()->addHours($i);
            $buckets[$hour->format('Y-m-d H')] = ['heure' => $hour->format('H'), 'entrees' => 0, 'sorties' => 0];
        }
        $rows = DB::select(
            "SELECT to_char(e.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24') AS h, e.type, count(*) AS n FROM access_events e
             WHERE e.occurred_at >= ? AND e.type IN ('entree', 'sortie') AND NOT EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id) GROUP BY 1, 2",
            [$sinceSql],
        );
        foreach ($rows as $r) {
            if (isset($buckets[$r->h])) {
                $buckets[$r->h][$r->type === 'entree' ? 'entrees' : 'sorties'] = (int) $r->n;
            }
        }

        return [
            'as_of' => $now->format('Y-m-d\TH:i:s\Z'), 'since' => $since->format('Y-m-d\TH:i:s\Z'),
            'mouvements' => ['entrees' => (int) $m->entrees, 'sorties' => (int) $m->sorties, 'controles' => (int) $m->controles, 'refus' => $refus],
            'presents' => ['total' => $permanent + $activities, 'permanents' => $permanent, 'activites' => $activities],
            'alertes' => ['a_traiter' => (int) $alerts->n, 'critiques' => (int) $alerts->critiques], 'litiges' => $litiges,
            'postes' => ['total' => (int) $checkpoints->total, 'actifs' => (int) $checkpoints->actifs, 'silencieux' => $silent],
            'par_heure' => array_values($buckets),
        ];
    }
}
