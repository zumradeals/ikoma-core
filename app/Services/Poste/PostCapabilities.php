<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;

/**
 * Capacités d'un poste (S02). Un agent ne dispose que des capacités du point de contrôle que tient sa tablette ; le rôle seul ne suffit plus.
 *
 * Décisions validées (cahier des charges AILCT V1, §2 et §8) :
 *  - poste 1 : aucun scan ni confirmation numérique en V1 ;
 *  - poste 2 : contrôle individuel à l'entrée, accueil (identité, badge, admission) et sortie ;
 *  - soute : contrôle individuel bidirectionnel, rien d'autre.
 * Postes 3 et 4 : procédures numériques « à confirmer » (§13). Tant qu'elles ne sont pas décidées, ces points n'ont AUCUNE capacité (refus par défaut).
 * Poste non typé (point_code nul) : poste hérité, comportement inchangé ; à typer avant toute mise en service réelle.
 *
 * Capacités : « accueil » (identité, validation, refus, badge, entrée, non listé), « scan » (contrôle d'un badge), « sortie » (sortie et restitution).
 */
final class PostCapabilities
{
    public const POINTS = ['poste_1' => 'Poste 1 — entrée principale', 'poste_2' => 'Poste 2 — accès terrestre', 'poste_3' => 'Poste 3 — chef du village', 'poste_4' => 'Poste 4 — Porton', 'soute' => 'Soute (point 5)'];

    private const ALLOWED = ['poste_2' => ['accueil', 'scan', 'sortie'], 'soute' => ['scan']];

    public static function allows(Device $poste, string $capability): bool
    {
        $point = $poste->point_code;

        return $point === null || in_array($capability, self::ALLOWED[$point] ?? [], true);
    }

    /** Refuse côté serveur, que l'appel vienne d'une route ou de la file de synchronisation. Exige aussi le rôle agent : un superviseur ne devient pas agent. */
    public static function require(Device $poste, User $agent, string $capability): void
    {
        if (! $agent->hasRole('agent_accueil')) {
            throw new DeskRefused('non_autorise', 'Action réservée à un agent d’accueil.', 403);
        }
        if (! self::allows($poste, $capability)) {
            throw new DeskRefused('poste_non_habilite', 'Cette opération n’est pas permise à ce poste.', 403, 'Utilisez le poste prévu pour cette opération.');
        }
    }
}
