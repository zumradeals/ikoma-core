<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Disponibilité du parcours de clôture du SUPERVISEUR (sortie et restitution d'une personne dont la catégorie est « à clôture réservée »).
 *
 * Tant que ce parcours n'existe pas (lot de clôture ultérieur), l'indicateur categories.closure_supervisor_only ne peut pas être ACTIVÉ par l'administration :
 * l'agent n'a pas le droit de clôturer ces personnes et personne d'autre n'a encore de chemin, donc l'activer rendrait toute sortie impossible et bloquerait
 * la clôture de l'activité. L'indicateur reste conservé (donnée métier explicite) et peut toujours être retiré. Cette disponibilité est un fait du code, pas un
 * réglage d'exploitation : elle ne devient vraie que lorsque le parcours est livré.
 */
final class SupervisorClosure
{
    public static function available(): bool
    {
        return false;
    }

    /** @throws ValidationException quand l'activation est demandée alors que le parcours du superviseur n'existe pas */
    public static function assertCanActivate(bool $requested, bool $alreadyActive): void
    {
        if ($requested && ! $alreadyActive && ! self::available()) {
            throw ValidationException::withMessages(['closure_supervisor_only' => 'Cette option sera disponible avec le parcours de clôture du superviseur. L’activer aujourd’hui rendrait toute sortie de ces personnes impossible.']);
        }
    }
}
