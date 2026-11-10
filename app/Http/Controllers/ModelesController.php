<?php

namespace App\Http\Controllers;

use App\Services\CardModels;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « Modèles institutionnels » de l'espace Badges et cartes : un modèle par catégorie institutionnelle, pour les cartes permanentes et les badges temporaires.
 * L'enregistrement et la validation passent par les routes existantes (`categories.gerer`) ; cette page ne fait que consulter.
 */
final class ModelesController
{
    public function index(Request $request, HolderCardsController $holderCards, HolderCards $cards, InstitutionalCategories $categories, CardModels $models): Response
    {
        Gate::authorize('badges.gerer');

        return Inertia::render('Admin/Modeles', ['cards' => $holderCards->modelProps($request, $cards, $categories, $models)]);
    }
}
