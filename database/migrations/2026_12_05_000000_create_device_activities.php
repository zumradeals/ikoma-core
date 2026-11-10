<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablette permanente du site, étape 1 (additive) : l'AFFECTATION D'UNE TABLETTE À UNE ACTIVITÉ.
 * - Une ligne = « cette tablette est autorisée à travailler sur cette activité » (liste décidée par l'administrateur).
 * - Chaque tablette existante reçoit une ligne pour son événement actuel : aucune donnée ni aucun comportement ne change.
 * - Un déclencheur garde la table à jour tant que `devices.event_id` existe (création d'une tablette par l'ancien écran).
 * Les clés composites (tablette, événement) des badges réservés et des attributions seront reportées sur cette table à l'étape suivante,
 * en même temps que `devices.event_id` devient facultatif. Voir docs/ailct/V1-POSTE-MULTI-POSTES-EVENEMENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('device_id');
            $t->uuid('event_id');
            $t->timestampsTz();
            $t->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $t->foreign('event_id')->references('id')->on('events')->restrictOnDelete();
            $t->unique(['device_id', 'event_id']);
            $t->index('event_id');
        });
        DB::statement('INSERT INTO device_activities (id, device_id, event_id, created_at, updated_at) SELECT gen_random_uuid(), id, event_id, now(), now() FROM devices WHERE event_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.ikoma_device_activity_sync() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_id IS NOT NULL THEN
        INSERT INTO public.device_activities (id, device_id, event_id, created_at, updated_at)
        VALUES (gen_random_uuid(), NEW.id, NEW.event_id, now(), now())
        ON CONFLICT (device_id, event_id) DO NOTHING;
    END IF;
    RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE TRIGGER devices_activity_sync AFTER INSERT OR UPDATE OF event_id ON devices FOR EACH ROW EXECUTE FUNCTION public.ikoma_device_activity_sync()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS devices_activity_sync ON devices');
        DB::statement('DROP FUNCTION IF EXISTS public.ikoma_device_activity_sync()');
        Schema::dropIfExists('device_activities');
    }
};
