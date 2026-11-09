<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Institution;
use App\Services\PermanentHolders;
use App\Services\SupportPrinting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Atelier des badges, mode « Cartes permanentes » : choix des personnes et impression de leurs cartes.
 * Séparé de AtelierController, qui reste étranger au registre des personnes. Imprimer n'active jamais une carte.
 */
final class AtelierCartesController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');

        return response()->json(['cards' => $this->cards(), 'csrf' => csrf_token()])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Titulaires ayant une carte active : choix des cartes à imprimer (aucune donnée sensible).
     *
     * @return list<array<string, mixed>>
     */
    private function cards(): array
    {
        $rows = DB::table('holder_cards as k')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')
            ->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')
            ->where('k.status', 'active')->where('h.status', 'actif')->orderBy('h.full_name')->limit(500)
            ->get(['h.id', 'h.identifier', 'h.full_name', 'c.label as category', 'k.activated_at', 'k.id as card_id']);
        $printed = DB::table('holder_card_prints')->whereIn('card_id', $rows->pluck('card_id'))->select('card_id')->groupBy('card_id')->pluck('card_id')->flip();

        return $rows->map(fn ($r) => ['id' => $r->id, 'identifier' => $r->identifier, 'name' => $r->full_name, 'category' => $r->category, 'activated' => $r->activated_at !== null, 'printed' => $printed->has($r->card_id)])->all();
    }

    /** Document de plusieurs cartes permanentes : un tirage tracé, puis recto/verso de chaque carte. */
    public function document(Request $request, PermanentHolders $holders): Response
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
        $data = $request->validate(['holders' => 'required|array|min:1|max:50', 'holders.*' => 'required|uuid|distinct', 'reason' => 'nullable|string|max:200']);
        $context = $holders->permanentContext();
        abort_if($context === null, 422, 'Le contexte permanent n’existe pas encore.');
        /** @var User $user */
        $user = $request->user();
        $ids = array_values($data['holders']);
        // Impression par le contrat UNIQUE (SupportPrinting) : mêmes règles, même rendu, même traçage que pour les badges temporaires.
        $badgeIds = DB::table('holder_cards')->whereIn('holder_id', $ids)->where('status', 'active')->pluck('badge_id', 'holder_id');
        abort_if($badgeIds->count() !== count($ids), 404);
        $result = [app(SupportPrinting::class)->print($user, array_values(array_map(fn (string $h): string => (string) $badgeIds[$h], $ids)), $data['reason'] ?? null)['views']];
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);

        return response()->view('badges.holder-card-print', [
            'card' => null, 'cards' => $result[0], 'kind' => 'emission', 'alignment' => false, 'nonce' => $nonce, 'institution' => app(Institution::class)->profile()['name'], 'back' => '/administration/atelier?mode=cartes',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
