<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')->whereNotNull('revoked_at')->update(['wipe_acknowledged_at' => null]);
        Schema::table('badge_batches', fn (Blueprint $t) => $t->timestampTz('print_exposed_at')->nullable());
        // L'impression historique n'était pas enregistrée : impossible de certifier ces lots inutilisés.
        DB::table('badge_batches')->update(['print_exposed_at' => now()]);
        Schema::create('retired_badge_identifiers', function (Blueprint $t) {
            $t->uuid('event_id'); // conservé même après suppression de l'événement
            $t->string('code', 40);
            $t->char('token_hash', 64)->unique();
            $t->primary(['event_id', 'code']);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.ikoma_retired_badge_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        INSERT INTO retired_badge_identifiers(event_id, code, token_hash) VALUES (OLD.event_id, OLD.code, encode(sha256(OLD.qr_token::bytea), 'hex'));
        RETURN OLD;
    END IF;
    IF EXISTS (SELECT 1 FROM retired_badge_identifiers WHERE (event_id = NEW.event_id AND code = NEW.code) OR token_hash = encode(sha256(NEW.qr_token::bytea), 'hex')) THEN
        RAISE EXCEPTION 'Identifiant de badge retiré non réattribuable.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER retired_badge_delete AFTER DELETE ON badges FOR EACH ROW EXECUTE FUNCTION public.ikoma_retired_badge_guard();
CREATE TRIGGER retired_badge_insert BEFORE INSERT OR UPDATE OF code, qr_token ON badges FOR EACH ROW EXECUTE FUNCTION public.ikoma_retired_badge_guard();
SQL);
        Schema::create('maintenance_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 30);
            $t->char('fingerprint', 64);
            $t->timestampTz('expires_at');
            $t->string('status', 30)->default('en_attente');
            $t->jsonb('result')->nullable();
            $t->timestampsTz();
        });
        Schema::create('pending_file_deletions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('path')->unique();
            $t->timestampTz('completed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retour destructif interdit : conserver les identifiants retirés et les demandes de maintenance.');
    }
};
