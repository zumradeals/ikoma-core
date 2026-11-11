<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mouvements du SITE (tablette de site, lot 3) : entrées, sorties et contrôles du personnel permanent hors de toute activité, dans le même journal
 * `access_events` (événement vide). La présence du site est calculée uniquement à partir de ces mouvements (dernier mouvement non corrigé), jamais stockée ;
 * elle ne compte que le personnel permanent (les visiteurs restent comptés par activité). Une erreur se corrige par un événement « correction ».
 * Le contrôle à l'entrée qui écrit ces mouvements vient au lot 4 : ce service est pour l'instant exercé par les tests et l'écran de lecture.
 */
final class SiteMovements
{
    public const TYPES = ['entree', 'sortie', 'controle'];

    public function __construct(private readonly Audit $audit) {}

    /**
     * Enregistre un mouvement du site pour un titulaire (ou un contrôle sur une carte inconnue si `$holderId` est null).
     *
     * @param  array<string, mixed>  $details
     */
    public function record(?string $holderId, string $type, ?string $point, ?User $actor, ?Carbon $at = null, array $details = []): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Type de mouvement inconnu.']);
        }
        if ($holderId === null && $type !== 'controle') {
            throw ValidationException::withMessages(['holder_id' => 'Une entrée ou une sortie du site concerne une personne identifiée.']);
        }
        $point = $point === null ? null : mb_substr(trim($point), 0, 160);

        return DB::transaction(function () use ($holderId, $type, $point, $actor, $at, $details): string {
            $identifier = null;
            if ($holderId !== null) {
                $holder = DB::table('permanent_holders')->where('id', $holderId)->first(['id', 'identifier']);
                abort_unless($holder !== null, 404);
                $identifier = $holder->identifier;
            }
            $id = (string) Str::uuid();
            $now = now();
            DB::table('access_events')->insert(['id' => $id, 'event_id' => null, 'visitor_id' => null, 'badge_id' => null, 'holder_id' => $holderId, 'actor_id' => $actor?->id, 'type' => $type, 'checkpoint' => $point,
                'occurred_at' => $at ?? $now, 'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            if ($type === 'entree') {
                $this->audit->append('entree.site', $actor?->id, ['access_event_id' => $id, 'holder_id' => $holderId, 'identifiant' => $identifier, 'point' => $point]);
            }

            return $id;
        });
    }

    /**
     * Personnes présentes sur le site : dernier mouvement (entrée ou sortie) non corrigé = une entrée.
     *
     * @return list<object>
     */
    public function present(): array
    {
        return DB::select(
            "SELECT l.holder_id AS id, h.full_name, h.identifier, c.label AS category, l.checkpoint, l.occurred_at
             FROM (
                SELECT DISTINCT ON (e.holder_id) e.holder_id, e.type, e.checkpoint, e.occurred_at FROM access_events e
                WHERE e.event_id IS NULL AND e.holder_id IS NOT NULL AND e.type IN ('entree', 'sortie')
                  AND NOT EXISTS (SELECT 1 FROM access_events x WHERE x.corrige_id = e.id)
                ORDER BY e.holder_id, e.occurred_at DESC, e.seq DESC
             ) l JOIN permanent_holders h ON h.id = l.holder_id LEFT JOIN institutional_categories c ON c.code = h.institutional_category
             WHERE l.type = 'entree' ORDER BY public.ikoma_normalize(h.full_name)",
        );
    }

    /** Vrai si le dernier mouvement (entrée ou sortie) non corrigé de ce titulaire sur le site est une entrée. */
    public function isPresent(string $holderId): bool
    {
        $last = DB::table('access_events as e')->whereNull('e.event_id')->where('e.holder_id', $holderId)->whereIn('e.type', ['entree', 'sortie'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as x')->whereColumn('x.corrige_id', 'e.id'))
            ->orderByDesc('e.occurred_at')->orderByDesc('e.seq')->value('e.type');

        return $last === 'entree';
    }

    /**
     * Derniers mouvements du site (entrées, sorties, contrôles, corrections), du plus récent au plus ancien, pour la supervision.
     *
     * @return list<object>
     */
    public function recent(int $limit = 30): array
    {
        return DB::select(
            "SELECT e.id, e.type, e.checkpoint, e.occurred_at, h.identifier, h.full_name,
                    EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id) AS corrected
             FROM access_events e LEFT JOIN permanent_holders h ON h.id = e.holder_id
             WHERE e.event_id IS NULL AND e.type IN ('entree', 'sortie', 'controle', 'correction')
             ORDER BY e.occurred_at DESC, e.seq DESC LIMIT ?",
            [$limit],
        );
    }

    public function count(): int
    {
        return count($this->present());
    }

    /** Corrige une entrée ou une sortie du site saisie par erreur (motif obligatoire, une seule fois) ; la présence est recalculée. */
    public function correct(string $movementId, User $actor, string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DeskRefused('motif_obligatoire', 'Le motif de la correction est obligatoire.');
        }

        try {
            return DB::transaction(function () use ($movementId, $actor, $reason): string {
                $movement = DB::table('access_events')->where('id', $movementId)->whereNull('event_id')->first();
                if ($movement === null || ! in_array($movement->type, ['entree', 'sortie'], true)) {
                    throw new DeskRefused('correction_impossible', 'Seule une entrée ou une sortie du site peut être corrigée.');
                }
                if (DB::table('access_events')->where('corrige_id', $movement->id)->exists()) {
                    throw new DeskRefused('deja_corrige', 'Ce mouvement a déjà été corrigé.', 409);
                }
                $id = (string) Str::uuid();
                $now = now();
                DB::table('access_events')->insert(['id' => $id, 'event_id' => null, 'visitor_id' => null, 'badge_id' => null, 'holder_id' => $movement->holder_id, 'actor_id' => $actor->id, 'type' => 'correction', 'checkpoint' => null,
                    'occurred_at' => $now, 'corrige_id' => $movement->id, 'details' => json_encode(['motif' => mb_substr($reason, 0, 500), 'corrige_type' => $movement->type], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
                $this->audit->append('presence_site.corrigee', $actor->id, ['access_event_id' => $movement->id, 'correction_id' => $id, 'holder_id' => $movement->holder_id, 'type_corrige' => $movement->type]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DeskRefused('deja_corrige', 'Ce mouvement a déjà été corrigé.', 409);
        }
    }
}
