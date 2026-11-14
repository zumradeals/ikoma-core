<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Capacités d'un poste (S02, V1). Un agent ne dispose que des capacités du poste que tient sa tablette ; le rôle seul ne suffit plus.
 *
 * V1 : les postes sont des objets PERMANENTS du site (table `checkpoints`) et leur configuration est une DONNÉE, administrable sans toucher au code
 * (docs/ailct/V1-INVARIANTS-SITE-ET-CONTROLE.md). Le catalogue des capacités, lui, est celui qui est réellement implémenté ; le serveur refuse toute autre valeur.
 * Configuration par défaut (décision du propriétaire, lot A : les cinq postes physiques scannent les badges et les cartes ; les différences entre postes sont conservées) :
 *  - poste 2 : contrôle individuel à l'entrée, accueil (identité, badge, admission) et sortie ;
 *  - soute (point 5) : contrôle individuel, sens Entrée / Sortie obligatoire, rien d'autre ;
 *  - postes 1, 3 et 4 : contrôle individuel (scan) seulement ; ni accueil, ni sortie ;
 *  - tablette sans poste (ancien point « hotel », groupes et cars) : aucune capacité de poste ; les opérations de groupe restent autorisées par leurs permissions de rôle.
 * Un poste désactivé n'a plus aucune capacité. Poste non identifié (point_code nul) : AUCUNE capacité (refus par défaut).
 *
 * Capacités : « accueil » (identité, validation, refus, badge, entrée, non listé), « scan » (contrôle d'un badge), « sortie » : la sortie du site et la restitution du badge sont DEUX opérations distinctes (la restitution exige une personne déjà sortie et une vérification physique) ; elles partagent aujourd'hui la même porte de poste. Les séparer en deux capacités est une décision ouverte (journal des décisions).
 */
final class PostCapabilities
{
    /** Catalogue des capacités réellement implémentées (code => libellé). */
    public const CATALOGUE = [
        'accueil' => 'Accueil : identité, admission et attribution de badge',
        'scan' => 'Contrôle d’un badge (entrée ou passage)',
        'sortie' => 'Sortie du site (la restitution du badge reste une opération distincte, permise par la même capacité)',
    ];

    /** Point hérité des tablettes sans poste (groupes et cars). */
    public const NO_POST = 'hotel';

    /**
     * Postes du site (code => libellé), plus le point hérité « tablette sans poste ».
     *
     * @return array<string, string>
     */
    public static function points(bool $activeOnly = true): array
    {
        $rows = DB::table('checkpoints')->when($activeOnly, fn ($q) => $q->where('active', true))->orderBy('code')->get(['code', 'name', 'function_label']);
        $points = [];
        foreach ($rows as $row) {
            $points[$row->code] = $row->function_label ? $row->name.' — '.$row->function_label : $row->name;
        }
        $points[self::NO_POST] = 'Sans poste — tablette mobile (groupes et cars)';

        return $points;
    }

    /** @return list<string> capacités configurées d'un poste actif (ordre du catalogue) */
    public static function ofCheckpoint(string $code): array
    {
        $row = DB::table('checkpoints')->where('code', $code)->where('active', true)->first(['capabilities']);

        return $row === null ? [] : self::normalize(json_decode((string) $row->capabilities, true));
    }

    /**
     * @return list<string>
     */
    public static function normalize(mixed $values): array
    {
        $values = is_array($values) ? $values : [];

        return array_values(array_filter(array_keys(self::CATALOGUE), fn (string $capability): bool => in_array($capability, $values, true)));
    }

    /** Le poste exige-t-il le choix explicite Entrée / Sortie avant un scan (S10) ? */
    public static function requiresDirection(Device $poste): bool
    {
        if ($poste->point_code === null) {
            return false;
        }
        $row = DB::table('checkpoints')->where('code', $poste->point_code)->where('active', true)->first(['direction_required', 'capabilities']);

        return $row !== null && (bool) $row->direction_required && in_array('scan', self::normalize(json_decode((string) $row->capabilities, true)), true);
    }

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
        return $poste->point_code === null ? [] : self::ofCheckpoint($poste->point_code);
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
