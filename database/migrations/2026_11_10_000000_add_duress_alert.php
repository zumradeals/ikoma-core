<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit global C2 : le code de contrainte saisi à la place du PIN d'une tablette ouvre le poste normalement et prévient le superviseur en silence.
 * L'alerte porte sur un poste (nouveau type de sujet) et reste critique.
 */
return new class extends Migration
{
    private const TYPES_BEFORE = "'refus','non_liste_attente','badge_annule','conflit_sync','hors_periode','car_arrive','groupe_incomplet','groupe_ecart','controle_refuse','zone_interdite','badge_double','controle_rafale'";

    public function up(): void
    {
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::TYPES_BEFORE.",'contrainte'))");
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_subject_valid');
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_subject_valid CHECK (subject_type IS NULL OR subject_type IN ('visiteur','badge','conflit','groupe','poste'))");
    }

    public function down(): void
    {
        DB::table('alerts')->where('type', 'contrainte')->delete();
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_subject_valid');
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_subject_valid CHECK (subject_type IS NULL OR subject_type IN ('visiteur','badge','conflit','groupe'))");
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT alerts_type_valid');
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_type_valid CHECK (type IN ('.self::TYPES_BEFORE.'))');
    }
};
