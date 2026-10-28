<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pièce d'identité présentée : son type et son numéro. Le numéro est chiffré par l'application (comme la photo) : il n'apparaît ni dans le journal
 * d'audit, ni dans les alertes, ni dans les files de synchronisation en clair. Les contrôles d'identité anciens et ceux du flux « groupe » n'en portent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE identity_checks ADD COLUMN document_type varchar(20) NULL, ADD COLUMN document_number text NULL');
        DB::statement("ALTER TABLE identity_checks ADD CONSTRAINT identity_document_type_valid CHECK (document_type IS NULL OR document_type IN ('cni','passeport','permis','carte_militaire','carte_professionnelle','autre'))");
        DB::statement('ALTER TABLE identity_checks ADD CONSTRAINT identity_document_pair CHECK ((document_type IS NULL) = (document_number IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE identity_checks DROP CONSTRAINT identity_document_pair, DROP CONSTRAINT identity_document_type_valid, DROP COLUMN document_number, DROP COLUMN document_type');
    }
};
