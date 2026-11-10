<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use App\Services\Poste\Devices;
use App\Services\Poste\ListSnapshot;
use App\Services\Poste\SyncProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Enrôlement, copie de la liste, synchronisation de la file et déclaration d'effacement d'une tablette. */
final class PosteSyncController
{
    public function enroler(Request $r, Devices $devices): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:40']);

        return response()->json($devices->enrol($data['code'], $r->user()));
    }

    public function liste(Request $r, ListSnapshot $snapshot): JsonResponse
    {
        return response()->json($snapshot->build($this->poste($r)));
    }

    public function version(Request $r, ListSnapshot $snapshot): JsonResponse
    {
        return response()->json(['version' => $snapshot->version($this->poste($r)), 'server_time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')]);
    }

    /** Activités que cette tablette peut choisir (autorisées par l'administrateur, encore ouvertes). */
    public function activites(Request $r, Devices $devices): JsonResponse
    {
        return response()->json(['activites' => $devices->choices($this->poste($r))]);
    }

    /** Change l'activité du jour de la tablette (l'agent choisit parmi les activités autorisées). */
    public function activite(Request $r, Devices $devices): JsonResponse
    {
        $data = $r->validate(['event_id' => 'required|uuid']);
        $devices->switchActivity($this->poste($r), $data['event_id'], $r->user());

        return response()->json(['activites' => $devices->choices($this->poste($r)->refresh())]);
    }

    public function sync(Request $r, SyncProcessor $processor): JsonResponse
    {
        $data = $r->validate(['sent_at' => 'nullable|date', 'operations' => 'required|array|min:1|max:'.SyncProcessor::MAX_OPERATIONS, 'operations.*' => 'array']);

        $result = $processor->process($this->poste($r), $r->user(), $data['operations'], $data['sent_at'] ?? null);
        if ($result['author_mismatch'] ?? false) {
            return response()->json(['code' => 'auteur_incoherent', 'message' => 'Ces opérations ont été saisies par un autre compte : reconnectez-vous avec le compte qui les a saisies. Rien n’a été enregistré.', 'action' => null], 403);
        }
        if ($result['revoked'] ?? false) {
            return response()->json(['code' => 'appareil_revoque', 'message' => 'Cette tablette a été révoquée : contactez l’administrateur.'], 401);
        }

        return response()->json($result);
    }

    public function efface(Request $r, Audit $audit): JsonResponse
    {
        $data = $r->validate(['motif' => ['required', Rule::in(['echecs_pin', 'deconnexion', 'fin_evenement', 'manuel'])], 'operations_perdues' => 'nullable|integer|min:0']);
        $device = $this->poste($r);
        DB::transaction(function () use ($device, $data, $r): void {
            DB::table('devices')->where('id', $device->id)->update(['wipe_acknowledged_at' => now(), 'updated_at' => now()]);
            if (($data['operations_perdues'] ?? 0) > 0) {
                DB::table('sync_conflicts')->insert(['id' => (string) Str::uuid(), 'event_id' => $device->event_id, 'device_id' => $device->id, 'agent_id' => $r->user()->id, 'kind' => 'conflit', 'reason' => 'effacement_operations_non_synchronisees', 'details' => json_encode(['nombre' => (int) $data['operations_perdues']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $audit->append('poste.efface', $r->user()->id, ['device_id' => $this->poste($r)->id, 'motif' => $data['motif'], 'operations_perdues' => (int) ($data['operations_perdues'] ?? 0)]);

        return response()->json(['ok' => true]);
    }

    private function poste(Request $r): Device
    {
        return $r->attributes->get('poste');
    }
}
