<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index du classement stable par événement. Ne change ni données ni collation de la base.
 * Le prédicat reprend la règle métier : les visiteurs retirés ne sont pas proposés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX visitors_active_name_c_idx ON visitors (event_id, full_name COLLATE "C", id) WHERE withdrawn_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visitors_active_name_c_idx');
    }
};
