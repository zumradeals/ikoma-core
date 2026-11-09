<?php

namespace App\Http\Controllers;

use App\Services\BadgeArtwork;
use App\Services\CardModels;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « Modèles » de l'espace Badges et cartes : regroupe, sans nouvelle règle, le modèle de badge visiteur (par activité) et le modèle de carte permanente
 * (par catégorie institutionnelle). Les enregistrements passent par les routes existantes, avec leurs permissions : `badges.gerer` pour le badge visiteur,
 * `categories.gerer` pour enregistrer et valider une carte. Cette page ne fait que consulter.
 */
final class ModelesController
{
    public function index(Request $request, HolderCardsController $holderCards, HolderCards $cards, InstitutionalCategories $categories, CardModels $models): Response
    {
        Gate::authorize('badges.gerer');
        $input = $request->validate(['event' => 'nullable|uuid|exists:events,id', 'section' => 'nullable|in:badges,cartes']);
        $events = DB::table('events')->where('is_permanent', false)->orderBy('name')->get(['id', 'name']);
        $settings = DB::table('settings')->where('key', 'like', 'badge_atelier_%')->pluck('value', 'key');
        $preferences = [];
        foreach ($events as $event) {
            $preferences[$event->id] = BadgeArtwork::defaults();
            foreach ($preferences[$event->id] as $key => $default) {
                $preferences[$event->id][$key] = $settings->get('badge_atelier_'.$event->id.'_'.$key, $default);
            }
        }

        return Inertia::render('Admin/Modeles', [
            'events' => $events, 'preferences' => $preferences, 'initialEvent' => $input['event'] ?? null,
            'section' => ($input['section'] ?? 'cartes') === 'badges' ? 'badges' : 'cartes',
            'cards' => $holderCards->modelProps($request, $cards, $categories, $models),
        ]);
    }
}
