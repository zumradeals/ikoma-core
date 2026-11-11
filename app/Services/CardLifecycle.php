<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie des cartes permanentes (A06) : déclaration de perte ou de vol, révocation, remplacement.
 *
 * Titulaire permanent ≠ carte physique ≠ identifiant imprimé ≠ jeton QR ≠ habilitation.
 * - Le titulaire et son identifiant imprimé (A04) ne changent jamais, quoi qu'il arrive à la carte.
 * - Une carte perdue, volée ou révoquée est TERMINALE : un déclencheur en base interdit toute réactivation. Son badge technique passe à « Annulé » par le service existant
 *   (même statut, même journal) : le contrôle serveur, la copie hors ligne des tablettes et les alertes la refusent comme tout badge annulé.
 * - Un remplacement révoque l'ancienne carte puis émet une NOUVELLE carte : nouveau badge, nouveau code B-nnn (jamais recyclé), nouveau jeton QR, nouveau rang.
 * - Une réimpression ordinaire (A05) conserve la carte, son badge et son QR ; elle n'a rien à voir avec un remplacement.
 * Le motif libre peut contenir des informations personnelles : il reste au registre des cartes et n'entre pas dans le journal d'audit.
 */
final class CardLifecycle
{
    public const REASONS = ['perdue' => 'Carte perdue', 'volee' => 'Carte volée', 'compromise' => 'Carte compromise', 'autre' => 'Autre motif'];

    public function __construct(private readonly HolderCards $cards, private readonly Audit $audit) {}

    /** @return list<object> toutes les cartes du titulaire, de la plus récente à la plus ancienne, avec leurs tirages. */
    public function history(string $holderId): array
    {
        $cards = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->leftJoin('users as r', 'r.id', '=', 'k.revoked_by')
            ->where('k.holder_id', $holderId)->orderByDesc('k.rank')
            ->get(['k.id', 'k.rank', 'k.status', 'k.issued_at', 'k.revoked_at', 'k.revocation_reason', 'k.revocation_note', 'k.replaced_by_card_id', 'b.code as badge_code', 'b.status as badge_status', 'r.name as revoked_by']);
        foreach ($cards as $card) {
            $card->prints = DB::table('holder_card_prints as p')->leftJoin('users as u', 'u.id', '=', 'p.printed_by')->where('p.card_id', $card->id)
                ->orderByRaw("p.kind = 'emission' DESC")->orderBy('p.created_at')->orderBy('p.id')->get(['p.id', 'p.kind', 'p.reason', 'p.created_at', 'u.name as by'])->all();
        }

        return $cards->all();
    }

    /** Déclare une carte perdue ou volée, ou la révoque (compromission, autre) : effet immédiat et définitif. */
    public function revoke(string $holderId, string $reason, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($holderId, $reason, $note, $actor): void {
            $this->revokeActive($holderId, $reason, $note, $actor);
        });
    }

    /**
     * Remplace la carte : révoque la carte active (si elle existe encore) puis émet une nouvelle carte. Fonctionne aussi après une déclaration de perte déjà faite.
     *
     * @return string identifiant de la nouvelle carte
     */
    public function replace(string $holderId, string $reason, ?string $note, User $actor, bool $activate = true, ?string $operationId = null): string
    {
        return DB::transaction(function () use ($holderId, $reason, $note, $actor, $activate, $operationId): string {
            $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['holder' => 'Ce titulaire est clos : aucune carte ne peut lui être émise.']);
            }
            $active = DB::table('holder_cards')->where('holder_id', $holderId)->where('status', 'active')->exists();
            if ($active) {
                $this->revokeActive($holderId, $reason, $note, $actor);
            } elseif (! DB::table('holder_cards')->where('holder_id', $holderId)->exists()) {
                throw ValidationException::withMessages(['holder' => 'Aucune carte à remplacer : émettez d’abord la carte.']);
            }
            $previous = DB::table('holder_cards')->where('holder_id', $holderId)->whereNull('replaced_by_card_id')->where('status', '<>', 'active')->orderByDesc('rank')->value('id');
            $replacedBadge = $previous === null ? null : DB::table('holder_cards')->where('id', $previous)->value('badge_id');
            $new = $this->cards->issue($holderId, $actor, $activate, $operationId, 'remplacement', $replacedBadge);
            if ($previous !== null) {
                DB::table('holder_cards')->where('id', $previous)->update(['replaced_by_card_id' => $new, 'updated_at' => now()]);
            }
            $this->audit->append('carte.remplacee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'ancienne' => $previous, 'nouvelle' => $new]);

            return $new;
        });
    }

    private function revokeActive(string $holderId, string $reason, ?string $note, User $actor): void
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Motif inconnu.']);
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && $note !== '' && (mb_strlen($note) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $note) === 1)) {
            throw ValidationException::withMessages(['note' => 'Précision trop longue ou contenant des caractères non acceptés (200 caractères au plus).']);
        }
        if ($reason === 'autre' && ($note === null || $note === '')) {
            throw ValidationException::withMessages(['note' => 'Précisez le motif de la révocation.']);
        }
        $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
        abort_unless($holder !== null, 404);
        $card = DB::table('holder_cards')->where('holder_id', $holderId)->where('status', 'active')->lockForUpdate()->first();
        if ($card === null) {
            throw ValidationException::withMessages(['holder' => 'Ce titulaire n’a pas de carte active à révoquer.']);
        }
        $status = match ($reason) {
            'perdue' => 'perdue',
            'volee' => 'volee',
            default => 'revoquee',
        };
        DB::table('holder_cards')->where('id', $card->id)->update(['status' => $status, 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revocation_reason' => $reason, 'revocation_note' => $note === '' ? null : $note, 'updated_at' => now()]);
        // Le badge technique devient « Annulé » : il ne peut plus jamais être associé ni accepté, et son code et son jeton restent réservés à jamais.
        $affected = DB::table('badges')->where('id', $card->badge_id)->where('status', 'Disponible')->update(['status' => 'Annulé', 'cancellation_reason' => self::REASONS[$reason].' (carte permanente)', 'updated_at' => now()]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['holder' => 'Le badge de cette carte n’est plus dans un état annulable : revue manuelle nécessaire.']);
        }
        $this->audit->append('badge.annule', $actor->id, ['badge_id' => $card->badge_id, 'carte_permanente' => true]);
        $this->audit->append('carte.revoquee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'card_id' => $card->id, 'motif' => $reason, 'statut' => $status]);
    }
}
