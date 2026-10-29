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
    /** @param list<string> $visitorIds
     * @return list<array{visitor_id:string,code:string}>
     */
    public function prepare(string $event, string $batch, int $from, int $to, array $visitorIds, User $actor): array
    {
        abort_unless($actor->can('badges.gerer') && $actor->can('listes.importer'), 403);

        return DB::transaction(function () use ($event, $batch, $from, $to, $visitorIds, $actor) {
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
                    $result[] = ['visitor_id' => $person->id, 'code' => $badge->code];

                    continue;
                }
                if ($badge->printed_for_visitor_id !== null || $badge->status !== BadgeStatus::Available || $badge->reserved_device_id !== null || $badge->reserved_group_id !== null || $badge->category_id !== $person->category_id
                    || Badge::where('printed_for_visitor_id', $person->id)->whereIn('status', ['Disponible', 'Associé'])->exists()
                    || DB::table('badge_assignments')->where('visitor_id', $person->id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
                    throw ValidationException::withMessages(['visitor_ids' => 'Préparation refusée : choisissez des badges disponibles, non réservés, de la même catégorie et des personnes sans autre badge actif ou préparé.']);
                }
                $badge->update(['printed_for_visitor_id' => $person->id]);
                $result[] = ['visitor_id' => $person->id, 'code' => $badge->code];
            }
            DB::table('events')->where('id', $event)->increment('list_revision');
            app(Audit::class)->append('badge.destination_preparee', $actor->id, ['event_id' => $event, 'batch_id' => $batch, 'destinations' => $result]);

            return $result;
        });
    }
}
