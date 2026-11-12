<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Habilitations des titulaires permanents (A06) : un titulaire est habilité à UNE zone d'UN événement pour une période. Rien d'implicite :
 * la catégorie institutionnelle (AILCT-01 à 08), la possession d'une carte et le rôle n'accordent jamais d'habilitation. Les habilitations ne se modifient pas,
 * elles se retirent (motif) ; l'historique est conservé. Chaque zone a en plus une politique (classification et autorisations) : sans politique, la zone refuse
 * les titulaires permanents. Les valeurs de classification sont saisies par l'institution : aucune classification officielle n'est fournie par défaut.
 */
final class HolderEntitlements
{
    public function __construct(private readonly Audit $audit) {}

    // --- Politiques de zone -----------------------------------------------------------------------------------------------------

    /** @return list<object> */
    public function zones(string $eventId): array
    {
        return ActivityZones::query('z')->leftJoin('site_zone_policies as sp', 'sp.zone_id', '=', 'z.id')->orderBy('z.name')
            ->get(['z.id', 'z.name', 'sp.classification', 'sp.permanent_allowed', 'sp.offline_entry_allowed'])->all();
    }

    public function savePolicy(string $eventId, string $zoneId, string $classification, bool $permanentAllowed, bool $offlineAllowed, User $actor): void
    {
        $classification = trim($classification);
        if ($classification === '' || mb_strlen($classification) > 40 || preg_match('/[\x00-\x1F\x7F<>]/u', $classification) === 1) {
            throw ValidationException::withMessages(['classification' => 'Indiquez une classification (40 caractères au plus).']);
        }
        if ($offlineAllowed && ! $permanentAllowed) {
            throw ValidationException::withMessages(['offline' => 'Une zone fermée aux titulaires permanents ne peut pas les accepter hors ligne.']);
        }
        $this->eventOrFail($eventId);
        if (! ActivityZones::allows($zoneId)) {
            throw ValidationException::withMessages(['zone_id' => 'Zone inconnue pour cet événement.']);
        }
        // Toute zone est une zone du site : une seule politique, celle du site.
        app(SiteEntitlements::class)->savePolicy($zoneId, $permanentAllowed, $offlineAllowed, $actor, $classification);
    }

    // --- Habilitations ------------------------------------------------------------------------------------------------------------

    /** @return list<object> */
    public function forEvent(string $eventId): array
    {
        return DB::table('holder_entitlements as e')->join('permanent_holders as h', 'h.id', '=', 'e.holder_id')->join('zones as z', 'z.id', '=', 'e.zone_id')
            ->where('e.event_id', $eventId)->orderBy('z.name')->orderByRaw('public.ikoma_normalize(h.full_name)')->orderByDesc('e.valid_from')
            ->get(['e.id', 'e.holder_id', 'h.full_name', 'h.identifier', 'z.id as zone_id', 'z.name as zone', 'e.valid_from', 'e.valid_until', 'e.revoked_at', 'e.revocation_reason'])->all();
    }

    /** @return list<object> */
    public function forHolder(string $holderId): array
    {
        return DB::table('holder_entitlements as e')->join('events as ev', 'ev.id', '=', 'e.event_id')->join('zones as z', 'z.id', '=', 'e.zone_id')
            ->where('e.holder_id', $holderId)->orderByDesc('e.valid_from')
            ->get(['e.id', 'ev.name as event', 'z.name as zone', 'e.valid_from', 'e.valid_until', 'e.revoked_at'])->all();
    }

    public function grant(string $holderId, string $eventId, string $zoneId, string $from, string $until, User $actor): string
    {
        $start = $this->date($from, 'valid_from');
        $end = $this->date($until, 'valid_until');
        if ($end <= $start) {
            throw ValidationException::withMessages(['valid_until' => 'La fin de l’habilitation doit suivre son début.']);
        }
        $this->eventOrFail($eventId);

        return DB::transaction(function () use ($holderId, $eventId, $zoneId, $start, $end, $actor): string {
            $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['holder_id' => 'Ce titulaire est clos : aucune habilitation ne peut lui être accordée.']);
            }
            if (! ActivityZones::allows($zoneId)) {
                throw ValidationException::withMessages(['zone_id' => 'Zone inconnue pour cet événement.']);
            }
            $overlap = DB::table('holder_entitlements')->where('holder_id', $holderId)->where('zone_id', $zoneId)->whereNull('revoked_at')
                ->where('valid_from', '<', $end)->where('valid_until', '>', $start)->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['valid_from' => 'Une habilitation existe déjà sur cette période pour cette zone : retirez-la avant d’en accorder une autre.']);
            }
            $id = (string) Str::uuid();
            DB::table('holder_entitlements')->insert(['id' => $id, 'holder_id' => $holderId, 'event_id' => $eventId, 'zone_id' => $zoneId, 'valid_from' => $start, 'valid_until' => $end, 'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('habilitation.accordee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'event_id' => $eventId, 'zone_id' => $zoneId, 'du' => $start->toIso8601String(), 'au' => $end->toIso8601String()]);

            return $id;
        });
    }

    /**
     * Accorde le même accès à plusieurs personnes et plusieurs zones : une habilitation INDIVIDUELLE par couple (personne, zone), jamais une règle de catégorie.
     * Un couple déjà couvert sur la période est ignoré (compté), sans rien modifier. Tout ou rien en cas d'autre erreur.
     *
     * @param  list<string>  $holderIds
     * @param  list<string>  $zoneIds
     * @return array{accordees: int, deja_couvertes: int}
     */
    public function grantMany(array $holderIds, string $eventId, array $zoneIds, string $from, string $until, User $actor): array
    {
        $created = 0;
        $skipped = 0;
        DB::transaction(function () use ($holderIds, $eventId, $zoneIds, $from, $until, $actor, &$created, &$skipped): void {
            foreach ($holderIds as $holder) {
                foreach ($zoneIds as $zone) {
                    try {
                        // Savepoint : un chevauchement est ignoré sans annuler les autres accès de la demande.
                        DB::transaction(fn () => $this->grant($holder, $eventId, $zone, $from, $until, $actor));
                        $created++;
                    } catch (ValidationException $e) {
                        if (! array_key_exists('valid_from', $e->errors())) {
                            throw $e;
                        }
                        $skipped++;
                    }
                }
            }
        });

        return ['accordees' => $created, 'deja_couvertes' => $skipped];
    }

    public function revoke(string $id, string $reason, User $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif du retrait (200 caractères au plus).']);
        }
        DB::transaction(function () use ($id, $reason, $actor): void {
            $row = DB::table('holder_entitlements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->revoked_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Cette habilitation est déjà retirée.']);
            }
            DB::table('holder_entitlements')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            $identifier = DB::table('permanent_holders')->where('id', $row->holder_id)->value('identifier');
            $this->audit->append('habilitation.retiree', $actor->id, ['holder_id' => $row->holder_id, 'identifiant' => $identifier, 'event_id' => $row->event_id, 'zone_id' => $row->zone_id, 'entitlement_id' => $id]);
        });
    }

    private function eventOrFail(string $eventId): void
    {
        $event = DB::table('events')->where('id', $eventId)->first(['status', 'anonymized_at']);
        if ($event === null) {
            throw ValidationException::withMessages(['event_id' => 'Choisissez un événement existant.']);
        }
        if (in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
            throw ValidationException::withMessages(['event_id' => 'Cet événement est clôturé, archivé ou anonymisé.']);
        }
    }

    private function date(string $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Date et heure invalides.']);
        }
    }
}
