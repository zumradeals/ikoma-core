<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord GÉNÉRAL de l'administrateur (V1) : vue institutionnelle transversale, LECTURE SEULE.
 *
 * Aucun registre, aucun moteur, aucune règle : chaque bloc réutilise un service canonique (PersonRegistry pour les personnes et les habilitations, ActivityRegistry pour les activités,
 * BadgesCartesSummary pour les supports, OperationsOverview pour la situation du site, BackupStatus) ou lit directement les tables existantes pour des COMPTAGES. Les détails restent dans
 * les modules propriétaires (Opérations, Pilotage, Badges et cartes, Personnes) : chaque priorité renvoie vers sa fonction d'origine.
 *
 * Distinctions maintenues : personne enregistrée ≠ habilitation active ≠ personne présente ; badge émis ≠ imprimé ≠ remis ≠ actif ; activité ≠ poste physique.
 * Les noms de personnes ne sont communiqués qu'aux comptes qui consultent les présences.
 */
final class AdminOverview
{
    public const MAX_ACTIVITIES = 5;

    public const MAX_MOVEMENTS = 5;

    public function __construct(private readonly PersonRegistry $persons, private readonly ActivityRegistry $activities, private readonly OperationsOverview $operations,
        private readonly BadgesCartesSummary $badges, private readonly Pilotage $pilotage, private readonly BackupStatus $backup) {}

    /** @return array<string, mixed> */
    public function build(User $viewer): array
    {
        $now = now()->utc();
        $site = $this->operations->general();
        $personKpis = $this->persons->kpis();
        $activityKpis = $this->activities->kpis();
        $summary = $this->badges->get();
        $cards = $this->cards();
        $temporary = $this->temporaryBadges((int) $summary['visitors']['to_return']);
        $printing = $this->printing();
        $verifications = $this->verifications();
        $visits = $this->announcedVisits();
        $backup = $this->backup->state();

        $silent = (int) $site['postes']['appareils_silencieux'];
        $escalated = (int) DB::table('alerts')->whereNotNull('escalated_at')->whereNull('acknowledged_at')->whereNull('resolved_at')->count();

        return [
            'as_of' => $now->format('Y-m-d\TH:i:s\Z'), 'perimetre' => 'Tout le site (toutes activités et contexte permanent)',
            'donnees' => ['sauvegarde' => $backup['etat'], 'actualise' => $now->format('Y-m-d\TH:i:s\Z')],
            'indicateurs' => [
                'personnes' => ['enregistrees' => (int) $personKpis['personnes']],
                'titulaires' => ['actifs' => (int) DB::table('permanent_holders')->where('status', 'actif')->count()],
                'habilitations' => ['actives' => (int) $personKpis['habilitations_actives'], 'a_echeance' => (int) $personKpis['a_echeance'], 'jours' => (int) $personKpis['jours_echeance']],
                'activites' => ['en_cours' => (int) $activityKpis['en_cours'], 'a_venir' => (int) $activityKpis['a_venir'], 'participants_actifs' => (int) $activityKpis['participants_actifs']],
                'supports' => ['cartes_remises' => $cards['remises'], 'badges_associes' => $temporary['associes']],
                'presents' => ['total' => (int) $site['presents']['total'], 'permanents' => (int) $site['presents']['permanents'], 'activites' => (int) $site['presents']['activites']],
                'alertes' => ['a_traiter' => (int) $site['alertes']['a_traiter'], 'litiges' => (int) $site['litiges']],
            ],
            'activites' => $this->openActivities(),
            'personnes' => ['enregistrees' => (int) $personKpis['personnes'], 'actives' => (int) DB::table('persons')->where('status', 'actif')->count(), 'a_echeance' => (int) $personKpis['a_echeance'],
                'jours' => (int) $personKpis['jours_echeance'], 'retirees_ou_expirees' => (int) $personKpis['retirees_ou_expirees'], 'verifications' => $verifications],
            'badges' => ['cartes' => $cards, 'temporaires' => $temporary, 'impression' => $printing],
            'operations' => ['mouvements' => $site['mouvements'], 'presents' => $site['presents'], 'postes' => $site['postes'], 'visites_annoncees' => $visits,
                'par_heure' => $site['par_heure'], 'vehicules_presents' => (int) DB::table('vehicles')->where('status', 'present')->count(),
                'derniere_synchro' => DB::table('devices')->whereNotNull('token_hash')->whereNull('revoked_at')->max('last_synced_at'),
                'derniers' => $this->lastMovements($viewer->can('presences.consulter'))],
            'priorites' => $this->priorities($viewer, $escalated, (int) $site['litiges'], (int) $personKpis['a_echeance'], (int) $personKpis['jours_echeance'], $temporary, $cards, $printing, $silent, $verifications, $backup),
        ];
    }

    /**
     * Cartes permanentes : émise (active) ≠ imprimée ≠ remise ; retirée = révoquée, perdue, volée ou remplacée.
     *
     * @return array{actives: int, remises: int, non_remises: int, imprimees_non_remises: int, retirees: int}
     */
    private function cards(): array
    {
        $row = DB::selectOne(
            "SELECT count(*) FILTER (WHERE k.status = 'active') AS actives,
                count(*) FILTER (WHERE k.status = 'active' AND k.activated_at IS NOT NULL) AS remises,
                count(*) FILTER (WHERE k.status = 'active' AND k.activated_at IS NULL) AS non_remises,
                count(*) FILTER (WHERE k.status = 'active' AND k.activated_at IS NULL AND EXISTS (SELECT 1 FROM holder_card_prints p WHERE p.card_id = k.id)) AS imprimees_non_remises,
                count(*) FILTER (WHERE k.status <> 'active') AS retirees
             FROM holder_cards k",
        );

        return ['actives' => (int) $row->actives, 'remises' => (int) $row->remises, 'non_remises' => (int) $row->non_remises, 'imprimees_non_remises' => (int) $row->imprimees_non_remises, 'retirees' => (int) $row->retirees];
    }

    /** @return array{associes: int, non_restitues: int} */
    private function temporaryBadges(int $toReturn): array
    {
        $associated = (int) DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false)->where('b.status', 'Associé')->count();

        return ['associes' => $associated, 'non_restitues' => $toReturn];
    }

    /**
     * Supports ÉMIS par le contrat canonique mais jamais imprimés (préparation à terminer). Un badge imprimé n'est pas pour autant remis ni activé.
     *
     * @return array{emis_non_imprimes: int}
     */
    private function printing(): array
    {
        $temporary = (int) DB::selectOne(
            "SELECT count(*) AS n FROM badge_issuances i JOIN badges b ON b.id = i.badge_id
             WHERE i.support_type = 'badge_temporaire' AND i.purpose IN ('emission', 'remplacement') AND b.status IN ('Disponible', 'Associé')
               AND NOT EXISTS (SELECT 1 FROM badge_print_items p WHERE p.badge_id = i.badge_id)",
        )->n;
        $cards = (int) DB::selectOne(
            "SELECT count(*) AS n FROM holder_cards k WHERE k.status = 'active' AND NOT EXISTS (SELECT 1 FROM holder_card_prints p WHERE p.card_id = k.id)
               AND NOT EXISTS (SELECT 1 FROM badge_print_items q WHERE q.badge_id = k.badge_id)",
        )->n;

        return ['emis_non_imprimes' => $temporary + $cards];
    }

    /**
     * Dossiers à vérifier (jamais corrigés ici) : noms identiques signalés, titulaires sans catégorie institutionnelle ou sans photo (la carte ne peut pas être préparée).
     *
     * @return array{homonymes: int, titulaires_sans_categorie: int, titulaires_sans_photo: int}
     */
    private function verifications(): array
    {
        return [
            'homonymes' => (int) DB::selectOne("SELECT count(*) AS n FROM (SELECT public.ikoma_normalize(full_name) AS k FROM persons WHERE status = 'actif' GROUP BY 1 HAVING count(*) > 1) x")->n,
            'titulaires_sans_categorie' => (int) DB::table('permanent_holders')->where('status', 'actif')->whereNull('institutional_category')->count(),
            'titulaires_sans_photo' => (int) DB::table('permanent_holders')->where('status', 'actif')->whereNull('photo_file')->count(),
        ];
    }

    /** @return array{total: int, en_retard: int} */
    private function announcedVisits(): array
    {
        $base = DB::table('visitors as v')->join('events as e', 'e.id', '=', 'v.event_id')->whereNotNull('v.expected_at')->whereNull('v.withdrawn_at')->whereNull('v.closed_at')->whereNull('v.no_show_at')
            ->where(fn ($q) => $q->whereNull('v.status')->orWhere('v.status', 'attendu'))->where('e.is_permanent', false)->where(fn ($q) => $q->whereNull('e.status')->orWhereNotIn('e.status', ['clos', 'archive']));
        $late = (clone $base)->where('v.expected_at', '<', now()->subMinutes(Visits::lateAfterMinutes()))->count();

        return ['total' => (int) $base->count(), 'en_retard' => (int) $late];
    }

    /**
     * Activités ouvertes ou à venir : effectifs, attendus, arrivées confirmées (entrées non corrigées), phase dérivée des dates et du statut.
     *
     * @return list<array<string, mixed>>
     */
    private function openActivities(): array
    {
        $rows = array_values(array_filter($this->activities->rows(false), fn (array $r): bool => in_array($r['phase'], ['en_cours', 'a_venir'], true) && ! $r['is_permanent']));
        usort($rows, fn (array $a, array $b): int => [$a['phase'] === 'en_cours' ? 0 : 1, $a['starts_at']] <=> [$b['phase'] === 'en_cours' ? 0 : 1, $b['starts_at']]);
        $out = [];
        foreach (array_slice($rows, 0, self::MAX_ACTIVITIES) as $r) {
            $c = $this->pilotage->counters((string) $r['id']);
            $out[] = ['id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'site' => $r['site'], 'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'phase' => $r['phase'],
                'participants' => (int) $r['participants'], 'attendus' => (int) $c['attendus'], 'arrivees' => (int) $c['arrives'], 'presents' => (int) $c['presents']];
        }

        return $out;
    }

    /**
     * Derniers mouvements CONFIRMÉS (entrées, sorties, contrôles non corrigés) et derniers refus, tous contextes. Le nom n'est communiqué qu'à qui consulte les présences.
     *
     * @return list<array{at: string, type: string, checkpoint: ?string, context: string, name: ?string, resultat: string}>
     */
    private function lastMovements(bool $withNames): array
    {
        $rows = DB::table('access_events as e')->join('visitors as v', 'v.id', '=', 'e.visitor_id')->join('events as ev', 'ev.id', '=', 'e.event_id')
            ->whereIn('e.type', ['entree', 'sortie', 'controle'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as c')->whereColumn('c.corrige_id', 'e.id'))
            ->orderByDesc('e.occurred_at')->orderByDesc('e.seq')->limit(self::MAX_MOVEMENTS)->get(['e.occurred_at', 'e.type', 'e.checkpoint', 'ev.name as context', 'ev.is_permanent', 'v.full_name']);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['at' => (string) $r->occurred_at, 'type' => (string) $r->type, 'checkpoint' => $r->checkpoint, 'context' => $r->is_permanent ? 'Contexte permanent' : (string) $r->context, 'name' => $withNames ? $r->full_name : null, 'resultat' => 'autorise'];
        }
        $refusals = DB::table('refusals as r')->join('visitors as v', 'v.id', '=', 'r.visitor_id')->join('events as ev', 'ev.id', '=', 'v.event_id')
            ->orderByDesc('r.refused_at')->limit(self::MAX_MOVEMENTS)->get(['r.refused_at', 'ev.name as context', 'ev.is_permanent', 'v.full_name']);
        foreach ($refusals as $r) {
            $out[] = ['at' => (string) $r->refused_at, 'type' => 'refus', 'checkpoint' => null, 'context' => $r->is_permanent ? 'Contexte permanent' : (string) $r->context, 'name' => $withNames ? $r->full_name : null, 'resultat' => 'refuse'];
        }
        usort($out, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($out, 0, self::MAX_MOVEMENTS);
    }

    /**
     * Situations qui demandent réellement une intervention administrative : chacune a un état réel, un nombre et un lien vers sa fonction d'origine (null si le compte n'y a pas droit).
     *
     * @param  array{associes: int, non_restitues: int}  $temporary
     * @param  array{actives: int, remises: int, non_remises: int, imprimees_non_remises: int, retirees: int}  $cards
     * @param  array{emis_non_imprimes: int}  $printing
     * @param  array{homonymes: int, titulaires_sans_categorie: int, titulaires_sans_photo: int}  $verifications
     * @param  array<string, mixed>  $backup
     * @return list<array{key: string, severity: string, title: string, text: string, count: int, status: string, href: ?string}>
     */
    private function priorities(User $viewer, int $escalated, int $litiges, int $expiring, int $days, array $temporary, array $cards, array $printing, int $silent, array $verifications, array $backup): array
    {
        $rows = [];
        $add = function (string $key, string $severity, string $title, string $text, int $count, string $status, ?string $href) use (&$rows): void {
            if ($count > 0) {
                $rows[] = ['key' => $key, 'severity' => $severity, 'title' => $title, 'text' => $text, 'count' => $count, 'status' => $status, 'href' => $href];
            }
        };
        $can = fn (string $permission, string $href): ?string => $viewer->can($permission) ? $href : null;

        if (in_array($backup['etat'], ['jamais', 'ancienne', 'echec'], true)) {
            $rows[] = ['key' => 'sauvegarde', 'severity' => 'haute', 'title' => 'Sauvegarde à vérifier', 'count' => 1, 'status' => 'À vérifier', 'href' => '/administration',
                'text' => match ($backup['etat']) {
                    'echec' => 'La dernière sauvegarde a échoué. Prévenez la personne qui exploite le serveur.',
                    'jamais' => 'Aucune sauvegarde n’a encore réussi. Prévenez la personne qui exploite le serveur.',
                    default => 'La dernière sauvegarde réussie date de plus de '.$backup['seuil_heures'].' heures.',
                }];
        }
        $add('alertes_escaladees', 'haute', 'Alertes de contrôle sans prise en compte', 'Le superviseur ne les a pas prises en compte dans le délai prévu.', $escalated, 'Escaladées', $can('alertes.consulter', '/pilotage/alertes'));
        $add('litiges', 'haute', 'Cas à décider (accès refusés)', 'La décision appartient au superviseur.', $litiges, 'En attente de décision', $can('litiges.decider', '/pilotage/litiges'));
        $add('synchronisation', 'haute', 'Appareils sans nouvelles', 'Aucune synchronisation depuis plus longtemps que le délai prévu : situation confirmée, pas une simple absence de réseau.', $silent, 'Silencieux', $can('postes.gerer', '/administration/postes'));
        $add('habilitations_expirent', 'normale', 'Habilitations arrivant à expiration', 'Dans les '.$days.' prochains jours : à prolonger ou laisser expirer explicitement.', $expiring, 'À échéance', $can('listes.importer', '/administration/personnes'));
        $add('badges_non_restitues', 'normale', 'Badges temporaires non restitués', 'La personne est sortie ou sa participation est clôturée : une sortie ou une clôture ne vaut jamais restitution.', $temporary['non_restitues'], 'À récupérer', $can('badges.gerer', '/administration/badges/registre'));
        $add('supports_non_imprimes', 'normale', 'Supports émis, jamais imprimés', 'Préparation à terminer : un support généré n’est ni imprimé, ni remis, ni activé.', $printing['emis_non_imprimes'], 'À imprimer', $can('badges.gerer', '/administration/badges'));
        $add('cartes_non_remises', 'info', 'Cartes permanentes non remises', 'La carte n’ouvre rien tant qu’elle n’est pas remise et activée.', $cards['non_remises'], 'Non remises', $can('badges.gerer', '/administration/atelier?mode=cartes'));
        $add('titulaires_incomplets', 'info', 'Titulaires sans catégorie institutionnelle', 'Leur carte ne peut pas être préparée.', $verifications['titulaires_sans_categorie'], 'À compléter', $can('listes.importer', '/administration/titulaires'));
        $add('homonymes', 'info', 'Noms identiques dans le registre', 'Jamais fusionnés automatiquement : à distinguer avant d’inscrire ou d’imprimer.', $verifications['homonymes'], 'À vérifier', $can('listes.importer', '/administration/personnes'));

        return $rows;
    }
}
