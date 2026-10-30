<?php

namespace App\Services;

use App\Services\Poste\DeskRefused;
use Illuminate\Support\Facades\DB;

final class EventAvailability
{
    public static function requireOpen(string $id): void
    {
        $query = DB::table('events')->where('id', $id);
        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $event = $query->first(['status', 'anonymized_at']);
        if (! $event || in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
            throw new DeskRefused('evenement_indisponible', 'Cet événement est clôturé, archivé ou anonymisé. Aucune nouvelle admission n’est autorisée.', 409);
        }
    }
}
