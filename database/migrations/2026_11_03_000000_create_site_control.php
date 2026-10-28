<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Un contrôle refusé sur un badge inconnu ou non associé n'a pas de visiteur : l'événement est tout de même enregistré (L7).
        DB::statement('ALTER TABLE access_events ALTER COLUMN visitor_id DROP NOT NULL');
        // Types utilisés : validation, entree (L5), controle, sortie, correction (L7). Une correction pointe l'événement corrigé par corrige_id.
        DB::statement("CREATE INDEX access_events_presence ON access_events (event_id, visitor_id, occurred_at DESC) WHERE type IN ('entree', 'sortie')");
        // Un événement ne peut être corrigé qu'une fois (la base le garantit, sans verrou : le compte applicatif n'a pas le droit UPDATE sur access_events).
        DB::statement('CREATE UNIQUE INDEX access_events_corrige ON access_events (corrige_id) WHERE corrige_id IS NOT NULL');
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_type_valid CHECK (type IN ('validation', 'entree', 'controle', 'sortie', 'correction'))");
        DB::statement("ALTER TABLE access_events ADD CONSTRAINT access_events_visitor_required CHECK (visitor_id IS NOT NULL OR type = 'controle')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_visitor_required');
        DB::statement('ALTER TABLE access_events DROP CONSTRAINT access_events_type_valid');
        DB::statement('DROP INDEX IF EXISTS access_events_corrige');
        DB::statement('DROP INDEX IF EXISTS access_events_presence');
        DB::statement('DELETE FROM access_events WHERE visitor_id IS NULL');
        DB::statement('ALTER TABLE access_events ALTER COLUMN visitor_id SET NOT NULL');
    }
};
