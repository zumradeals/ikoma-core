<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Capacités d'un poste (S02). Un agent ne dispose que des capacités du point de contrôle que tient sa tablette ; le rôle seul ne suffit plus.
 *
 * Décisions validées (cahier des charges AILCT V1, §2 et §8) :
 *  - poste 1 : aucun scan ni confirmation numérique en V1 ;
 *  - poste 2 : contrôle individuel à l'entrée, accueil (identité, badge, admission) et sortie ;
 *  - soute : contrôle individuel bidirectionnel, rien d'autre.
 * Postes 3 et 4 : procédures numériques « à confirmer » (§13). Tant qu'elles ne sont pas décidées, ces points n'ont AUCUNE capacité (refus par défaut).
 * Poste non identifié (point_code nul) : AUCUNE capacité (refus par défaut). Le point de contrôle est obligatoire pour toute tablette
 * opérationnelle : à la création, à l'émission du code d'enrôlement, à l'enrôlement et, en base, pour tout appareil muni d'un jeton.
 *
 * Capacités : « accueil » (identité, validation, refus, badge, entrée, non listé), « scan » (contrôle d'un badge), « sortie » (sortie et restitution).
 */
final class PostCapabilities
{
    public const POINTS = ['poste_1' => 'Poste 1 — entrée principale', 'poste_2' => 'Poste 2 — accès terrestre', 'poste_3' => 'Poste 3 — chef du village', 'poste_4' => 'Poste 4 — Porton', 'soute' => 'Soute (point 5)'];

    private const ALLOWED = ['poste_2' => ['accueil', 'scan', 'sortie'], 'soute' => ['scan']];

    /**
     * Délai (en heures) pendant lequel une tablette sans réseau peut prendre une décision FAVORABLE (admission, badge, scan valide) sur sa copie locale.
     * Il n'existe que si l'institution a fixé un délai strictement positif ET l'a approuvé ; sinon 0 = aucune décision favorable hors ligne.
     * Une valeur nulle, absente ou non approuvée ne signifie jamais « illimité ». Les cartes permanentes ont leur propre règle (PermanentAccess).
     */
    public static function offlineTrustHours(): int
    {
        $hours = (int) (DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->value('value') ?? 0);
        $approved = (string) (DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->value('value') ?? '0') === '1';

        return $approved && $hours > 0 ? $hours : 0;
    }

    /** @return list<string> */
    public static function capabilities(Device $poste): array
    {
        return $poste->point_code === null ? [] : self::ALLOWED[$poste->point_code] ?? [];
    }

    public static function allows(Device $poste, string $capability): bool
    {
        return in_array($capability, self::capabilities($poste), true);
    }

    /** Refuse côté serveur, que l'appel vienne d'une route ou de la file de synchronisation. Exige aussi le rôle agent : un superviseur ne devient pas agent. */
    public static function require(Device $poste, User $agent, string $capability): void
    {
        if (! $agent->hasRole('agent_accueil')) {
            throw new DeskRefused('non_autorise', 'Action réservée à un agent d’accueil.', 403);
        }
        if ($poste->point_code === null) {
            throw new DeskRefused('poste_non_identifie', 'Ce poste n’est pas identifié : l’administrateur doit préciser son point de contrôle.', 403, 'Prévenez l’administrateur.');
        }
        if (! self::allows($poste, $capability)) {
            throw new DeskRefused('poste_non_habilite', 'Cette opération n’est pas permise à ce poste.', 403, 'Utilisez le poste prévu pour cette opération.');
        }
    }
}
