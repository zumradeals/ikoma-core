<?php

namespace App\Http\Controllers;

use App\Services\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Nombre de personnes présentes pour le superviseur (l'écran de pilotage arrive au lot L8). */
final class PresencesController
{
    public function __invoke(Request $r, Presence $presence): JsonResponse
    {
        $data = $r->validate(['event_id' => 'required|uuid|exists:events,id']);

        return response()->json(['event_id' => $data['event_id'], 'count' => $presence->count($data['event_id']), 'as_of' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')]);
    }
}
