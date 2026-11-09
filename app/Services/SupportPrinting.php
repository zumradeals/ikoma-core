<?php

namespace App\Services;

use App\Models\User;
use App\Services\Atelier\PrintTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Contrat d'IMPRESSION unique des supports émis (badges temporaires nominatifs et cartes permanentes) : un seul point d'entrée pour toute NOUVELLE impression ou
 * réimpression, un seul rendu (composeur de HolderCards + modèles institutionnels de CardModels), un seul traçage de tirage (PrintTrail : document généré → demandé → confirmé
 * par l'opérateur) et un historique par support (`badge_print_items`).
 *
 *  - Seuls les supports ÉMIS par le contrat d'émission (BadgeIssuance) s'impriment : jamais un support annulé, révoqué, restitué définitivement ou d'un titulaire clos.
 *  - Premier tirage d'un support = « émission » ; les suivants = « réimpression » avec motif obligatoire. Aucune réimpression ne crée de numéro, de jeton ni d'affectation,
 *    et ne réactive jamais un support perdu, volé ou révoqué.
 *  - Produire le document n'est PAS confirmer l'impression physique ni la remise ; aucune activation, aucune habilitation.
 *  - Les cartes continuent d'alimenter leur historique propre (holder_card_prints, via HolderCards::recordPrint) pour les écrans qui le lisent : structure de compatibilité.
 */
final class SupportPrinting
{
    public const MAX_PER_CALL = 100;

    public function __construct(private readonly HolderCards $cards, private readonly PrintTrail $trail, private readonly Audit $audit) {}

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('badges.gerer') && $actor->can('listes.importer'), 403);
    }

    /**
     * @param  list<string>  $badgeIds
     * @return list<string>
     */
    private function ids(array $badgeIds): array
    {
        $ids = array_values(array_unique($badgeIds));
        if ($ids === [] || count($ids) > self::MAX_PER_CALL) {
            throw ValidationException::withMessages(['badges' => 'Sélectionnez entre 1 et '.self::MAX_PER_CALL.' supports.']);
        }
        foreach ($ids as $id) {
            if (! Uuid::isValid($id)) {
                throw ValidationException::withMessages(['badges' => 'Support invalide.']);
            }
        }

        return $ids;
    }

    /**
     * État d'impression d'un support émis.
     *
     * @return array{badge_id: string, code: string, support_type: string, holder_id: ?string, event_id: string, printable: bool, refusal: ?string, kind: string, model_version: ?int}
     */
    private function describe(string $badgeId, bool $lock): array
    {
        $query = DB::table('badges')->where('id', $badgeId);
        $badge = ($lock ? $query->lockForUpdate() : $query)->first(['id', 'code', 'status', 'event_id']);
        $origin = $badge === null ? null : DB::table('badge_issuances')->where('badge_id', $badgeId)->whereIn('purpose', ['emission', 'remplacement'])->first(['support_type', 'holder_id']);
        // Carte émise avant le registre des émissions : son porteur est connu par la carte elle-même (aucune carte existante ne devient inimprimable).
        $origin ??= $badge === null ? null : DB::table('holder_cards')->where('badge_id', $badgeId)->selectRaw("'carte_permanente' as support_type, holder_id")->first();
        $out = ['badge_id' => $badgeId, 'code' => $badge->code ?? '', 'support_type' => $origin->support_type ?? '', 'holder_id' => $origin->holder_id ?? null, 'event_id' => $badge->event_id ?? '',
            'printable' => false, 'refusal' => null, 'kind' => 'emission', 'model_version' => null];
        if ($badge === null) {
            $out['refusal'] = 'Support introuvable.';
        } elseif ($origin === null) {
            $out['refusal'] = 'Ce support n’a pas été émis par « Préparer un badge » : il ne s’imprime pas ici.';
        } elseif ($origin->support_type === BadgeIssuance::CARD) {
            $card = DB::table('holder_cards as k')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->where('k.badge_id', $badgeId)->first(['k.id', 'k.status', 'h.status as holder_status', 'h.institutional_category']);
            if ($card === null || $card->status !== 'active') {
                $out['refusal'] = 'Cette carte est révoquée, perdue, volée ou remplacée : elle ne se réimprime pas (utilisez la carte active ou un remplacement).';
            } elseif ($card->holder_status !== 'actif') {
                $out['refusal'] = 'Ce titulaire est clos : sa carte ne s’imprime plus.';
            } else {
                $out['printable'] = true;
                $out['kind'] = DB::table('holder_card_prints')->where('card_id', $card->id)->exists() || DB::table('badge_print_items')->where('badge_id', $badgeId)->exists() ? 'reimpression' : 'emission';
                $out['model_version'] = $card->institutional_category === null ? null : app(CardModels::class)->forCategory($card->institutional_category)['version'];
            }
        } elseif (! in_array($badge->status, ['Disponible', 'Associé'], true)) {
            $out['refusal'] = 'Ce support est annulé, clos ou restitué définitivement : il ne s’imprime plus. Utilisez un remplacement.';
        } else {
            $out['printable'] = true;
            $out['kind'] = DB::table('badge_print_items')->where('badge_id', $badgeId)->exists() ? 'reimpression' : 'emission';
        }

        return $out;
    }

    /**
     * Récapitulatif avant impression (lecture seule) : pour chaque support, première impression ou réimpression, ou refus.
     *
     * @param  list<string>  $badgeIds
     * @return list<array<string, mixed>>
     */
    public function plan(User $actor, array $badgeIds): array
    {
        $this->authorize($actor);

        return array_map(fn (string $id): array => $this->describe($id, false), $this->ids($badgeIds));
    }

    /**
     * Aperçu recto/verso (lecture seule, sans tirage) : le même rendu que l'impression.
     *
     * @param  list<string>  $badgeIds
     * @return list<array<string, mixed>>
     */
    public function preview(User $actor, array $badgeIds): array
    {
        $this->authorize($actor);
        $views = [];
        foreach ($this->ids($badgeIds) as $id) {
            $d = $this->describe($id, false);
            if (! $d['printable']) {
                throw ValidationException::withMessages(['badges' => $d['refusal'] ?? 'Support non imprimable.']);
            }
            $views[] = $this->view($d);
        }
        $this->audit->append('badge.apercu', $actor->id, ['nombre' => count($views)]);

        return $views;
    }

    /** @param array<string, mixed> $d */
    private function view(array $d): array
    {
        return $d['support_type'] === BadgeIssuance::CARD ? $this->cards->cardData((string) $d['holder_id']) : $this->cards->temporaryData((string) $d['badge_id']);
    }

    /**
     * Impression ou réimpression : un tirage tracé, un historique par support, un rendu unique. Tout ou rien.
     *
     * @param  list<string>  $badgeIds
     * @return array{run: string, items: list<array{badge_id: string, code: string, kind: string}>, views: list<array<string, mixed>>}
     */
    public function print(User $actor, array $badgeIds, ?string $reason): array
    {
        $this->authorize($actor);
        $ids = $this->ids($badgeIds);
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && (mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1)) {
            throw ValidationException::withMessages(['reason' => 'Motif trop long ou contenant des caractères non acceptés (200 caractères au plus).']);
        }
        sort($ids);

        return DB::transaction(function () use ($actor, $ids, $reason): array {
            $described = [];
            foreach ($ids as $id) {
                $d = $this->describe($id, true);
                if (! $d['printable']) {
                    throw ValidationException::withMessages(['badges' => ($d['code'] !== '' ? $d['code'].' : ' : '').($d['refusal'] ?? 'Support non imprimable.')]);
                }
                if ($d['kind'] === 'reimpression' && $reason === null) {
                    throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la réimpression de '.$d['code'].' (200 caractères au plus).']);
                }
                $described[] = $d;
            }
            $allCards = collect($described)->every(fn (array $d): bool => $d['support_type'] === BadgeIssuance::CARD);
            $views = [];
            foreach ($described as $d) {
                $views[] = $this->view($d);
            }
            $run = $this->trail->generate($actor, ['kind' => $allCards ? 'permanent' : 'support', 'nature' => 'officiel', 'event_id' => $described[0]['event_id'], 'batch_id' => null, 'format' => 'cr80',
                'side' => 'recto', 'design' => null, 'mode' => null, 'planned_count' => count($described), 'first_number' => null, 'last_number' => null,
                'options' => ['supports' => count($described), 'badge_ids' => array_column($described, 'badge_id')]], $reason);
            $items = [];
            foreach ($described as $d) {
                if ($d['support_type'] === BadgeIssuance::CARD) {
                    // Historique propre aux cartes, lu par les écrans existants : même motif, même règle d'émission/réimpression.
                    $this->cards->recordPrint((string) $d['holder_id'], $reason, $actor);
                } else {
                    // Le QR est exposé sur un support imprimable : protection d'exposition portée par le lot technique du support (structure de compatibilité).
                    DB::table('badge_batches')->where('id', DB::table('badges')->where('id', $d['badge_id'])->value('badge_batch_id'))->update(['print_exposed_at' => now()]);
                }
                DB::table('badge_print_items')->insert(['id' => (string) Str::uuid(), 'print_run_id' => $run, 'badge_id' => $d['badge_id'], 'kind' => $d['kind'], 'reason' => $d['kind'] === 'reimpression' ? $reason : null,
                    'printed_by' => $actor->id, 'model_version' => $d['model_version'], 'created_at' => now()]);
                $items[] = ['badge_id' => $d['badge_id'], 'code' => $d['code'], 'kind' => $d['kind']];
            }
            $this->audit->append('badge.imprime', $actor->id, ['tirage' => $run, 'nombre' => count($items), 'emissions' => count(array_filter($items, fn (array $i): bool => $i['kind'] === 'emission')),
                'reimpressions' => count(array_filter($items, fn (array $i): bool => $i['kind'] === 'reimpression'))]);

            return ['run' => $run, 'items' => $items, 'views' => $views];
        });
    }
}
