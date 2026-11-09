<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parcours de l'atelier (S10) : une page d'orientation, en lecture seule, qui range les parcours existants en DEUX domaines distincts — badges visiteurs
 * (réutilisables, rattachés aux activités) et cartes permanentes (liées à un titulaire). Aucun registre, aucune règle ni identité nouvelle : les nombres viennent
 * des tables existantes et chaque lien mène à un écran qui existe déjà.
 */
final class AtelierParcoursController
{
    public function index(): Response
    {
        Gate::authorize('badges.gerer');
        $visitorBadges = DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false);
        $byStatus = (clone $visitorBadges)->selectRaw('b.status, count(*) AS n')->groupBy('b.status')->pluck('n', 'status');
        $toReturn = DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->whereNull('a.returned_at')->whereNull('a.cancelled_at')
            ->where(fn ($q) => $q->where('v.status', 'sorti')->orWhereNotNull('v.closed_at'))->count();

        return Inertia::render('Admin/AtelierParcours', [
            'visitors' => [
                'batches' => DB::table('badge_batches as g')->join('events as e', 'e.id', '=', 'g.event_id')->where('e.is_permanent', false)->count(),
                'badges' => ['Disponible' => (int) ($byStatus['Disponible'] ?? 0), 'Associé' => (int) ($byStatus['Associé'] ?? 0), 'Restitué' => (int) ($byStatus['Restitué'] ?? 0), 'Annulé' => (int) ($byStatus['Annulé'] ?? 0), 'Clos' => (int) ($byStatus['Clos'] ?? 0)],
                'print_runs' => DB::table('badge_print_runs')->where('kind', 'visiteur')->count(),
                'to_return' => $toReturn,
            ],
            'cards' => [
                'holders' => (int) DB::table('permanent_holders')->where('status', 'actif')->count(),
                'active' => (int) DB::table('holder_cards')->where('status', 'active')->count(),
                'inactive' => (int) DB::table('holder_cards')->where('status', '<>', 'active')->count(),
                'prints' => (int) DB::table('holder_card_prints')->count(),
            ],
        ]);
    }
}
