<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compteur de personnes présentes, calculé uniquement à partir des événements (jamais d'un compteur stocké) :
 * une personne est présente si son dernier événement non corrigé, parmi « entree » et « sortie », est une entrée.
 * Une correction est un nouvel événement (type « correction », corrige_id) : l'événement corrigé n'est plus compté, il n'est ni modifié ni supprimé.
 */
final class Presence
{
    public function __construct(private readonly Audit $audit) {}

    public function count(string $eventId): int
    {
        return (int) DB::selectOne(
            "SELECT count(*) AS n FROM (
                SELECT DISTINCT ON (e.visitor_id) e.type FROM access_events e
                WHERE e.event_id = ? AND e.type IN ('entree', 'sortie') AND e.visitor_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM access_events c WHERE c.corrige_id = e.id)
                ORDER BY e.visitor_id, e.occurred_at DESC, e.created_at DESC, e.id DESC
            ) last WHERE last.type = 'entree'",
            [$eventId],
        )->n;
    }

    public function visitorPresent(string $visitorId): bool
    {
        $last = DB::table('access_events as e')->where('e.visitor_id', $visitorId)->whereIn('e.type', ['entree', 'sortie'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as c')->whereColumn('c.corrige_id', 'e.id'))
            ->orderByDesc('e.occurred_at')->orderByDesc('e.created_at')->orderByDesc('e.id')->value('e.type');

        return $last === 'entree';
    }

    /**
     * Corrige un événement d'entrée ou de sortie saisi par erreur. Le statut du visiteur est recalculé depuis les événements restants.
     * Le badge n'est pas touché : corriger une sortie ne le réassocie pas (le droit et l'écran de correction viendront avec le pilotage, lot L8).
     */
    public function corriger(string $accessEventId, User $actor, string $motif): string
    {
        $motif = trim($motif);
        if ($motif === '') {
            throw new DeskRefused('motif_obligatoire', 'Le motif de la correction est obligatoire.');
        }

        try {
            return DB::transaction(function () use ($accessEventId, $actor, $motif): string {
                $event = DB::table('access_events')->where('id', $accessEventId)->first();
                if ($event === null || ! in_array($event->type, ['entree', 'sortie'], true)) {
                    throw new DeskRefused('correction_impossible', 'Seule une entrée ou une sortie peut être corrigée.');
                }
                if (DB::table('access_events')->where('corrige_id', $event->id)->exists()) {
                    throw new DeskRefused('deja_corrige', 'Cet événement a déjà été corrigé.', 409);
                }
                $id = (string) Str::uuid();
                $now = now();
                DB::table('access_events')->insert(['id' => $id, 'event_id' => $event->event_id, 'visitor_id' => $event->visitor_id, 'badge_id' => $event->badge_id, 'actor_id' => $actor->id, 'type' => 'correction', 'checkpoint' => null, 'occurred_at' => $now, 'corrige_id' => $event->id, 'details' => json_encode(['motif' => mb_substr($motif, 0, 500), 'corrige_type' => $event->type], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
                $last = DB::table('access_events as e')->where('e.visitor_id', $event->visitor_id)->whereIn('e.type', ['entree', 'sortie'])
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as c')->whereColumn('c.corrige_id', 'e.id'))
                    ->orderByDesc('e.occurred_at')->orderByDesc('e.created_at')->orderByDesc('e.id')->value('e.type');
                DB::table('visitors')->where('id', $event->visitor_id)->whereIn('status', ['attendu', 'present', 'sorti'])->update(['status' => match ($last) {
                    'entree' => 'present', 'sortie' => 'sorti', default => 'attendu'
                }, 'updated_at' => $now]);
                $this->audit->append('presence.corrigee', $actor->id, ['access_event_id' => $event->id, 'correction_id' => $id, 'visitor_id' => $event->visitor_id, 'event_id' => $event->event_id, 'type_corrige' => $event->type]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DeskRefused('deja_corrige', 'Cet événement a déjà été corrigé.', 409);
        }
    }
}
