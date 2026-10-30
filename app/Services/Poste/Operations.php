<?php

namespace App\Services\Poste;

use App\Services\EventAvailability;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Traitement idempotent des écritures du poste : chaque opération porte un identifiant (en-tête X-Operation-Id) créé par la tablette.
 * Rejouer le même identifiant avec la même charge renvoie le résultat enregistré sans rien écrire de plus ;
 * le réutiliser avec une autre charge, ou par un autre compte, est refusé.
 */
final class Operations
{
    /** @param Closure(): array<string, mixed> $handler retourne le corps de la réponse (200) ou lève DeskRefused */
    public function run(Request $r, string $type, string $fingerprint, Closure $handler): JsonResponse
    {
        $id = (string) $r->header('X-Operation-Id');
        if (! Str::isUuid($id)) {
            return response()->json(['code' => 'operation_requise', 'message' => 'Identifiant d’opération manquant ou invalide.', 'action' => null], 422);
        }
        $device = $r->attributes->get('poste');
        $hash = hash('sha256', $type.'|'.$fingerprint);

        return DB::transaction(function () use ($r, $id, $type, $hash, $device, $handler): JsonResponse {
            $now = now();
            // ON CONFLICT DO NOTHING : une exécution simultanée du même identifiant attend la fin de la première, puis relit son résultat.
            $inserted = DB::table('sync_operations')->insertOrIgnore(['id' => $id, 'device_id' => $device?->id, 'user_id' => $r->user()->id, 'type' => $type, 'payload_hash' => $hash, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if ($inserted === 0) {
                $row = DB::table('sync_operations')->where('id', $id)->first();
                if ($row->user_id !== $r->user()->id || $row->payload_hash !== $hash) {
                    return response()->json(['code' => 'operation_conflit', 'message' => 'Cet identifiant d’opération a déjà servi pour une autre action.', 'action' => null], 409);
                }

                return response()->json(json_decode((string) $row->result, true), (int) $row->status_code, ['X-Idempotent-Replay' => 'true']);
            }
            try {
                if ($device !== null) {
                    EventAvailability::requireOpen($device->event_id);
                    if (DB::table('devices')->where('id', $device->id)->whereNotNull('revoked_at')->exists()) {
                        throw new DeskRefused('appareil_revoque', 'Cette tablette a été révoquée.', 401);
                    }
                }
                $body = DB::transaction($handler); // point de sauvegarde : un refus annule les écritures de l'opération, pas son enregistrement
                $status = 200;
            } catch (DeskRefused $e) {
                $body = $e->toArray();
                $status = $e->status;
            }
            DB::table('sync_operations')->where('id', $id)->update(['status_code' => $status, 'result' => json_encode($body, JSON_THROW_ON_ERROR), 'processed_at' => now(), 'updated_at' => now()]);

            return response()->json($body, $status);
        });
    }
}
