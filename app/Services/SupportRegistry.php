<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Registre commun des supports (V1, convergence « Badges et cartes ») : LECTURE SEULE.
 *
 * Une seule liste pour les cartes permanentes et les badges temporaires. Aucune table, aucun statut ni aucune règle n'est ajouté : le type, le titulaire et l'état sont DÉRIVÉS
 * des tables existantes (badges, holder_cards, permanent_holders, visitors, badge_print_items, holder_card_prints). Le jeton QR n'est jamais lu ici.
 * Les actions (émission, impression, réimpression, remplacement) passent par les contrats existants (BadgeIssuance, SupportPrinting).
 */
final class SupportRegistry
{
    public const PER_PAGE = 8;

    /** @var array<string, string> */
    public const STATES = [
        'emis' => 'Émis, non remis', 'actif' => 'Actif', 'associe' => 'Associé', 'disponible' => 'Disponible (stock)', 'restitue' => 'Restitué', 'remplace' => 'Remplacée', 'perdu' => 'Perdue', 'vole' => 'Volée',
        'revoque' => 'Révoquée', 'annule' => 'Annulé', 'clos' => 'Clos',
    ];

    /**
     * Type et état dérivés : une carte permanente est un support lié à un titulaire (carte du site, ou ancienne carte du registre technique) ; l'état d'une carte est celui de la carte, sinon celui du badge.
     * « Émis, non remis » : support nominatif généré mais pas encore remis (carte non activée, badge non associé) : émis ≠ imprimé ≠ remis ≠ actif.
     */
    private const TYPE = "CASE WHEN k.id IS NOT NULL OR e.is_permanent THEN 'carte_permanente' ELSE 'badge_temporaire' END";

    private const STATE = "CASE WHEN k.id IS NOT NULL THEN CASE k.status WHEN 'active' THEN CASE WHEN k.activated_at IS NULL THEN 'emis' ELSE 'actif' END WHEN 'retiree' THEN 'remplace' WHEN 'perdue' THEN 'perdu' WHEN 'volee' THEN 'vole' WHEN 'revoquee' THEN 'revoque' ELSE 'clos' END "
        ."ELSE CASE b.status WHEN 'Disponible' THEN CASE WHEN b.printed_for_visitor_id IS NOT NULL THEN 'emis' ELSE 'disponible' END WHEN 'Associé' THEN 'associe' WHEN 'Restitué' THEN 'restitue' WHEN 'Annulé' THEN 'annule' ELSE 'clos' END END";

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function list(array $filters, int $page): array
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $query = DB::table('badges as b')->leftJoin('events as e', 'e.id', '=', 'b.event_id')
            ->leftJoin('holder_cards as k', 'k.badge_id', '=', 'b.id')->leftJoin('permanent_holders as h', 'h.id', '=', 'k.holder_id')
            ->leftJoin('visitors as v', 'v.id', '=', 'b.printed_for_visitor_id')->leftJoin('categories as c', 'c.id', '=', 'b.category_id')
            ->leftJoin('institutional_categories as ic', 'ic.code', '=', 'h.institutional_category');
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(b.code) LIKE ?', [$like])->orWhereRaw('lower(coalesce(h.full_name, v.full_name, \'\')) LIKE ?', [$like])->orWhereRaw('lower(coalesce(h.identifier, \'\')) LIKE ?', [$like]));
        }
        $type = (string) ($filters['type'] ?? '');
        if (in_array($type, ['carte_permanente', 'badge_temporaire'], true)) {
            $query->whereRaw(self::TYPE.' = ?', [$type]);
        }
        $etat = (string) ($filters['etat'] ?? '');
        if (isset(self::STATES[$etat])) {
            $query->whereRaw(self::STATE.' = ?', [$etat]);
        }
        // Le stock de lots sans titulaire n'encombre pas le registre : visible sur demande (filtre « Disponible (stock) ») et par la recherche.
        if ($etat === '' && $term === '') {
            $query->whereRaw(self::STATE." <> 'disponible'");
        }
        $paginated = $query->orderByDesc('b.created_at')->orderBy('b.code')->select(['b.id', 'b.code', DB::raw("coalesce(e.name, 'Site') AS event_name"),
            DB::raw(self::TYPE.' AS type'), DB::raw(self::STATE.' AS state'),
            DB::raw('coalesce(h.full_name, v.full_name) AS holder_name'), DB::raw('coalesce(ic.label, c.name) AS category'),
            DB::raw('(EXISTS (SELECT 1 FROM badge_print_items i WHERE i.badge_id = b.id) OR EXISTS (SELECT 1 FROM holder_card_prints p JOIN holder_cards kk ON kk.id = p.card_id WHERE kk.badge_id = b.id)) AS imprime'),
        ])->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        $data = [];
        foreach ($paginated->items() as $r) {
            $data[] = ['id' => (string) $r->id, 'code' => (string) $r->code, 'type' => (string) $r->type, 'state' => (string) $r->state, 'state_label' => self::STATES[(string) $r->state] ?? 'Clos',
                'holder_name' => $r->holder_name, 'category' => $r->category, 'event_name' => (string) $r->event_name, 'printed' => (bool) $r->imprime];
        }

        return ['data' => $data, 'meta' => ['current_page' => $paginated->currentPage(), 'last_page' => $paginated->lastPage(), 'total' => $paginated->total(), 'per_page' => self::PER_PAGE]];
    }
}
