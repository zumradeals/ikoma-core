<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\Audit;
use App\Services\Poste\Devices;
use App\Services\Poste\ListSnapshot;
use App\Services\Poste\SyncProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function sync(Request $r, SyncProcessor $processor): JsonResponse
    {
        $data = $r->validate(['sent_at' => 'nullable|date', 'operations' => 'required|array|min:1|max:'.SyncProcessor::MAX_OPERATIONS, 'operations.*' => 'array']);

        return response()->json($processor->process($this->poste($r), $r->user(), $data['operations'], $data['sent_at'] ?? null));
    }

    public function efface(Request $r, Audit $audit): JsonResponse
    {
        $data = $r->validate(['motif' => ['required', Rule::in(['echecs_pin', 'deconnexion', 'fin_evenement', 'manuel'])], 'operations_perdues' => 'nullable|integer|min:0']);
        $audit->append('poste.efface', $r->user()->id, ['device_id' => $this->poste($r)->id, 'motif' => $data['motif'], 'operations_perdues' => (int) ($data['operations_perdues'] ?? 0)]);

        return response()->json(['ok' => true]);
    }

    private function poste(Request $r): Device
    {
        return $r->attributes->get('poste');
    }
}
