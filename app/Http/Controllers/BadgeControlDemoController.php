<?php

namespace App\Http\Controllers;

use App\Services\BadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Route de démonstration du lot L4, à supprimer en L5 quand l’accueil appellera BadgeService.
 * Moindre pouvoir : l’administrateur voit le verdict, la catégorie et les zones, jamais l’identité de la personne.
 */
final class BadgeControlDemoController
{
    public function __invoke(Request $r, BadgeService $badges): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $data = $r->validate(['code' => 'required|string|max:100', 'event_id' => 'nullable|uuid']);
        $verdict = $badges->controler($data['code'], $data['event_id'] ?? null, $r->user());

        return response()->json(['valide' => $verdict->valid, 'raison' => $verdict->reason, 'message' => $verdict->message, 'categorie' => $verdict->category['name'] ?? null, 'zones' => $verdict->zones]);
    }
}
