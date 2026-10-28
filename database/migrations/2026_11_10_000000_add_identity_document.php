<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pièce d'identité photographiée (option de l'administrateur, désactivée par défaut : l'accueil reste « case cochée + photo du visiteur »).
 * Activée, l'agent indique le type de pièce et la photographie ; le numéro est facultatif. Le fichier image et le numéro sont chiffrés par
 * l'application (comme la photo) : ni le journal d'audit ni les alertes n'en contiennent. Les contrôles anciens et ceux du flux « groupe » n'en portent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE identity_checks ADD COLUMN document_type varchar(20) NULL, ADD COLUMN document_number text NULL, ADD COLUMN document_file varchar(120) NULL');
        DB::statement("ALTER TABLE identity_checks ADD CONSTRAINT identity_document_type_valid CHECK (document_type IS NULL OR document_type IN ('cni','passeport','permis','carte_militaire','carte_professionnelle','autre'))");
        DB::statement('ALTER TABLE identity_checks ADD CONSTRAINT identity_document_needs_type CHECK ((document_file IS NULL AND document_number IS NULL) OR document_type IS NOT NULL)');
        DB::table('settings')->insert(['key' => 'identite_piece_active', 'value' => '0']);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'identite_piece_active')->delete();
        DB::statement('ALTER TABLE identity_checks DROP CONSTRAINT identity_document_needs_type, DROP CONSTRAINT identity_document_type_valid, DROP COLUMN document_file, DROP COLUMN document_number, DROP COLUMN document_type');
    }
};
