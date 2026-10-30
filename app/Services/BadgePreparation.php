<?php

namespace App\Services;

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Destination d'impression immuable ; aucune association, validation ni entrée. */
final class BadgePreparation
{
    /**
     * @param list<string> $visitorIds
     * @return list<array{visitor_id:string,code:string,poste_id:string|null}>
     */
    public function prepare(string $event, string $batch, int $from, int $to, array $visitorIds, User $actor): array
    {
        abort_unless($actor->can('badges.gerer') && $actor->can('listes.importer'), 403);

        return DB::transaction(function () use ($event, $batch, $from, $to, $visitorIds, $actor) {
            EventAvailability::requireOpen($event);
            $badges = Badge::where('event_id', $event)->where('badge_batch_id', $batch)
                ->whereRaw("(CASE WHEN code ~ '^B-[0-9]+$' THEN substring(code from 3)::integer END) BETWEEN ? AND ?", [$from, $to])
                ->orderByRaw('length(code), code')->limit(201)->lockForUpdate()->get();
            if ($badges->count() !== count($visitorIds) || $badges->count() > 200) {
                throw ValidationException::withMessages(['visitor_ids' => 'Choisissez exactement une personne par badge de la plage (200 au maximum).']);
            }
            $result = [];
            foreach ($badges as $index => $badge) {
                $person = Visitor::where('event_id', $event)->whereNull('withdrawn_at')->lockForUpdate()->findOrFail($visitorIds[$index]);
                if ($badge->printed_for_visitor_id === $person->id) {
                    $result[] = ['visitor_id' => $person->id, 'code' => $badge->code, 'poste_id' => $badge->reserved_device_id];

                    continue;
                }
                // La réservation au poste est une affectation de stock, pas une remise à une personne.
                // Conserver le poste et le QR. Ne jamais transformer un badge déjà utilisé, réservé à
                // un groupe, ou réservé à un poste désactivé / rattaché à un autre événement.
                $posteValide = $badge->reserved_device_id === null || DB::table('devices')
                    ->where('id', $badge->reserved_device_id)->where('event_id', $event)
                    ->whereNull('revoked_at')->exists();
                if ($badge->printed_for_visitor_id !== null || $badge->status !== BadgeStatus::Available
                    || $badge->reserved_group_id !== null || ! $posteValide
                    || $badge->category_id !== $person->category_id
                    || DB::table('badge_assignments')->where('badge_id', $badge->id)->exists()
                    || Badge::where('printed_for_visitor_id', $person->id)->whereIn('status', ['Disponible', 'Associé'])->exists()
                    || DB::table('badge_assignments')->where('visitor_id', $person->id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
                    throw ValidationException::withMessages(['visitor_ids' => 'Préparation refusée : badge indisponible, déjà utilisé, réservé à un groupe ou à un poste inactif, catégorie différente, ou personne ayant un autre badge. Aucune destination n’a été modifiée.']);
                }
                $badge->update(['printed_for_visitor_id' => $person->id]);
                $result[] = ['visitor_id' => $person->id, 'code' => $badge->code, 'poste_id' => $badge->reserved_device_id];
            }
            DB::table('events')->where('id', $event)->increment('list_revision');
            app(Audit::class)->append('badge.destination_preparee', $actor->id, ['event_id' => $event, 'batch_id' => $batch, 'destinations' => $result, 'mise_a_jour_poste_requise' => collect($result)->contains(fn (array $destination) => $destination['poste_id'] !== null)]);

            return $result;
        });
    }
}
