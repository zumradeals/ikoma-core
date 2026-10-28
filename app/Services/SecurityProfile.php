<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Profil de sécurité de l'installation. `renforce` active les contrôles les plus contraignants (2FA pour tous les rôles, double validation des actions
 * critiques, approbation des listes par l'officier de sécurité, numéro de pièce obligatoire). `standard` convient à la démonstration avec des données
 * fictives : il n'est pas adapté à des données réelles. Un réglage absent ou illisible vaut `renforce` (échec sûr).
 */
final class SecurityProfile
{
    public const STANDARD = 'standard';

    public const STRICT = 'renforce';

    public static function current(): string
    {
        $value = DB::table('settings')->where('key', 'profil_securite')->value('value');

        return in_array($value, [self::STANDARD, self::STRICT], true) ? $value : self::STRICT;
    }

    public static function strict(): bool
    {
        return self::current() === self::STRICT;
    }
}
