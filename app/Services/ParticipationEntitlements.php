<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Habilitations d'une PARTICIPATION à une zone (S06). Réservé à l'administrateur : accorder, suspendre, reprendre et retirer exigent « zones.gerer » et
 * « listes.importer », que le superviseur n'a pas. Le superviseur consulte et surveille, il ne modifie jamais une habilitation.
 * Une habilitation exceptionnelle exige un motif et une durée limitée (réglage habilitation_exceptionnelle_max_heures) ; toute action est auditée sans nom de personne.
 */
final class ParticipationEntitlements
{
    public function __construct(private readonly Audit $audit) {}

    public static function maxExceptionalHours(): int
    {
        return max(1, (int) (DB::table('settings')->where('key', 'habilitation_exceptionnelle_max_heures')->value('value') ?? 24));
    }

    /** @return list<object> */
    public function forEvent(string $eventId): array
    {
        return DB::table('participation_entitlements as e')->join('visitors as v', 'v.id', '=', 'e.visitor_id')->join('zones as z', 'z.id', '=', 'e.zone_id')->where('e.event_id', $eventId)
            ->orderByDesc('e.valid_from')->limit(300)
            ->get(['e.id', 'e.visitor_id', 'v.person_id', 'v.full_name', 'z.id as zone_id', 'z.name as zone', 'e.kind', 'e.reason', 'e.valid_from', 'e.valid_until', 'e.suspended_at', 'e.suspension_reason', 'e.revoked_at', 'e.revocation_reason'])->all();
    }

    public function grant(string $visitorId, string $zoneId, string $kind, ?string $reason, string $from, string $until, User $actor): string
    {
        $this->authorize($actor);
        $kind = in_array($kind, ['standard', 'exceptionnelle'], true) ? $kind : throw ValidationException::withMessages(['kind' => 'Type d’habilitation inconnu.']);
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && (mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1)) {
            throw ValidationException::withMessages(['reason' => 'Le motif ne dépasse pas 200 caractères.']);
        }
        if ($kind === 'exceptionnelle' && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire pour une habilitation exceptionnelle.']);
        }
        $start = $this->date($from, 'valid_from');
        $end = $this->date($until, 'valid_until');
        if ($end <= $start) {
            throw ValidationException::withMessages(['valid_until' => 'La fin de l’habilitation doit suivre son début.']);
        }
        if ($kind === 'exceptionnelle') {
            if ($end->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['valid_until' => 'Une habilitation exceptionnelle doit se terminer dans le futur.']);
            }
            if ($start->diffInMinutes($end) > self::maxExceptionalHours() * 60) {
                throw ValidationException::withMessages(['valid_until' => 'Une habilitation exceptionnelle dure au plus '.self::maxExceptionalHours().' heure(s).']);
            }
        }

        return DB::transaction(function () use ($visitorId, $zoneId, $kind, $reason, $start, $end, $actor): string {
            $visitor = DB::table('visitors')->where('id', $visitorId)->lockForUpdate()->first();
            abort_unless($visitor !== null, 404);
            EventAvailability::requireOpen($visitor->event_id);
            if ($visitor->closed_at !== null || $visitor->withdrawn_at !== null || $visitor->status === 'refuse') {
                throw ValidationException::withMessages(['visitor_id' => 'Cette participation est clôturée, retirée ou refusée : aucune habilitation ne peut lui être accordée.']);
            }
            if (! ActivityZones::allows($zoneId)) {
                throw ValidationException::withMessages(['zone_id' => 'Zone inconnue pour cet événement.']);
            }
            $overlap = DB::table('participation_entitlements')->where('visitor_id', $visitorId)->where('zone_id', $zoneId)->whereNull('revoked_at')->where('valid_from', '<', $end)->where('valid_until', '>', $start)->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['zone_id' => 'Une habilitation de cette zone existe déjà sur cette période : retirez-la d’abord.']);
            }
            $id = (string) Str::uuid();
            DB::table('participation_entitlements')->insert(['id' => $id, 'event_id' => $visitor->event_id, 'visitor_id' => $visitorId, 'zone_id' => $zoneId, 'kind' => $kind, 'reason' => $reason === '' ? null : $reason,
                'valid_from' => $start, 'valid_until' => $end, 'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('events')->where('id', $visitor->event_id)->increment('list_revision');
            $this->audit->append('habilitation.accordee', $actor->id, ['habilitation_id' => $id, 'visitor_id' => $visitorId, 'zone_id' => $zoneId, 'event_id' => $visitor->event_id, 'type' => $kind,
                'valid_from' => $start->toIso8601String(), 'valid_until' => $end->toIso8601String()]);

            return $id;
        });
    }

    public function suspend(string $id, string $reason, User $actor): void
    {
        $this->authorize($actor);
        $reason = $this->requiredReason($reason);
        $this->change($id, $actor, 'habilitation.suspendue', function (object $row) use ($reason, $actor): array {
            if ($row->revoked_at !== null || $row->suspended_at !== null) {
                throw ValidationException::withMessages(['id' => 'Cette habilitation est déjà suspendue ou retirée.']);
            }

            return ['suspended_at' => now(), 'suspended_by' => $actor->id, 'suspension_reason' => $reason];
        });
    }

    public function resume(string $id, User $actor): void
    {
        $this->authorize($actor);
        $this->change($id, $actor, 'habilitation.reprise', function (object $row): array {
            if ($row->revoked_at !== null || $row->suspended_at === null) {
                throw ValidationException::withMessages(['id' => 'Cette habilitation n’est pas suspendue.']);
            }

            return ['suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null];
        });
    }

    public function revoke(string $id, string $reason, User $actor): void
    {
        $this->authorize($actor);
        $reason = $this->requiredReason($reason);
        $this->change($id, $actor, 'habilitation.retiree', function (object $row) use ($reason, $actor): array {
            if ($row->revoked_at !== null) {
                throw ValidationException::withMessages(['id' => 'Cette habilitation est déjà retirée.']);
            }

            return ['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason];
        });
    }

    /** @param callable(object): array<string, mixed> $changes */
    private function change(string $id, User $actor, string $action, callable $changes): void
    {
        DB::transaction(function () use ($id, $actor, $action, $changes): void {
            $row = DB::table('participation_entitlements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            DB::table('participation_entitlements')->where('id', $id)->update($changes($row) + ['updated_at' => now()]);
            DB::table('events')->where('id', $row->event_id)->increment('list_revision');
            $this->audit->append($action, $actor->id, ['habilitation_id' => $id, 'visitor_id' => $row->visitor_id, 'zone_id' => $row->zone_id, 'event_id' => $row->event_id, 'type' => $row->kind]);
        });
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Indiquez un motif (200 caractères au plus).']);
        }

        return $reason;
    }

    private function date(string $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Date invalide.']);
        }
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('zones.gerer') && $actor->can('listes.importer'), 403);
    }
}
