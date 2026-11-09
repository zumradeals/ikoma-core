<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Compteurs de l'espace « Badges et cartes » (lecture seule, aucune donnée personnelle) : ils viennent des tables existantes et ne créent ni registre ni règle.
 * Les badges visiteurs et les cartes permanentes restent deux domaines distincts.
 */
final class BadgesCartesSummary
{
    /** @return array{visitors: array{available: int, to_return: int, batches: int, print_runs: int}, cards: array{holders: int, active: int, prints: int}, totals: array{supports_actifs: int, cartes_permanentes: int, badges_visiteurs: int, tirages_30j: int}} */
    public function get(): array
    {
        $visitorBadges = DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false);
        $toReturn = DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->whereNull('a.returned_at')->whereNull('a.cancelled_at')
            ->where(fn ($q) => $q->where('v.status', 'sorti')->orWhereNotNull('v.closed_at'))->count();

        // Supports actifs = cartes permanentes REMISES (actives) + badges temporaires associés : un support émis, imprimé ou disponible n'est pas encore actif.
        $cards = (int) DB::table('holder_cards')->where('status', 'active')->whereNotNull('activated_at')->count();
        $assigned = (int) (clone $visitorBadges)->where('b.status', 'Associé')->count();

        return [
            'totals' => ['supports_actifs' => $cards + $assigned, 'cartes_permanentes' => $cards, 'badges_visiteurs' => $assigned,
                'tirages_30j' => (int) DB::table('badge_print_runs')->where('generated_at', '>=', now()->subDays(30))->count()],
            'visitors' => [
                'available' => (int) (clone $visitorBadges)->where('b.status', 'Disponible')->count(),
                'to_return' => $toReturn,
                'batches' => DB::table('badge_batches as g')->join('events as e', 'e.id', '=', 'g.event_id')->where('e.is_permanent', false)->count(),
                'print_runs' => DB::table('badge_print_runs')->where('kind', 'visiteur')->count(),
            ],
            'cards' => [
                'holders' => (int) DB::table('permanent_holders')->where('status', 'actif')->count(),
                'active' => (int) DB::table('holder_cards')->where('status', 'active')->count(),
                'prints' => (int) DB::table('holder_card_prints')->count(),
            ],
        ];
    }
}
