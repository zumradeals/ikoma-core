<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Habilitations permanentes liées au SITE (tablette de site, lot 2) : un titulaire est habilité à UNE zone du site pour une période, sans événement.
 * Mêmes principes que les habilitations d'activité (HolderEntitlements) : rien d'implicite (ni catégorie, ni carte, ni rôle), pas de modification,
 * un retrait motivé, un historique conservé. Les deux moteurs restent distincts : aucune habilitation d'activité n'est lue ni déplacée ici.
 */
final class SiteEntitlements
{
    public function __construct(private readonly Audit $audit) {}

    /** @return list<object> */
    public function all(): array
    {
        return DB::table('holder_site_entitlements as e')->join('permanent_holders as h', 'h.id', '=', 'e.holder_id')->join('zones as z', 'z.id', '=', 'e.zone_id')
            ->orderBy('z.name')->orderByRaw('public.ikoma_normalize(h.full_name)')->orderByDesc('e.valid_from')
            ->get(['e.id', 'e.holder_id', 'h.full_name', 'h.identifier', 'z.id as zone_id', 'z.name as zone', 'e.valid_from', 'e.valid_until', 'e.revoked_at', 'e.revocation_reason'])->all();
    }

    public function grant(string $holderId, string $zoneId, string $from, string $until, User $actor): string
    {
        $start = $this->date($from, 'valid_from');
        $end = $this->date($until, 'valid_until');
        if ($end <= $start) {
            throw ValidationException::withMessages(['valid_until' => 'La fin de l’habilitation doit suivre son début.']);
        }

        return DB::transaction(function () use ($holderId, $zoneId, $start, $end, $actor): string {
            $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['holder_id' => 'Cette personne est clôturée : aucune habilitation ne peut lui être accordée.']);
            }
            if (! DB::table('zones')->where('id', $zoneId)->whereNull('event_id')->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Zone du site inconnue.']);
            }
            $overlap = DB::table('holder_site_entitlements')->where('holder_id', $holderId)->where('zone_id', $zoneId)->whereNull('revoked_at')
                ->where('valid_from', '<', $end)->where('valid_until', '>', $start)->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['valid_from' => 'Une habilitation existe déjà sur cette période pour cette zone : retirez-la avant d’en accorder une autre.']);
            }
            $id = (string) Str::uuid();
            DB::table('holder_site_entitlements')->insert(['id' => $id, 'holder_id' => $holderId, 'zone_id' => $zoneId, 'valid_from' => $start, 'valid_until' => $end, 'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('habilitation_site.accordee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'zone_id' => $zoneId, 'du' => $start->toIso8601String(), 'au' => $end->toIso8601String(), 'habilitation_id' => $id]);

            return $id;
        });
    }

    public function revoke(string $id, string $reason, User $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif du retrait (200 caractères au plus).']);
        }
        DB::transaction(function () use ($id, $reason, $actor): void {
            $row = DB::table('holder_site_entitlements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if ($row->revoked_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Cette habilitation est déjà retirée.']);
            }
            DB::table('holder_site_entitlements')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            $identifier = DB::table('permanent_holders')->where('id', $row->holder_id)->value('identifier');
            $this->audit->append('habilitation_site.retiree', $actor->id, ['holder_id' => $row->holder_id, 'identifiant' => $identifier, 'zone_id' => $row->zone_id, 'habilitation_id' => $id]);
        });
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
