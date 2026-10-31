<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F06 reste un droit fondateur permanent ; seule son exploitation est facultative.
 * Installation neuve : désactivé. Migration d'une installation déjà utilisée :
 * conserver les groupes historiques opérationnels, sans effacer leurs données.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingGroups = DB::table('groups')->exists();
        DB::table('settings')->insertOrIgnore([
            'key' => 'module_f06_active',
            'value' => $existingGroups ? '1' : '0',
        ]);
    }

    public function down(): void
    {
        // On ne supprime pas l'état d'exploitation après activation en production.
        // Un retour destructif de cette décision contractuelle exige une migration explicite.
    }
};
