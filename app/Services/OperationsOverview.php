<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Vue globale du site (V1, chantier Opérations) : LECTURE SEULE, toutes activités et contexte permanent confondus.
 *
 * Aucune table, aucun moteur, aucune règle : les chiffres sont calculés à partir des événements réels (`access_events`, `refusals`, `alerts`, `checkpoints`, `devices`)
 * et des services existants (Presence pour les compteurs par contexte, Pilotage pour le délai de silence des appareils). Le total des présents compte des identités DISTINCTES. Une activité n'est qu'un contexte de consultation : les passages des titulaires
 * permanents et des personnes sans activité ouverte ne disparaissent donc jamais de cette vue. Aucun nom de personne : les noms restent affichés par le centre de supervision.
 */
final class OperationsOverview
{
    public function __construct(private readonly Presence $presence) {}

    /**
     * @return array{as_of: string, since: string, mouvements: array{entrees: int, sorties: int, controles: int, refus: int},
     *     presents: array{total: int, permanents: int, activites: int, contextes: list<array{event_id: string, name: string, is_permanent: bool, presents: int}>, multi_contextes: int},
     *     alertes: array{a_traiter: int, critiques: int}, litiges: int, postes: array{total: int, actifs: int, appareils_enroles: int, appareils_synchronises: int, appareils_silencieux: int},
     *     par_heure: list<array{heure: string, entrees: int, sorties: int}>}
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

        // Présence PHYSIQUE : une identité (personne canonique) est présente si son dernier mouvement confirmé et non corrigé, tous contextes confondus, est une entrée.
        // Une même personne présente dans plusieurs activités, ou dans le contexte permanent et une activité, n'est comptée qu'UNE fois.
        $physical = DB::selectOne(
            "SELECT count(*) FILTER (WHERE l.type = 'entree') AS total, count(*) FILTER (WHERE l.type = 'entree' AND ev.is_permanent) AS permanents
             FROM (
                SELECT DISTINCT ON (COALESCE(v.person_id, v.id)) COALESCE(v.person_id, v.id) AS ident, e.type, e.event_id
                FROM access_events e JOIN visitors v ON v.id = e.visitor_id
                WHERE e.type IN ('entree', 'sortie') AND NOT EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id)
                ORDER BY COALESCE(v.person_id, v.id), e.occurred_at DESC, e.seq DESC
             ) l JOIN events ev ON ev.id = l.event_id",
        );
        $physicalTotal = (int) $physical->total;
        $physicalPermanent = (int) $physical->permanents;

        // Compteurs PAR CONTEXTE (chaque activité et le contexte permanent comptent leurs propres présents) : indicateurs distincts, jamais additionnés pour obtenir le total du site.
        $contexts = [];
        $contextSum = 0;
        foreach (DB::table('events')->whereIn('id', DB::table('access_events')->whereIn('type', ['entree', 'sortie'])->select('event_id'))->orderByDesc('is_permanent')->orderBy('name')->get(['id', 'name', 'is_permanent']) as $event) {
            $n = $this->presence->count((string) $event->id);
            $contextSum += $n;
            if ($n > 0) {
                $contexts[] = ['event_id' => (string) $event->id, 'name' => (string) $event->name, 'is_permanent' => (bool) $event->is_permanent, 'presents' => $n];
            }
        }

        $alerts = DB::selectOne("SELECT count(*) AS n, count(*) FILTER (WHERE severity = 'critique') AS critiques FROM alerts WHERE acknowledged_at IS NULL AND resolved_at IS NULL");
        $litiges = (int) DB::selectOne('SELECT count(*) AS n FROM refusals WHERE decision IS NULL')->n;
        // Postes : « actif » est un état ADMINISTRATIF du poste ; la connexion se prouve par la synchronisation récente des appareils (délai existant de Pilotage).
        $checkpoints = DB::selectOne('SELECT count(*) AS total, count(*) FILTER (WHERE active) AS actifs FROM checkpoints');
        $enrolled = DB::table('devices')->whereNotNull('token_hash')->whereNull('revoked_at');
        $silent = (int) (clone $enrolled)->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(Pilotage::silentAfterMinutes())))->count();
        $enrolledCount = (int) (clone $enrolled)->count();

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
            'presents' => ['total' => $physicalTotal, 'permanents' => $physicalPermanent, 'activites' => $physicalTotal - $physicalPermanent, 'contextes' => $contexts, 'multi_contextes' => max(0, $contextSum - $physicalTotal)],
            'alertes' => ['a_traiter' => (int) $alerts->n, 'critiques' => (int) $alerts->critiques], 'litiges' => $litiges,
            'postes' => ['total' => (int) $checkpoints->total, 'actifs' => (int) $checkpoints->actifs, 'appareils_enroles' => $enrolledCount, 'appareils_synchronises' => $enrolledCount - $silent, 'appareils_silencieux' => $silent],
            'par_heure' => array_values($buckets),
        ];
    }
}
