<?php

namespace App\Services\Atelier;

use Illuminate\Support\Facades\DB;

/**
 * Marquage d'exposition des QR : un lot dont les jetons ont été rendus (aperçu réel, impression, étiquettes) ne peut plus être supprimé comme un lot jamais exposé.
 * Point unique du marquage pour l'Atelier. À appeler AVANT toute lecture ou tout rendu d'un vrai jeton. Jamais appelé pour un aperçu fictif (QR d'exemple).
 */
final class ExposureMarker
{
    /** Vrai si le lot existe (et appartient à l'événement indiqué, s'il l'est) : il est alors marqué comme exposé. */
    public function markBatch(string $batchId, ?string $eventId = null): bool
    {
        $query = DB::table('badge_batches')->where('id', $batchId);
        if ($eventId !== null) {
            $query->where('event_id', $eventId);
        }

        return $query->update(['print_exposed_at' => now()]) === 1;
    }
}
