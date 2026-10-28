<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Groupes de stagiaires transportés en car (document de fonctionnement, section 8). Source de vérité de l'appartenance : visitors.group_id
 * (un stagiaire, un seul groupe) ; group_members porte l'avancement de chaque stagiaire (appel, contrôle, confirmation, embarquement, arrivée).
 * Toute action est idempotente : la rejouer ne change rien. Aucun nom de personne dans le journal ni dans les alertes.
 */
final class Groups
{
    public const MODES = ['identite', 'encadreur'];

    public function __construct(private readonly Audit $audit, private readonly BadgeService $badges, private readonly Alerts $alerts) {}

    public function mode(): string
    {
        $mode = (string) DB::table('settings')->where('key', 'controle_stagiaires')->value('value');

        return in_array($mode, self::MODES, true) ? $mode : 'encadreur';
    }

    public function create(string $eventId, string $name, ?string $bus, ?string $supervisorId, User $actor): string
    {
        $this->assertSupervisor($supervisorId);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $eventId, $name, $bus, $supervisorId, $actor): void {
            DB::table('groups')->insert(['id' => $id, 'event_id' => $eventId, 'name' => mb_substr(trim($name), 0, 120), 'bus_number' => $bus !== null && trim($bus) !== '' ? mb_substr(trim($bus), 0, 60) : null, 'supervisor_id' => $supervisorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('groupe.cree', $actor->id, ['group_id' => $id, 'event_id' => $eventId, 'encadreur_id' => $supervisorId]);
        });

        return $id;
    }

    public function designate(string $groupId, ?string $supervisorId, User $actor): void
    {
        $this->assertSupervisor($supervisorId);
        DB::table('groups')->where('id', $groupId)->update(['supervisor_id' => $supervisorId, 'updated_at' => now()]);
        $this->audit->append('groupe.encadreur_designe', $actor->id, ['group_id' => $groupId, 'encadreur_id' => $supervisorId]);
    }

    private function assertSupervisor(?string $userId): void
    {
        if ($userId !== null && ! User::query()->where('id', $userId)->where('active', true)->get()->contains(fn (User $u) => $u->hasRole('encadreur'))) {
            throw new DeskRefused('encadreur_invalide', 'Le compte désigné doit être un encadreur actif.');
        }
    }

    /** Rattache un stagiaire au groupe : refusé s'il appartient déjà à un groupe (le même ou un autre). */
    public function attach(string $groupId, string $visitorId, User $actor): void
    {
        DB::transaction(function () use ($groupId, $visitorId, $actor): void {
            $group = DB::table('groups')->where('id', $groupId)->lockForUpdate()->first() ?? throw new DeskRefused('groupe_inconnu', 'Groupe introuvable.', 404);
            $visitor = DB::table('visitors')->where('id', $visitorId)->where('event_id', $group->event_id)->lockForUpdate()->first() ?? throw new DeskRefused('visiteur_inconnu', 'Visiteur introuvable pour cet événement.', 404);
            if ($visitor->group_id !== null) {
                throw new DeskRefused('deja_dans_un_groupe', $visitor->group_id === $group->id ? 'Cette personne est déjà dans ce groupe.' : 'Cette personne appartient déjà à un autre groupe.', 409);
            }
            DB::table('visitors')->where('id', $visitorId)->update(['group_id' => $groupId, 'updated_at' => now()]);
            DB::table('group_members')->insertOrIgnore(['id' => (string) Str::uuid(), 'group_id' => $groupId, 'visitor_id' => $visitorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('groupe.stagiaire_rattache', $actor->id, ['group_id' => $groupId, 'visitor_id' => $visitorId]);
        });
    }

    /** Retire un stagiaire d'un groupe, tant qu'aucun badge ne lui a été remis. */
    public function detach(string $groupId, string $visitorId, User $actor): void
    {
        DB::transaction(function () use ($groupId, $visitorId, $actor): void {
            if (DB::table('badge_assignments')->where('visitor_id', $visitorId)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
                throw new DeskRefused('badge_remis', 'Un badge a déjà été remis à cette personne : elle ne peut plus être retirée du groupe.', 409);
            }
            DB::table('group_members')->where('group_id', $groupId)->where('visitor_id', $visitorId)->delete();
            DB::table('visitors')->where('id', $visitorId)->where('group_id', $groupId)->update(['group_id' => null, 'updated_at' => now()]);
            $this->audit->append('groupe.stagiaire_retire', $actor->id, ['group_id' => $groupId, 'visitor_id' => $visitorId]);
        });
    }

    /** Crée les lignes de suivi manquantes (stagiaires rattachés par une importation, par exemple). */
    public function ensureMembers(string $groupId): void
    {
        DB::statement('INSERT INTO group_members (id, group_id, visitor_id, created_at, updated_at) SELECT gen_random_uuid(), v.group_id, v.id, now(), now() FROM visitors v WHERE v.group_id = ? AND NOT EXISTS (SELECT 1 FROM group_members m WHERE m.visitor_id = v.id) ON CONFLICT DO NOTHING', [$groupId]);
    }

    /**
     * Avancement d'un groupe pour l'agent, l'encadreur ou le superviseur.
     *
     * @return array<string, mixed>
     */
    public function state(string $groupId): array
    {
        $this->ensureMembers($groupId);
        $group = DB::table('groups')->where('id', $groupId)->first() ?? throw new DeskRefused('groupe_inconnu', 'Groupe introuvable.', 404);
        $members = DB::select(
            'SELECT m.visitor_id, v.full_name, v.organisation, m.called_at, m.checked_at, m.check_mode, m.confirmed_at, m.absent_at, m.boarded_at, m.arrived_at,
                (SELECT b.code FROM badge_assignments a JOIN badges b ON b.id = a.badge_id WHERE a.visitor_id = v.id AND a.returned_at IS NULL AND a.cancelled_at IS NULL LIMIT 1) AS badge_code
             FROM group_members m JOIN visitors v ON v.id = m.visitor_id WHERE m.group_id = ? AND v.withdrawn_at IS NULL ORDER BY v.full_name, v.id',
            [$groupId],
        );
        $total = count($members);
        $badged = count(array_filter($members, fn ($m) => $m->badge_code !== null));

        return [
            'group' => ['id' => $group->id, 'name' => $group->name, 'bus_number' => $group->bus_number, 'opened_at' => $group->opened_at, 'departed_at' => $group->departed_at, 'arrival_closed_at' => $group->arrival_closed_at],
            'members' => array_map(fn ($m) => (array) $m, $members),
            'progress' => ['total' => $total, 'badged' => $badged, 'boarded' => count(array_filter($members, fn ($m) => $m->boarded_at !== null)), 'arrived' => count(array_filter($members, fn ($m) => $m->arrived_at !== null)), 'absent' => count(array_filter($members, fn ($m) => $m->absent_at !== null)), 'complete' => $total > 0 && $badged === $total],
            'mode' => $this->mode(),
        ];
    }

    private function member(string $groupId, string $visitorId): object
    {
        $this->ensureMembers($groupId);

        return DB::table('group_members')->where('group_id', $groupId)->where('visitor_id', $visitorId)->lockForUpdate()->first() ?? throw new DeskRefused('pas_du_groupe', 'Cette personne ne fait pas partie de ce groupe.', 422);
    }

    private function open(string $groupId, User $actor): object
    {
        $group = DB::table('groups')->where('id', $groupId)->lockForUpdate()->first() ?? throw new DeskRefused('groupe_inconnu', 'Groupe introuvable.', 404);
        if ($group->opened_at === null) {
            DB::table('groups')->where('id', $groupId)->update(['opened_at' => now(), 'updated_at' => now()]);
            $this->audit->append('groupe.ouvert', $actor->id, ['group_id' => $groupId, 'event_id' => $group->event_id]);
        }

        return $group;
    }

    /**
     * Appel nominal et contrôle de correspondance. Mode « identité » : la pièce a été contrôlée (enregistrée dans identity_checks) ;
     * mode « encadreur » : seule la confirmation de l'encadreur compte (l'agent ne peut pas la remplacer).
     *
     * @return array{checked: bool, mode: string}
     */
    public function check(User $agent, string $groupId, string $visitorId, bool $identityChecked = false): array
    {
        return DB::transaction(function () use ($agent, $groupId, $visitorId, $identityChecked): array {
            $this->open($groupId, $agent);
            $member = $this->member($groupId, $visitorId);
            $mode = $this->mode();
            if ($member->absent_at !== null || DB::table('groups')->where('id', $groupId)->value('departed_at') !== null) {
                throw new DeskRefused('car_ferme', 'Le car est déjà parti : plus aucun contrôle de ce groupe n’est possible.', 409);
            }
            if ($member->checked_at !== null) {
                return ['checked' => true, 'mode' => (string) ($member->check_mode ?? $mode)];
            }
            if ($mode === 'identite') {
                if (! $identityChecked) {
                    throw new DeskRefused('identite_non_cochee', 'Cochez « pièce d’identité contrôlée » après avoir vérifié la pièce.');
                }
                DB::table('identity_checks')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $visitorId, 'actor_id' => $agent->id, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($member->confirmed_at === null) {
                throw new DeskRefused('confirmation_encadreur_requise', 'L’encadreur n’a pas encore confirmé la présence de cette personne.', 422, 'Demandez à l’encadreur de confirmer sur son téléphone.');
            }
            DB::table('group_members')->where('id', $member->id)->update(['called_at' => $member->called_at ?? now(), 'checked_at' => now(), 'check_mode' => $mode, 'checked_by' => $agent->id, 'updated_at' => now()]);

            return ['checked' => true, 'mode' => $mode];
        });
    }

    /** Confirmation de présence par l'encadreur de ce groupe (contrôle d'accès par objet : jamais un autre groupe). */
    public function confirm(User $encadreur, string $groupId, string $visitorId): void
    {
        DB::transaction(function () use ($encadreur, $groupId, $visitorId): void {
            $group = DB::table('groups')->where('id', $groupId)->lockForUpdate()->first();
            if ($group === null || $group->supervisor_id !== $encadreur->id) {
                throw new DeskRefused('groupe_interdit', 'Ce groupe n’est pas le vôtre.', 403);
            }
            $member = $this->member($groupId, $visitorId);
            if ($group->departed_at !== null) {
                throw new DeskRefused('car_ferme', 'Le car est déjà parti.', 409);
            }
            if ($member->confirmed_at !== null) {
                return;
            }
            DB::table('group_members')->where('id', $member->id)->update(['confirmed_at' => now(), 'confirmed_by' => $encadreur->id, 'called_at' => $member->called_at ?? now(), 'updated_at' => now()]);
            $this->audit->append('groupe.confirme_par_encadreur', $encadreur->id, ['group_id' => $groupId, 'visitor_id' => $visitorId]);
        });
    }

    /**
     * Remise d'un badge du lot réservé au groupe. Passe par BadgeService (aucun contournement) ; un badge hors du lot du groupe est refusé.
     *
     * @return array<string, mixed>
     */
    public function deliver(?Device $device, User $agent, string $groupId, string $visitorId, string $code): array
    {
        return DB::transaction(function () use ($device, $agent, $groupId, $visitorId, $code): array {
            $group = $this->open($groupId, $agent);
            $member = $this->member($groupId, $visitorId);
            if ($group->departed_at !== null) {
                throw new DeskRefused('car_ferme', 'Le car est déjà parti : plus aucun badge ne peut être remis.', 409);
            }
            if ($member->checked_at === null) {
                throw new DeskRefused('controle_requis', 'Contrôlez d’abord la correspondance de cette personne.', 422, $this->mode() === 'encadreur' ? 'Attendez la confirmation de l’encadreur.' : 'Cochez la pièce d’identité contrôlée.');
            }
            $input = trim($code);
            if (str_starts_with($input, QrSvg::PREFIX)) {
                $input = substr($input, strlen(QrSvg::PREFIX));
            }
            $query = DB::table('badges')->select('id', 'code', 'reserved_group_id', 'event_id');
            preg_match('/^[0-9a-f]{64}$/i', $input) === 1 ? $query->where('qr_token', strtolower($input)) : $query->where('event_id', $group->event_id)->whereRaw('upper(code) = ?', [mb_strtoupper($input)]);
            $badge = $query->first() ?? throw new DeskRefused('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 422, 'Saisissez le code imprimé ou prenez un autre badge.');
            $held = DB::table('badge_assignments as a')->join('badges as b', 'b.id', '=', 'a.badge_id')->where('a.visitor_id', $visitorId)->whereNull('a.returned_at')->whereNull('a.cancelled_at')->value('b.code');
            if ($held === $badge->code) {
                return ['badge_code' => $badge->code];
            }
            if ($badge->reserved_group_id !== $groupId) {
                throw new DeskRefused('hors_lot_groupe', 'Ce badge n’appartient pas au lot réservé à ce groupe.', 422, 'Prenez un badge du lot de ce groupe.');
            }
            try {
                $this->badges->associer($badge->id, $visitorId, $agent, $device);
            } catch (BadgeRefused $e) {
                throw new DeskRefused($e->reason, $e->getMessage(), 422, 'Prenez un autre badge du lot du groupe.');
            }
            $this->audit->append('groupe.badge_remis', $agent->id, ['group_id' => $groupId, 'visitor_id' => $visitorId, 'badge_id' => $badge->id, 'event_id' => $group->event_id]);

            return ['badge_code' => $badge->code];
        });
    }

    /**
     * Fermeture du car : les stagiaires badgés sont embarqués, les autres sont absents (signalés au superviseur). Rejouable sans effet.
     *
     * @return array{boarded:int,absent:int}
     */
    public function depart(User $agent, string $groupId): array
    {
        return DB::transaction(function () use ($agent, $groupId): array {
            $group = $this->open($groupId, $agent);
            $this->ensureMembers($groupId);
            if ($group->departed_at !== null) {
                return ['boarded' => (int) DB::table('group_members')->where('group_id', $groupId)->whereNotNull('boarded_at')->count(), 'absent' => (int) DB::table('group_members')->where('group_id', $groupId)->whereNotNull('absent_at')->count()];
            }
            $now = now();
            $boarded = DB::table('group_members')->where('group_id', $groupId)->whereExists(fn ($q) => $q->selectRaw('1')->from('badge_assignments as a')->whereColumn('a.visitor_id', 'group_members.visitor_id')->whereNull('a.returned_at')->whereNull('a.cancelled_at'))->update(['boarded_at' => $now, 'updated_at' => $now]);
            $absentIds = DB::table('group_members')->where('group_id', $groupId)->whereNull('boarded_at')->pluck('visitor_id')->all();
            DB::table('group_members')->where('group_id', $groupId)->whereNull('boarded_at')->update(['absent_at' => $now, 'updated_at' => $now]);
            DB::table('groups')->where('id', $groupId)->update(['departed_at' => $now, 'departed_by' => $agent->id, 'updated_at' => $now]);
            foreach ($absentIds as $visitorId) {
                $this->audit->append('groupe.absent', $agent->id, ['group_id' => $groupId, 'visitor_id' => $visitorId]);
            }
            $this->audit->append('groupe.embarque', $agent->id, ['group_id' => $groupId, 'embarques' => $boarded, 'absents' => count($absentIds), 'event_id' => $group->event_id]);
            if ($absentIds !== []) {
                $this->alerts->raise($group->event_id, 'groupe_incomplet', 'haute', 'groupe', $groupId, count($absentIds).' absent(s) au départ du car '.($group->bus_number ?? $group->name).' ; l’encadreur est prévenu.', 'groupe_incomplet:'.$groupId);
            }

            return ['boarded' => $boarded, 'absent' => count($absentIds)];
        });
    }

    /**
     * Arrivée sur le site : un badge scanné marque son porteur « arrivé » (statut présent et événement d'entrée). Un badge de stagiaire non embarqué
     * (ou d'un groupe dont le car n'est pas parti) est un écart signalé ; il est tout de même accepté, c'est une personne réellement présente.
     *
     * @return array<string, mixed>
     */
    public function arrive(Device $device, User $agent, string $code, ?string $declaredAt = null): array
    {
        return DB::transaction(function () use ($device, $agent, $code, $declaredAt): array {
            $input = trim($code);
            if (str_starts_with($input, QrSvg::PREFIX)) {
                $input = substr($input, strlen(QrSvg::PREFIX));
            }
            $query = DB::table('badges')->select('id', 'code', 'event_id', 'status');
            preg_match('/^[0-9a-f]{64}$/i', $input) === 1 ? $query->where('qr_token', strtolower($input)) : $query->where('event_id', $device->event_id)->whereRaw('upper(code) = ?', [mb_strtoupper($input)]);
            $badge = $query->first();
            if ($badge === null || $badge->event_id !== $device->event_id) {
                throw new DeskRefused('badge_inconnu', 'Badge inconnu : vérifiez le code ou scannez à nouveau.', 422);
            }
            $visitorId = DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNull('returned_at')->whereNull('cancelled_at')->value('visitor_id') ?? throw new DeskRefused('non_associe', 'Badge non associé à une personne.', 422);
            $member = DB::table('group_members as m')->join('groups as g', 'g.id', '=', 'm.group_id')->where('m.visitor_id', $visitorId)->lockForUpdate()->first(['m.*', 'g.name as group_name', 'g.bus_number', 'g.event_id as group_event', 'g.departed_at']) ?? throw new DeskRefused('pas_dun_groupe', 'Ce badge n’est pas celui d’un stagiaire de groupe : utilisez le contrôle sur site.', 422);
            if ($member->arrived_at !== null) {
                return ['arrived' => true, 'already' => true, 'badge_code' => $badge->code, 'group_id' => $member->group_id, 'ecart' => null];
            }
            $guest = DB::table('visitors')->where('id', $visitorId)->lockForUpdate()->first();
            if ($guest->withdrawn_at !== null) {
                throw new DeskRefused('invite_retire', 'Cet invité a été retiré de la liste.');
            }
            $now = now();
            $ecart = null;
            if ($member->boarded_at === null) {
                $ecart = 'non_embarque';
                $this->alerts->raise($member->group_event, 'groupe_ecart', 'haute', 'groupe', $member->group_id, 'Un badge du car '.($member->bus_number ?? $member->group_name).' a été scanné à l’arrivée alors que la personne n’était pas embarquée.', 'ecart_non_embarque:'.$visitorId);
            }
            DB::table('group_members')->where('id', $member->id)->update(['arrived_at' => $now, 'updated_at' => $now]);
            DB::table('visitors')->where('id', $visitorId)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'attendu'))->update(['status' => 'present', 'updated_at' => $now]);
            DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $device->event_id, 'visitor_id' => $visitorId, 'badge_id' => $badge->id, 'actor_id' => $agent->id, 'type' => 'entree', 'checkpoint' => $device->station ?? $device->name, 'occurred_at' => $now, 'details' => json_encode(array_filter(['arrivee_groupe' => true, 'group_id' => $member->group_id, 'heure_declaree' => $declaredAt, 'ecart' => $ecart])), 'created_at' => $now, 'updated_at' => $now]);
            app(HostNotifications::class)->send($visitorId, 'arrivee', $agent, $device);
            $this->audit->append('groupe.arrive', $agent->id, ['group_id' => $member->group_id, 'visitor_id' => $visitorId, 'badge_id' => $badge->id, 'device_id' => $device->id, 'ecart' => $ecart]);

            return ['arrived' => true, 'already' => false, 'badge_code' => $badge->code, 'group_id' => $member->group_id, 'ecart' => $ecart];
        });
    }

    /**
     * Fin du contrôle collectif d'arrivée : les embarqués non scannés sont signalés. Rejouable sans effet.
     *
     * @return array{arrived:int,missing:int}
     */
    public function finishArrival(User $agent, string $groupId): array
    {
        return DB::transaction(function () use ($groupId): array {
            $group = DB::table('groups')->where('id', $groupId)->lockForUpdate()->first() ?? throw new DeskRefused('groupe_inconnu', 'Groupe introuvable.', 404);
            $arrived = (int) DB::table('group_members')->where('group_id', $groupId)->whereNotNull('arrived_at')->count();
            $missing = (int) DB::table('group_members')->where('group_id', $groupId)->whereNotNull('boarded_at')->whereNull('arrived_at')->count();
            if ($group->arrival_closed_at === null) {
                DB::table('groups')->where('id', $groupId)->update(['arrival_closed_at' => now(), 'updated_at' => now()]);
                $label = $group->bus_number ?? $group->name;
                if ($missing > 0) {
                    $this->alerts->raise($group->event_id, 'groupe_ecart', 'haute', 'groupe', $groupId, $missing.' stagiaire(s) embarqué(s) du car '.$label.' n’ont pas été scannés à l’arrivée.', 'ecart_arrivee:'.$groupId);
                }
                $this->alerts->raise($group->event_id, 'car_arrive', 'normale', 'groupe', $groupId, 'Car '.$label.' arrivé : '.$arrived.' stagiaire(s) scanné(s).', 'car_arrive:'.$groupId);
            }

            return ['arrived' => $arrived, 'missing' => $missing];
        });
    }
}
