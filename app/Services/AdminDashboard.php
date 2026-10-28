<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord de l'administrateur : où en est la préparation de l'événement, quelques chiffres, ce qui demande un regard et les dernières actions.
 * Lecture seule. Aucun nom de personne n'en sort : seuls des nombres, des noms de postes et des libellés d'actions.
 */
final class AdminDashboard
{
    /** Rôles qui doivent avoir au moins un compte actif pour que l'événement puisse se dérouler. */
    private const ROLES = ['agent_accueil' => 'agent d’accueil', 'superviseur' => 'superviseur', 'hote' => 'hôte', 'encadreur' => 'encadreur'];

    private const CODE_EXPIRES_WITHIN_MINUTES = 15;

    public function __construct(private readonly Pilotage $pilotage) {}

    /** @return array<string, mixed> */
    public function build(?string $requestedEvent): array
    {
        $event = $this->pilotage->event($requestedEvent);
        $events = DB::table('events')->orderByDesc('starts_at')->get(['id', 'name'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->all();
        if ($event === null) {
            return ['event' => null, 'events' => $events, 'steps' => [], 'summary' => ['done' => 0, 'total' => 0, 'next' => null], 'tiles' => null, 'watch' => $this->profileWatch(), 'recent' => $this->recent()];
        }
        $e = $event->id;
        $zones = (int) DB::table('zones')->where('event_id', $e)->count();
        $categories = (int) DB::table('categories')->where('event_id', $e)->count();
        $visitors = (int) DB::table('visitors')->where('event_id', $e)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'refuse'))->count();
        $inCars = (int) DB::table('visitors')->where('event_id', $e)->whereNotNull('group_id')->count();
        $badges = DB::selectOne("SELECT count(*) AS total,
            count(*) FILTER (WHERE status = 'Disponible' AND reserved_device_id IS NULL AND reserved_group_id IS NULL) AS free,
            count(*) FILTER (WHERE reserved_device_id IS NOT NULL) AS for_devices,
            count(*) FILTER (WHERE reserved_group_id IS NOT NULL) AS for_groups,
            count(*) FILTER (WHERE category_id IS NULL AND status = 'Disponible') AS no_category
            FROM badges WHERE event_id = ?", [$e]);
        $devices = DB::selectOne('SELECT count(*) AS total,
            count(*) FILTER (WHERE token_hash IS NOT NULL AND revoked_at IS NULL) AS enrolled
            FROM devices WHERE event_id = ?', [$e]);
        $groups = DB::selectOne('SELECT count(*) AS total,
            count(*) FILTER (WHERE supervisor_id IS NULL) AS no_supervisor,
            count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM badges b WHERE b.reserved_group_id = groups.id)) AS no_badges
            FROM groups WHERE event_id = ?', [$e]);
        $missingRoles = array_keys(array_filter(self::ROLES, fn (string $label, string $role) => ! User::query()->role($role)->where('active', true)->exists(), ARRAY_FILTER_USE_BOTH));
        $activeUsers = (int) User::query()->where('active', true)->count();

        $steps = [
            $this->step('zones', 'Zones et catégories', $zones > 0 && $categories > 0 ? 'fait' : ($zones + $categories > 0 ? 'en_cours' : 'a_faire'),
                $zones.' zone'.($zones > 1 ? 's' : '').' · '.$categories.' catégorie'.($categories > 1 ? 's' : ''), '/administration/zones'),
            $this->step('liste', 'Liste d’invités', $visitors > 0 ? 'fait' : 'a_faire',
                $visitors > 0 ? $visitors.' invité'.($visitors > 1 ? 's' : '').' dans la liste' : 'Aucun invité pour le moment', '/administration/imports'),
            $this->badgeStep((int) $badges->total, (int) $groups->total, (int) $groups->no_badges, (int) $badges->for_devices),
            $this->step('tablettes', 'Tablettes', (int) $devices->total > 0 && (int) $devices->enrolled === (int) $devices->total ? 'fait' : 'a_faire',
                (int) $devices->total === 0 ? 'Aucune tablette créée' : (int) $devices->enrolled.' branchée'.((int) $devices->enrolled > 1 ? 's' : '').' sur '.(int) $devices->total.((int) $devices->total - (int) $devices->enrolled > 0 ? ' · il manque un code d’enrôlement' : ''), '/administration/postes'),
            $this->step('groupes', 'Groupes et cars', (int) $groups->total === 0 ? 'a_faire' : ((int) $groups->no_supervisor > 0 ? 'en_cours' : 'fait'),
                (int) $groups->total === 0 ? 'Aucun groupe (à créer s’il y a des cars)' : (int) $groups->total.' car'.((int) $groups->total > 1 ? 's' : '').((int) $groups->no_supervisor > 0 ? ' · '.(int) $groups->no_supervisor.' sans encadreur' : ' · un encadreur par car'), '/administration/groupes'),
            $this->step('comptes', 'Comptes', $missingRoles === [] ? 'fait' : (count($missingRoles) < count(self::ROLES) ? 'en_cours' : 'a_faire'),
                $missingRoles === [] ? $activeUsers.' comptes actifs, un par rôle' : 'Il manque : '.implode(', ', array_map(fn (string $r) => self::ROLES[$r], $missingRoles)), '/administration/comptes'),
        ];
        $done = count(array_filter($steps, fn (array $s) => $s['status'] === 'fait'));
        $next = collect($steps)->first(fn (array $s) => $s['status'] !== 'fait');

        return [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'events' => $events,
            'steps' => $steps,
            'summary' => ['done' => $done, 'total' => count($steps), 'next' => $next === null ? null : ['title' => $next['title'], 'href' => $next['href'], 'text' => $next['key'] === 'badges' ? 'Réserver les badges' : 'Continuer : '.mb_strtolower($next['title'])]],
            'tiles' => [
                'invites' => ['value' => $visitors, 'caption' => $inCars > 0 ? 'dont '.$inCars.' stagiaires en car' : 'aucun car pour le moment'],
                'badges' => ['value' => (int) $badges->free, 'total' => (int) $badges->total, 'caption' => (int) $badges->for_devices.' réservés à un poste · '.(int) $badges->for_groups.' à un car'],
                'tablettes' => ['value' => (int) $devices->enrolled, 'total' => (int) $devices->total, 'caption' => (int) $devices->total - (int) $devices->enrolled.' en attente d’enrôlement'],
                'comptes' => ['value' => $activeUsers, 'caption' => $missingRoles === [] ? 'tous les rôles sont couverts' : count($missingRoles).' rôle'.(count($missingRoles) > 1 ? 's' : '').' sans compte'],
            ],
            'watch' => $this->watch($e, (int) $badges->no_category),
            'recent' => $this->recent(),
        ];
    }

    /** @return array{key:string,title:string,status:string,detail:string,href:string} */
    private function step(string $key, string $title, string $status, string $detail, string $href): array
    {
        return ['key' => $key, 'title' => $title, 'status' => $status, 'detail' => $detail, 'href' => $href];
    }

    /**
     * Badges : fabriqués, puis réservés à un poste et à chaque car.
     *
     * @return array{key:string,title:string,status:string,detail:string,href:string}
     */
    private function badgeStep(int $total, int $groups, int $groupsWithoutBadges, int $reservedForDevices): array
    {
        if ($total === 0) {
            return $this->step('badges', 'Badges', 'a_faire', 'Aucun lot de badges fabriqué', '/administration/badges');
        }
        if ($groupsWithoutBadges > 0) {
            return $this->step('badges', 'Badges', 'en_cours', $total.' fabriqués · '.$groupsWithoutBadges.' car'.($groupsWithoutBadges > 1 ? 's n’ont' : ' n’a').' pas encore ses badges', '/administration/badges');
        }
        if ($reservedForDevices === 0) {
            return $this->step('badges', 'Badges', 'en_cours', $total.' fabriqués · aucun n’est réservé à un poste d’accueil', '/administration/badges');
        }

        return $this->step('badges', 'Badges', 'fait', $total.' fabriqués · '.$reservedForDevices.' réservés à un poste'.($groups > 0 ? ' · chaque car a les siens' : ''), '/administration/badges');
    }

    /**
     * Ce qui demande un regard. Chaque ligne a une gravité en toutes lettres (jamais la couleur seule) et aucun nom de personne.
     *
     * @return list<array{severity:string,title:string,text:string,href:string}>
     */
    private function watch(string $eventId, int $badgesWithoutCategory): array
    {
        $rows = [];
        $silent = DB::table('devices')->where('event_id', $eventId)->whereNotNull('token_hash')->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(Pilotage::silentAfterMinutes())))
            ->orderBy('name')->get(['name', 'station', 'last_synced_at']);
        foreach ($silent as $d) {
            $label = $d->station ?? $d->name;
            $rows[] = ['severity' => 'haute', 'title' => 'Une tablette est silencieuse', 'text' => $d->last_synced_at === null ? '« '.$label.' » n’a jamais synchronisé.' : '« '.$label.' » n’a pas synchronisé depuis '.$this->since((string) $d->last_synced_at).'.', 'href' => '/administration/postes'];
        }
        $expiring = DB::table('devices')->where('event_id', $eventId)->whereNull('enrolled_at')->whereNotNull('enrollment_expires_at')
            ->where('enrollment_expires_at', '>', now())->where('enrollment_expires_at', '<=', now()->addMinutes(self::CODE_EXPIRES_WITHIN_MINUTES))
            ->orderBy('enrollment_expires_at')->get(['name', 'station', 'enrollment_expires_at']);
        foreach ($expiring as $d) {
            $minutes = max(1, (int) ceil((strtotime((string) $d->enrollment_expires_at) - time()) / 60));
            $rows[] = ['severity' => 'normale', 'title' => 'Un code expire bientôt', 'text' => 'Le code d’enrôlement de « '.($d->station ?? $d->name).' » expire dans '.$minutes.' minute'.($minutes > 1 ? 's' : '').'.', 'href' => '/administration/postes'];
        }
        $homonyms = (int) DB::selectOne('SELECT count(*) AS n FROM (SELECT public.ikoma_normalize(full_name) AS k FROM visitors WHERE event_id = ? GROUP BY 1 HAVING count(*) > 1) x', [$eventId])->n;
        if ($homonyms > 0) {
            $rows[] = ['severity' => 'info', 'title' => $homonyms.' nom'.($homonyms > 1 ? 's' : '').' identique'.($homonyms > 1 ? 's' : '').' dans la liste', 'text' => 'Prévoyez un second critère à l’accueil pour ne pas confondre deux personnes.', 'href' => '/administration/imports'];
        }
        if ($badgesWithoutCategory > 0) {
            $rows[] = ['severity' => 'info', 'title' => $badgesWithoutCategory.' badge'.($badgesWithoutCategory > 1 ? 's' : '').' sans catégorie', 'text' => 'Ils viennent d’anciens lots et ne peuvent pas être remis : utilisez les lots par catégorie.', 'href' => '/administration/badges'];
        }

        $rows = [...$this->profileWatch(), ...$rows];
        $escalated = (int) DB::table('alerts')->where('event_id', $eventId)->whereNotNull('escalated_at')->whereNull('acknowledged_at')->whereNull('resolved_at')->count();
        if ($escalated > 0) {
            $minutes = max(1, (int) (DB::table('settings')->where('key', 'alerte_escalade_minutes')->value('value') ?? 5));
            array_unshift($rows, ['severity' => 'haute', 'title' => $escalated.' alerte'.($escalated > 1 ? 's' : '').' sans prise en compte', 'text' => 'Le superviseur n’a pas pris en compte '.($escalated > 1 ? $escalated.' alertes' : 'une alerte').' depuis plus de '.$minutes.' minute'.($minutes > 1 ? 's' : '').'. Appelez-le.', 'href' => '/administration/journal']);
        }

        return $rows;
    }

    /**
     * Le profil « standard » ne convient pas à des données réelles : la ligne reste en tête tant que l'administrateur n'a pas choisi « renforcé ».
     *
     * @return list<array{severity:string,title:string,text:string,href:string}>
     */
    private function profileWatch(): array
    {
        return SecurityProfile::strict() ? [] : [['severity' => 'haute', 'title' => 'Profil de sécurité « standard »', 'text' => 'Ce profil convient à une démonstration avec des données fictives. Passez au profil renforcé avant toute donnée réelle.', 'href' => '/administration/profil-securite']];
    }

    private function since(string $at): string
    {
        $minutes = max(1, (int) floor((time() - strtotime($at)) / 60));

        return $minutes < 120 ? $minutes.' minutes' : (int) floor($minutes / 60).' h';
    }

    /**
     * Les dernières entrées du journal : libellé de l'action et heure, sans détail ni acteur.
     *
     * @return list<array{action:string,occurred_at:string}>
     */
    private function recent(): array
    {
        return DB::table('audit_logs')->orderByDesc('id')->limit(5)->get(['action', 'occurred_at'])->map(fn ($r) => ['action' => $r->action, 'occurred_at' => $r->occurred_at])->all();
    }
}
