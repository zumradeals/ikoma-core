<?php

namespace App\Services;

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\BadgeAssignment;
use App\Models\Device;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;

/**
 * Point d’entrée unique des règles de vie d’un badge. Chaque opération est une seule transaction avec verrou de ligne :
 * statut du badge, historique d’association et journal d’audit sont écrits ensemble ou pas du tout.
 */
final class BadgeService
{
    public function __construct(private readonly Audit $audit) {}

    /** $avis : avis de l'hôte ou du superviseur (« hote » ou « superviseur »), seul moyen d'associer un badge à un visiteur hors période. */
    public function associer(Badge|string $badge, Visitor|string $visitor, User $agent, Device|string|null $device = null, ?string $avis = null): BadgeAssignment
    {
        $this->require($agent, 'badges.associer');
        $badgeId = $badge instanceof Badge ? $badge->id : $badge;
        $visitorId = $visitor instanceof Visitor ? $visitor->id : $visitor;
        $deviceId = $device instanceof Device ? $device->id : $device;

        return DB::transaction(function () use ($badgeId, $visitorId, $deviceId, $agent, $avis): BadgeAssignment {
            $badge = $this->lockBadge($badgeId);
            $visitor = Visitor::query()->lockForUpdate()->find($visitorId) ?? throw new BadgeRefused('visiteur_inconnu', 'Visiteur introuvable.');
            if ($badge->event_id !== $visitor->event_id) {
                throw new BadgeRefused('autre_evenement', 'Ce badge appartient à un autre événement.');
            }
            if ($badge->status !== BadgeStatus::Available) {
                throw $this->unavailable($badge);
            }
            if ($badge->category_id === null) {
                throw new BadgeRefused('sans_categorie', 'Badge sans catégorie (lot hérité) : il ne peut pas être associé. Prenez un autre badge.');
            }
            if ($badge->category_id !== $visitor->category_id) {
                $badgeCategory = DB::table('categories')->where('id', $badge->category_id)->value('name');
                $visitorCategory = DB::table('categories')->where('id', $visitor->category_id)->value('name');
                throw new BadgeRefused('categorie', "Badge de catégorie {$badgeCategory}, visiteur de catégorie {$visitorCategory}");
            }
            if ($visitor->status === 'refuse') {
                throw new BadgeRefused('visiteur_refuse', 'Accès refusé à ce visiteur : aucun badge ne peut lui être associé.');
            }
            $held = DB::table('badge_assignments as a')->join('badges as b', 'b.id', '=', 'a.badge_id')->where('a.visitor_id', $visitor->id)->whereNull('a.returned_at')->whereNull('a.cancelled_at')->value('b.code');
            if ($held !== null) {
                throw new BadgeRefused('visiteur_deja_badge', "Ce visiteur a déjà le badge {$held}.");
            }
            if ($badge->reserved_device_id !== null && $badge->reserved_device_id !== $deviceId) {
                throw new BadgeRefused('autre_poste', 'Badge réservé à un autre poste : il ne peut être associé que depuis ce poste.');
            }
            if ($deviceId !== null && ! DB::table('devices')->where('id', $deviceId)->where('event_id', $badge->event_id)->exists()) {
                throw new BadgeRefused('poste_inconnu', 'Poste inconnu pour cet événement.');
            }
            $now = now();
            if (($now->lt($visitor->valid_from) || $now->gt($visitor->valid_until)) && ! in_array($avis, ['hote', 'superviseur'], true)) {
                throw new BadgeRefused('hors_periode', "Visiteur hors période : avis de l'hôte ou du superviseur requis");
            }
            $assignment = BadgeAssignment::create(['event_id' => $badge->event_id, 'badge_id' => $badge->id, 'visitor_id' => $visitor->id, 'actor_id' => $agent->id, 'device_id' => $deviceId, 'assigned_at' => $now]);
            $this->setStatus($badge, BadgeStatus::Assigned);
            $this->audit->append('badge.associe', $agent->id, ['badge_id' => $badge->id, 'visitor_id' => $visitor->id, 'assignment_id' => $assignment->id, 'device_id' => $deviceId, 'event_id' => $badge->event_id, ...($avis !== null ? ['avis' => $avis] : [])]);

            return $assignment;
        });
    }

    /** Restitution : le badge redevient Disponible si le réglage badge_reutilisable vaut « oui », sinon il est Clos. */
    public function restituer(Badge|string $badge, User $agent): Badge
    {
        $this->require($agent, 'badges.associer');
        $badgeId = $badge instanceof Badge ? $badge->id : $badge;

        return DB::transaction(function () use ($badgeId, $agent): Badge {
            $badge = $this->lockBadge($badgeId);
            $assignment = $this->activeAssignment($badge);
            if ($badge->status !== BadgeStatus::Assigned || $assignment === null) {
                throw new BadgeRefused('non_associe', 'Ce badge n’est pas associé : il ne peut pas être restitué.');
            }
            $reusable = DB::table('settings')->where('key', 'badge_reutilisable')->value('value') === 'oui';
            $assignment->update(['returned_at' => now()]);
            $this->setStatus($badge, BadgeStatus::Returned);
            $this->setStatus($badge, $reusable ? BadgeStatus::Available : BadgeStatus::Closed);
            $this->audit->append('badge.restitue', $agent->id, ['badge_id' => $badge->id, 'visitor_id' => $assignment->visitor_id, 'assignment_id' => $assignment->id, 'statut_final' => $badge->status->value, 'event_id' => $badge->event_id]);

            return $badge;
        });
    }

    /** Annulation définitive (perdu, volé, défectueux), d'un badge associé ou encore disponible, par un superviseur. Le motif est obligatoire. */
    public function annuler(Badge|string $badge, User $superviseur, string $motif): Badge
    {
        $this->require($superviseur, 'badges.annuler');
        $motif = trim($motif);
        if ($motif === '') {
            throw new BadgeRefused('motif_obligatoire', 'Le motif d’annulation est obligatoire.');
        }
        $badgeId = $badge instanceof Badge ? $badge->id : $badge;

        return DB::transaction(function () use ($badgeId, $superviseur, $motif): Badge {
            $badge = $this->lockBadge($badgeId);
            $assignment = $this->activeAssignment($badge);
            if ($badge->status === BadgeStatus::Assigned && $assignment !== null) {
                $assignment->update(['cancelled_at' => now(), 'cancellation_reason' => $motif]);
            } elseif ($badge->status !== BadgeStatus::Available) {
                throw new BadgeRefused('non_annulable', 'Seul un badge disponible ou associé peut être annulé.');
            }
            $badge->cancellation_reason = $motif;
            $this->setStatus($badge, BadgeStatus::Cancelled);
            $this->audit->append('badge.annule', $superviseur->id, ['badge_id' => $badge->id, 'visitor_id' => $assignment?->visitor_id, 'assignment_id' => $assignment?->id, 'event_id' => $badge->event_id]);

            return $badge;
        });
    }

    /**
     * Contrôle d’un badge à partir du code lisible ou du jeton (avec ou sans le préfixe du QR). Ne modifie ni le badge ni son historique ;
     * un refus est seulement journalisé. Un code lisible n’est unique que dans son événement : il faut alors fournir l’événement.
     */
    public function controler(string $codeOrToken, ?string $eventId = null, ?User $actor = null): BadgeVerdict
    {
        $input = trim($codeOrToken);
        if (str_starts_with($input, QrSvg::PREFIX)) {
            $input = substr($input, strlen(QrSvg::PREFIX));
        }
        $query = DB::table('badges as b')->leftJoin('categories as c', 'c.id', '=', 'b.category_id')
            ->leftJoin('badge_assignments as a', fn ($j) => $j->on('a.badge_id', '=', 'b.id')->whereNull('a.returned_at')->whereNull('a.cancelled_at'))
            ->leftJoin('visitors as v', 'v.id', '=', 'a.visitor_id')
            ->select('b.id', 'b.event_id', 'b.status', 'c.id as category_id', 'c.name as category_name', 'c.color as category_color', 'v.id as visitor_id', 'v.full_name', 'v.organisation', 'v.valid_from', 'v.valid_until', 'v.category_id as visitor_category_id');
        if (preg_match('/^[0-9a-f]{64}$/i', $input) === 1) {
            $query->where('b.qr_token', strtolower($input));
        } else {
            $query->whereRaw('upper(b.code) = ?', [mb_strtoupper($input)]);
            if ($eventId !== null) {
                $query->where('b.event_id', $eventId);
            }
        }
        $rows = $query->limit(2)->get();
        if ($rows->count() !== 1) {
            return $this->refuse($rows->isEmpty() ? 'inconnu' : 'evenement_requis', $rows->isEmpty() ? 'Badge inconnu.' : 'Plusieurs événements portent ce code : précisez l’événement.', null, null, $actor);
        }
        $row = $rows->first();
        $status = BadgeStatus::from($row->status);
        if ($status === BadgeStatus::Cancelled) {
            return $this->refuse('annule', 'Badge annulé : accès refusé.', $row->id, $row->event_id, $actor);
        }
        if ($status === BadgeStatus::Returned || $status === BadgeStatus::Closed) {
            return $this->refuse('restitue', 'Badge restitué : il n’est plus valable.', $row->id, $row->event_id, $actor);
        }
        if ($status === BadgeStatus::Available || $row->visitor_id === null) {
            return $this->refuse('non_associe', 'Badge non associé à une personne.', $row->id, $row->event_id, $actor);
        }
        $now = now();
        if ($now->lt(new \DateTimeImmutable($row->valid_from)) || $now->gt(new \DateTimeImmutable($row->valid_until))) {
            return $this->refuse('hors_periode', 'Badge hors de sa période de validité.', $row->id, $row->event_id, $actor);
        }
        $categoryId = $row->visitor_category_id;
        $category = DB::table('categories')->where('id', $categoryId)->first(['id', 'name', 'color']);
        $zones = DB::table('category_zone as cz')->join('zones as z', 'z.id', '=', 'cz.zone_id')->where('cz.category_id', $categoryId)->orderBy('z.name')->pluck('z.name')->all();

        return new BadgeVerdict(true, null, 'Badge valide.', $row->id, ['id' => $row->visitor_id, 'name' => $row->full_name], $category ? ['id' => $category->id, 'name' => $category->name, 'color' => $category->color] : null, $row->organisation, $zones);
    }

    private function refuse(string $reason, string $message, ?string $badgeId, ?string $eventId, ?User $actor): BadgeVerdict
    {
        $this->audit->append('badge.controle_refuse', $actor?->id, ['badge_id' => $badgeId, 'event_id' => $eventId, 'raison' => $reason]);

        return new BadgeVerdict(false, $reason, $message, $badgeId);
    }

    private function require(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new BadgeRefused('non_autorise', 'Action non autorisée pour ce compte.');
        }
    }

    private function lockBadge(string $id): Badge
    {
        return Badge::query()->lockForUpdate()->find($id) ?? throw new BadgeRefused('inconnu', 'Badge introuvable.');
    }

    private function activeAssignment(Badge $badge): ?BadgeAssignment
    {
        return BadgeAssignment::query()->where('badge_id', $badge->id)->whereNull('returned_at')->whereNull('cancelled_at')->lockForUpdate()->first();
    }

    private function setStatus(Badge $badge, BadgeStatus $status): void
    {
        $badge->status = $status;
        $badge->save();
    }

    private function unavailable(Badge $badge): BadgeRefused
    {
        if ($badge->status === BadgeStatus::Assigned) {
            $name = DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->where('a.badge_id', $badge->id)->whereNull('a.returned_at')->whereNull('a.cancelled_at')->value('v.full_name');

            return new BadgeRefused('deja_associe', 'Badge déjà associé'.($name ? " à {$name}" : '').'. Prenez un autre badge.');
        }

        return match ($badge->status) {
            BadgeStatus::Cancelled => new BadgeRefused('annule', 'Badge annulé : il ne peut plus être utilisé.'),
            default => new BadgeRefused('indisponible', 'Badge restitué ou clos : il ne peut plus être associé.'),
        };
    }
}
