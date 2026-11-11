<?php

use App\Services\LegacyCardRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Reprise de l'ancien registre technique des cartes (étape 4 du retrait du conteneur).
 * La migration réelle a déjà été appliquée à la base de test : on reconstitue ici, avec le compte propriétaire et dans une transaction annulée à la fin,
 * un ancien registre (événement, catégorie, lot, participation, passages, carte, émission) et on vérifie ce que detach() en fait.
 */

function legacyOwner()
{
    $password = is_file(base_path('.secrets/db-owner')) ? trim((string) file_get_contents(base_path('.secrets/db-owner'))) : null;
    if ($password === null) {
        test()->markTestSkipped('Secret propriétaire indisponible dans cet environnement.');
    }
    config(['database.connections.owner' => [...config('database.connections.pgsql'), 'username' => 'ikoma', 'password' => $password]]);

    return DB::connection('owner');
}

/** @return array<string, string> identifiants créés */
function legacyBuild($owner, string $event): array
{
    $at = ['created_at' => now(), 'updated_at' => now()];
    $ids = ['category' => (string) Str::uuid(), 'batch' => (string) Str::uuid(), 'badge' => (string) Str::uuid(), 'visitor' => (string) Str::uuid(), 'holder' => (string) Str::uuid(), 'card' => (string) Str::uuid(), 'person' => (string) Str::uuid(), 'move' => (string) Str::uuid()];
    $owner->table('events')->insert(['id' => $event, 'name' => 'Ancien registre fictif', 'starts_at' => now(), 'ends_at' => now()->addYear(), ...$at]);
    $owner->table('categories')->insert(['id' => $ids['category'], 'event_id' => $event, 'name' => 'Catégorie ancienne', 'color' => '#336699', 'priority_circuit' => false, ...$at]);
    $owner->table('badge_batches')->insert(['id' => $ids['batch'], 'event_id' => $event, 'category_id' => $ids['category'], 'name' => 'Lot ancien', 'quantity' => 1, 'margin' => 0, ...$at]);
    $owner->table('permanent_holders')->insert(['id' => $ids['holder'], 'identifier' => 'T-000777-0', 'identifier_seq' => 777777, 'full_name' => 'Titulaire fictif ancien', 'status' => 'actif', ...$at]);
    $owner->table('visitors')->insert(['id' => $ids['visitor'], 'event_id' => $event, 'full_name' => 'Titulaire fictif ancien', 'category_id' => $ids['category'], 'valid_from' => now(), 'valid_until' => now()->addYear(), 'source' => 'listé', 'status' => 'attendu', 'holder_id' => $ids['holder'], 'host_validation' => 'accepte', ...$at]);
    $owner->table('badges')->insert(['id' => $ids['badge'], 'event_id' => $event, 'category_id' => $ids['category'], 'badge_batch_id' => $ids['batch'], 'code' => 'B-777', 'qr_token' => bin2hex(random_bytes(32)), 'status' => 'Disponible', 'printed_for_visitor_id' => $ids['visitor'], ...$at]);
    $owner->statement('ALTER TABLE holder_cards ADD COLUMN event_id uuid'); // colonne retirée par la migration : reconstituée le temps du test
    $owner->table('holder_cards')->insert(['id' => $ids['card'], 'holder_id' => $ids['holder'], 'badge_id' => $ids['badge'], 'event_id' => $event, 'status' => 'active', 'issued_at' => now(), 'rank' => 1, ...$at]);
    $owner->table('access_events')->insert(['id' => $ids['move'], 'event_id' => $event, 'visitor_id' => $ids['visitor'], 'type' => 'entree', 'checkpoint' => 'accueil', 'occurred_at' => now(), ...$at]);
    $owner->table('retired_badge_identifiers')->insert(['event_id' => $event, 'code' => 'B-776', 'token_hash' => hash('sha256', 'ancien')]);

    return $ids;
}

it('rattache les cartes de l’ancien registre au site et efface le reste du registre, sans toucher aux autres activités', function () {
    $owner = legacyOwner();
    $owner->beginTransaction();
    try {
        $event = (string) Str::uuid();
        $ids = legacyBuild($owner, $event);
        // Une autre activité, qui ne doit pas être touchée.
        $other = (string) Str::uuid();
        $owner->table('events')->insert(['id' => $other, 'name' => 'Activité fictive voisine', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        $result = (new LegacyCardRegistry($owner))->detach($event);

        expect($result['cartes'])->toBe(1);
        $badge = $owner->table('badges')->where('id', $ids['badge'])->first();
        expect($badge->event_id)->toBeNull()->and($badge->badge_batch_id)->toBeNull()->and($badge->category_id)->toBeNull()->and($badge->printed_for_visitor_id)->toBeNull()
            ->and($badge->code)->toBe('B-777');
        expect($owner->table('holder_cards')->where('id', $ids['card'])->value('event_id'))->toBeNull()
            ->and($owner->table('holder_cards')->where('id', $ids['card'])->value('badge_id'))->toBe($ids['badge']);
        expect($owner->table('retired_badge_identifiers')->where('code', 'B-776')->value('event_id'))->toBeNull();
        foreach (['events' => $event, 'categories' => $ids['category'], 'badge_batches' => $ids['batch'], 'visitors' => $ids['visitor'], 'access_events' => $ids['move']] as $table => $id) {
            expect($owner->table($table)->where('id', $id)->exists(), $table)->toBeFalse();
        }
        expect($owner->table('events')->where('id', $other)->exists())->toBeTrue();
        // Les protections « ajout seul » sont rétablies.
        $owner->statement('SAVEPOINT essai');
        try {
            $owner->statement("DELETE FROM holder_cards WHERE id = '{$ids['card']}'");
            $this->fail('La suppression d’une carte aurait dû être refusée.');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('ne se supprime pas');
        }
        $owner->statement('ROLLBACK TO SAVEPOINT essai');
    } finally {
        $owner->rollBack();
    }
});
