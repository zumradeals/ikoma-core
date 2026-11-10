<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tablette permanente, étape 2 : une tablette peut exister SANS activité en cours (« tablette de site »).
 * - Les clés composites (tablette, activité) des badges réservés et des attributions pointent désormais sur `device_activities` : une tablette peut
 *   changer d'activité en cours sans toucher aux réservations de ses autres activités.
 * - `devices.event_id` devient « l'activité en cours » : facultatif, et obligatoirement choisie parmi les activités autorisées (clé étrangère différée).
 * - Le déclencheur d'autorisation ne joue plus qu'à la création d'une tablette (ancien écran) : une activité ne devient jamais autorisée par simple choix.
 * - La remise à zéro de démonstration d'une activité retire aussi les autorisations de cette activité.
 */
return new class extends Migration
{
    public function up(): void
    {
        $composite = DB::select(<<<'SQL'
SELECT c.conrelid::regclass::text AS tbl, c.conname AS name,
       (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = c.conrelid AND a.attnum = c.conkey[1]) AS device_col
FROM pg_constraint c
WHERE c.contype = 'f' AND c.confrelid = 'public.devices'::regclass AND array_length(c.conkey, 1) = 2
SQL);
        foreach ($composite as $fk) {
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $fk->tbl, $fk->name));
            DB::statement(sprintf('ALTER TABLE %1$s ADD CONSTRAINT %2$s FOREIGN KEY (%3$s, event_id) REFERENCES device_activities (device_id, event_id) ON DELETE RESTRICT', $fk->tbl, $fk->name, $fk->device_col));
        }

        DB::statement('ALTER TABLE devices ALTER COLUMN event_id DROP NOT NULL');
        DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_current_activity_authorized FOREIGN KEY (id, event_id) REFERENCES device_activities (device_id, event_id) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('DROP TRIGGER IF EXISTS devices_activity_sync ON devices');
        DB::statement('CREATE TRIGGER devices_activity_sync AFTER INSERT ON devices FOR EACH ROW EXECUTE FUNCTION public.ikoma_device_activity_sync()');

        $event = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_event(uuid)'::regprocedure) AS d")->d;
        $event = str_replace('DELETE FROM public.devices WHERE event_id = p_event;', "DELETE FROM public.devices WHERE event_id = p_event;\n    DELETE FROM public.device_activities WHERE event_id = p_event;", $event, $count);
        if ($count !== 1) {
            throw new RuntimeException('Fonction de remise à zéro (activité) inattendue : revue du contrat requise.');
        }
        DB::unprepared($event);
    }

    public function down(): void
    {
        throw new RuntimeException('Migration non réversible : des tablettes peuvent déjà exister sans activité en cours.');
    }
};
