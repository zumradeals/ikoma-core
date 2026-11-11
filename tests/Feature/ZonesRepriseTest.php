<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Reprise des zones d'activité vers les zones du site (Z3). La migration réelle est déjà appliquée à la base de test : on reconstitue ici, avec le compte
 * propriétaire et dans une transaction annulée à la fin, des zones d'activité (anciens noms, politiques, références) puis on rejoue la migration.
 */

function repriseOwner()
{
    $password = is_file(base_path('.secrets/db-owner')) ? trim((string) file_get_contents(base_path('.secrets/db-owner'))) : null;
    if ($password === null) {
        test()->markTestSkipped('Secret propriétaire indisponible dans cet environnement.');
    }
    config(['database.connections.owner' => [...config('database.connections.pgsql'), 'username' => 'ikoma', 'password' => $password]]);

    return DB::connection('owner');
}

function repriseRun($owner): void
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('owner');
    try {
        (require base_path('database/migrations/2026_12_14_000000_move_activity_zones_to_site.php'))->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

it('fusionne les zones de même nom, garde la politique la plus restrictive et redirige les références', function () {
    $owner = repriseOwner();
    $owner->beginTransaction();
    try {
        $at = ['created_at' => now(), 'updated_at' => now()];
        $site = (string) $owner->table('sites')->orderBy('created_at')->value('id');
        [$a, $b] = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ([$a, $b] as $i => $event) {
            $owner->table('events')->insert(['id' => $event, 'name' => 'Activité fictive '.$i, 'starts_at' => now(), 'ends_at' => now()->addDay(), ...$at]);
        }
        $siteZone = (string) Str::uuid();
        $owner->table('zones')->insert(['id' => $siteZone, 'event_id' => null, 'site_id' => $site, 'name' => 'Réfectoire reprise', ...$at]);
        $owner->table('site_zone_policies')->insert(['zone_id' => $siteZone, 'classification' => 'Interne', 'permanent_allowed' => true, 'offline_entry_allowed' => true, ...$at]);
        $zoneA = (string) Str::uuid();
        $zoneB = (string) Str::uuid();
        $hallA = (string) Str::uuid();
        $hallB = (string) Str::uuid();
        $owner->table('zones')->insert([
            ['id' => $zoneA, 'event_id' => $a, 'site_id' => $site, 'name' => 'Réfectoire reprise', 'est_accueil' => false, ...$at],
            ['id' => $zoneB, 'event_id' => $b, 'site_id' => $site, 'name' => 'Réfectoire reprise', 'est_accueil' => false, ...$at],
            ['id' => $hallA, 'event_id' => $a, 'site_id' => $site, 'name' => 'Hall reprise', 'est_accueil' => true, ...$at],
            ['id' => $hallB, 'event_id' => $b, 'site_id' => $site, 'name' => 'Hall reprise', 'est_accueil' => false, ...$at],
        ]);
        $owner->table('zones')->where('id', $hallA)->update(['created_at' => now()->subDay()]); // la plus ancienne est retenue
        $owner->table('zone_policies')->insert([
            ['zone_id' => $zoneB, 'event_id' => $b, 'classification' => 'Restreint', 'permanent_allowed' => true, 'offline_entry_allowed' => false, ...$at],
            ['zone_id' => $hallA, 'event_id' => $a, 'classification' => 'Public', 'permanent_allowed' => true, 'offline_entry_allowed' => true, ...$at],
        ]);
        $categories = [];
        foreach ([$a, $b] as $event) {
            $category = (string) Str::uuid();
            $categories[$event] = $category;
            $owner->table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Catégorie reprise', 'color' => '#336699', 'priority_circuit' => false, ...$at]);
        }
        $owner->table('category_zone')->insert([
            ['id' => (string) Str::uuid(), 'event_id' => $a, 'category_id' => $categories[$a], 'zone_id' => $zoneA, ...$at],
            ['id' => (string) Str::uuid(), 'event_id' => $a, 'category_id' => $categories[$a], 'zone_id' => $hallA, ...$at],
            ['id' => (string) Str::uuid(), 'event_id' => $b, 'category_id' => $categories[$b], 'zone_id' => $zoneB, ...$at],
        ]);
        $checkpoint = (string) $owner->table('checkpoints')->orderBy('code')->value('id');
        $owner->table('checkpoint_zones')->insert([
            ['id' => (string) Str::uuid(), 'event_id' => $a, 'checkpoint_id' => $checkpoint, 'zone_id' => $zoneA, ...$at],
            ['id' => (string) Str::uuid(), 'event_id' => $b, 'checkpoint_id' => $checkpoint, 'zone_id' => $zoneB, ...$at],
        ]);
        $revision = (int) $owner->table('events')->where('id', $a)->value('list_revision');

        repriseRun($owner);

        // Une seule zone « Réfectoire reprise » : la zone du site existante ; plus aucune zone d'activité pour ces noms.
        expect($owner->table('zones')->where('name', 'Réfectoire reprise')->pluck('id')->all())->toBe([$siteZone]);
        expect($owner->table('zones')->whereIn('id', [$zoneA, $zoneB, $hallB])->exists())->toBeFalse();
        // Hall : la plus ancienne zone retenue, devenue zone du site, d'accueil car l'une des deux l'était.
        $hall = $owner->table('zones')->where('name', 'Hall reprise')->get();
        expect($hall)->toHaveCount(1)->and($hall[0]->id)->toBe($hallA)->and($hall[0]->event_id)->toBeNull()->and((bool) $hall[0]->est_accueil)->toBeTrue();
        // Politiques : la plus restrictive.
        $refectoire = $owner->table('site_zone_policies')->where('zone_id', $siteZone)->first();
        expect((bool) $refectoire->permanent_allowed)->toBeTrue()->and((bool) $refectoire->offline_entry_allowed)->toBeFalse()->and($refectoire->classification)->toBe('Interne');
        $hallPolicy = $owner->table('site_zone_policies')->where('zone_id', $hallA)->first();
        expect((bool) $hallPolicy->permanent_allowed)->toBeFalse()->and((bool) $hallPolicy->offline_entry_allowed)->toBeFalse(); // la zone B n'avait aucune politique : refus par défaut
        expect($owner->table('zone_policies')->count())->toBe(0);
        // Références redirigées, sans doublon.
        expect($owner->table('category_zone')->where('category_id', $categories[$a])->pluck('zone_id')->sort()->values()->all())->toBe(collect([$siteZone, $hallA])->sort()->values()->all());
        expect($owner->table('category_zone')->where('category_id', $categories[$b])->pluck('zone_id')->all())->toBe([$siteZone]);
        expect($owner->table('checkpoint_zones')->where('checkpoint_id', $checkpoint)->where('zone_id', $siteZone)->count())->toBe(1);
        // Les activités rechargent leur copie, et le récapitulatif est au journal d'audit.
        expect((int) $owner->table('events')->where('id', $a)->value('list_revision'))->toBe($revision + 1);
        expect($owner->table('audit_logs')->where('action', 'zones.reprise_vers_site')->exists())->toBeTrue();
        // Les protections ajout seul sont rétablies : le déclencheur de portée refuse encore une zone d'une autre activité.
        $other = (string) Str::uuid();
        $owner->table('zones')->insert(['id' => $other, 'event_id' => $b, 'site_id' => $site, 'name' => 'Zone propre reprise', ...$at]);
        $owner->statement('SAVEPOINT essai');
        try {
            $owner->table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $a, 'category_id' => $categories[$a], 'zone_id' => $other, ...$at]);
            $this->fail('Une zone d’une autre activité aurait dû être refusée.');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('sa propre activité');
        }
        $owner->statement('ROLLBACK TO SAVEPOINT essai');
    } finally {
        $owner->rollBack();
    }
});
