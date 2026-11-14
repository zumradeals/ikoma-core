<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Décision du propriétaire (lot A, correction du contrôle aux cinq postes) : les cinq postes physiques de l'AILCT peuvent scanner les badges et les cartes.
 *
 * Reprise de configuration des installations existantes : la capacité « scan » est AJOUTÉE aux cinq postes qui ne l'ont pas encore ; rien d'autre n'est modifié.
 * Ni l'accueil (Poste 2 seul par défaut), ni la sortie (Poste 2 seul par défaut), ni le sens obligatoire (Soute), ni le nom, l'activation, les zones gardées ou toute
 * autre capacité déjà réglée par l'administrateur ne sont touchés. Idempotent : une seconde exécution ne change rien. Le déclencheur `checkpoints_guard` incrémente
 * la version du poste ; la copie des tablettes change de version (elle inclut les capacités) et chaque tablette se met à jour à sa prochaine synchronisation.
 *
 * Nouvelles installations : cette migration s'exécute après la création des postes, la configuration par défaut est donc celle d'ici (voir docs/MATRICE_DROITS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE checkpoints SET capabilities = capabilities || '[\"scan\"]'::jsonb WHERE code IN ('poste_1', 'poste_2', 'poste_3', 'poste_4', 'soute') AND NOT jsonb_exists(capabilities, 'scan')");
    }

    /**
     * Retour à la configuration du contrat V1 antérieur pour les postes 1, 3 et 4 seulement (là où le scan était absent) ; les postes 2 et Soute l'avaient déjà.
     * Un poste qui impose un sens garde « scan » (contrainte de la base) : il n'est pas touché.
     */
    public function down(): void
    {
        DB::statement("UPDATE checkpoints SET capabilities = capabilities - 'scan' WHERE code IN ('poste_1', 'poste_3', 'poste_4') AND NOT direction_required");
    }
};
