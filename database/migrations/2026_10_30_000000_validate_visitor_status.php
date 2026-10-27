<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE visitors ADD CONSTRAINT visitors_status_valid CHECK (status IN ('attendu','present','sorti','refuse'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE visitors DROP CONSTRAINT visitors_status_valid');
    }
};
