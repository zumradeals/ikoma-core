<?php

use App\Enums\BadgeStatus;
use App\Models\Badge;
use App\Models\User;
use App\Services\VisitorSearch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function businessAccount(string $role): User
{
    $user = User::create(['name' => 'Compte fictif L2', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
});
it('charge un jeu fictif complet et idempotent', function () {
    $this->seed(DemoSeeder::class);
    $e = DemoSeeder::id('event');
    foreach (['events' => 1, 'zones' => 6, 'categories' => 4, 'hosts' => 3, 'visitors' => 300, 'badge_batches' => 1, 'badges' => 400, 'groups' => 1, 'group_members' => 40, 'devices' => 1] as $table => $count) {
        $query = DB::table($table);
        if ($table === 'events') {
            $query->where('id', $e);
        } elseif ($table === 'group_members') {
            $query->where('group_id', DemoSeeder::id('group'));
        } else {
            $query->where('event_id', $e);
        }
        expect($query->count())->toBe($count);
    }
    expect(DB::table('badges')->where('event_id', $e)->whereNotNull('reserved_device_id')->count())->toBe(60);
    expect(DB::table('categories')->where('id', DemoSeeder::id('category0'))->value('priority_circuit'))->toBeTrue();
    expect(DB::table('visitors')->where('group_id', DemoSeeder::id('group'))->distinct()->pluck('category_id')->all())->toBe([DemoSeeder::id('category3')]);
    expect(DB::table('visitors')->where('event_id', $e)->where('full_name', 'Yao Brou')->count())->toBeGreaterThan(1);
});
it('retrouve accents et fautes, trie les résultats et ne divulgue que le nécessaire', function () {
    $search = app(VisitorSearch::class);
    $e = DemoSeeder::id('event');
    expect($search->search($e, 'kone mariam')[0]['full_name'])->toBe('Koné Mariam');
    expect($search->search($e, 'Yaw Brou')[0]['full_name'])->toBe('Yao Brou');
    $results = $search->search($e, 'Yao Brou');
    expect($results[0]['full_name'])->toBe('Yao Brou');
    expect(array_keys($results[0]))->toBe(['id', 'full_name', 'category']);
    expect(array_keys($results[0]['category']))->toBe(['id', 'name', 'color', 'priority_circuit']);
    expect($search->search($e, 'Visiteur fictif'))->toHaveCount(10);
    expect($search->search((string) Str::uuid(), 'Yao Brou'))->toBe([]);
    expect($search->search($e, 'ko')[0]['full_name'])->toBe('Koné Mariam');
    expect($search->search($e, 'x'))->toBe([]);
    expect($search->search($e, "' OR 1=1 --"))->toBe([]);
    $indexes = DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname='public' AND indexname='visitors_name_trgm'");
    expect($indexes)->toHaveCount(1);
    expect($indexes[0]->indexdef)->toContain('gin');
});
it('applique un départage portable entre noms accentués après qualité, VIP et score', function () {
    $event = DemoSeeder::id('event');
    $names = ['A testtri', 'a testtri', 'À testtri', 'É testtri'];
    foreach ($names as $i => $name) {
        DB::table('visitors')->where('id', DemoSeeder::id('visitor'.$i))
            ->update(['full_name' => $name, 'category_id' => DemoSeeder::id('category0')]);
    }
    $found = app(VisitorSearch::class)->searchDetailed($event, 'testtri');
    expect(array_column($found, 'full_name'))->toBe($names);
    expect(array_column($found, 'id'))->toHaveCount(4);
    $index = DB::table('pg_indexes')->where('schemaname', 'public')->where('indexname', 'visitors_active_name_c_idx')->value('indexdef');
    expect($index)->toContain('COLLATE "C"')->toContain('withdrawn_at IS NULL');
});

it('refuse en base une deuxième association active mais accepte une nouvelle après restitution ou annulation', function () {
    $badge = DB::table('badges')->where('event_id', DemoSeeder::id('event'))->first();
    $visitor = DemoSeeder::id('visitor0');
    $assignment = ['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'badge_id' => $badge->id, 'visitor_id' => $visitor, 'assigned_at' => now()];
    DB::table('badge_assignments')->insert($assignment);
    try {
        DB::transaction(fn () => DB::table('badge_assignments')->insert([...$assignment, 'id' => (string) Str::uuid()]));
        $this->fail('Seconde association acceptée');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23505');
    }
    DB::table('badge_assignments')->where('id', $assignment['id'])->update(['returned_at' => now()]);
    $second = (string) Str::uuid();
    DB::table('badge_assignments')->insert([...$assignment, 'id' => $second]);
    DB::table('badge_assignments')->where('id', $second)->update(['cancelled_at' => now()]);
    DB::table('badge_assignments')->insert([...$assignment, 'id' => (string) Str::uuid()]);
    expect(DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNull('returned_at')->whereNull('cancelled_at')->count())->toBe(1);
});
it('conserve le vocabulaire des statuts et refuse toute valeur étrangère', function () {
    expect(array_column(BadgeStatus::cases(), 'value'))->toBe(['Disponible', 'Associé', 'Restitué', 'Annulé', 'Clos']);
    $badge = Badge::where('code', 'B-400')->firstOrFail();
    // Depuis L4 un déclencheur n'autorise que les transitions du cycle de vie : chaque statut est donc stocké sur un badge créé à ce statut.
    foreach (BadgeStatus::cases() as $status) {
        $copy = Badge::create(['event_id' => $badge->event_id, 'badge_batch_id' => $badge->badge_batch_id, 'code' => 'V-'.$status->name, 'qr_token' => bin2hex(random_bytes(32)), 'status' => $status]);
        expect($copy->fresh()->status)->toBe($status);
    }
    try {
        DB::transaction(fn () => DB::table('badges')->where('id', $badge->id)->update(['status' => 'Inconnu']));
        $this->fail('Statut invalide accepté');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
    expect($badge->fresh()->status)->toBe(BadgeStatus::Available);
    expect($badge->toArray())->not->toHaveKey('qr_token');
});
it('refuse les collisions de code et de jeton QR', function () {
    $one = DB::table('badges')->where('code', 'B-398')->first();
    $two = DB::table('badges')->where('code', 'B-399')->first();
    foreach (['code', 'qr_token'] as $field) {
        try {
            DB::transaction(fn () => DB::table('badges')->where('id', $two->id)->update([$field => $one->$field]));
            $this->fail('Collision acceptée');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23505');
        }
    }
});
it('refuse les écrans et écritures sans la permission correspondante', function () {
    foreach (['superviseur', 'agent_accueil'] as $role) {
        $user = businessAccount($role);
        if ($role === 'superviseur') {
            $user->update(['two_factor_confirmed_at' => now()]);
        }
        foreach (['zones', 'categories', 'badges'] as $screen) {
            $this->actingAs($user)->get('/administration/'.$screen)->assertForbidden();
        }
        foreach (['zones', 'categories'] as $screen) {
            $this->post('/administration/'.$screen, [])->assertForbidden();
        }
    }
});
it('journalise la création et la modification des zones et catégories sans données superflues', function () {
    $admin = businessAccount('administrateur');
    $admin->update(['two_factor_confirmed_at' => now()]);
    $this->actingAs($admin);
    $name = 'Zone test '.Str::uuid();
    $event = DemoSeeder::id('event');
    $this->post('/administration/zones', ['event_id' => $event, 'name' => $name])->assertRedirect();
    $zone = DB::table('zones')->where('name', $name)->first();
    $this->put('/administration/zones/'.$zone->id, ['name' => $name.' modifiée'])->assertRedirect();
    $categoryName = 'Catégorie test '.Str::uuid();
    $data = ['event_id' => $event, 'name' => $categoryName, 'color' => '#0F5AA6', 'priority_circuit' => false, 'zone_ids' => [$zone->id]];
    $this->post('/administration/categories', $data)->assertRedirect();
    $category = DB::table('categories')->where('name', $categoryName)->first();
    $this->put('/administration/categories/'.$category->id, [...$data, 'name' => $categoryName.' modifiée', 'zone_ids' => [DemoSeeder::id('zone0')]])->assertRedirect();
    foreach (['zone.creee', 'zone.modifiee', 'categorie.creee', 'categorie.modifiee'] as $action) {
        $entry = DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', $action)->first();
        expect($entry)->not->toBeNull();
        expect(array_keys(json_decode($entry->details, true)))->toBe(['id', 'event_id', 'fields']);
    }
    expect(DB::table('category_zone')->where('category_id', $category->id)->pluck('zone_id')->all())->toBe([DemoSeeder::id('zone0')]);
    $this->artisan('audit:verify')->assertSuccessful();
});
it('refuse des zones d’un autre événement et ne crée aucun journal en cas de validation refusée', function () {
    $admin = businessAccount('administrateur');
    $admin->update(['two_factor_confirmed_at' => now()]);
    $this->actingAs($admin);
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Autre exercice fictif', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    $zone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $zone, 'event_id' => $event, 'name' => 'Zone hors événement']);
    $count = DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', 'categorie.creee')->count();
    $this->post('/administration/categories', ['event_id' => DemoSeeder::id('event'), 'name' => 'Catégorie interdite', 'color' => '#0F5AA6', 'priority_circuit' => false, 'zone_ids' => [$zone]])->assertSessionHasErrors('zone_ids.0');
    expect(DB::table('audit_logs')->where('actor_id', $admin->id)->where('action', 'categorie.creee')->count())->toBe($count);
    try {
        DB::transaction(fn () => DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'category_id' => DemoSeeder::id('category0'), 'zone_id' => $zone]));
        $this->fail('Mélange événements accepté');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23503');
    }
});
it('affiche les badges en lecture seule sans jeton QR et filtre les réservations', function () {
    $admin = businessAccount('administrateur');
    $admin->update(['two_factor_confirmed_at' => now()]);
    $this->actingAs($admin)->get('/administration/badges?reserved=oui')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Badges')->where('badges.total', 60)->missing('badges.data.0.qr_token'));
    $this->post('/administration/badges', [])->assertStatus(405);
});
it('reçoit les droits sur les nouvelles tables sans élargir ceux du journal', function () {
    expect(DB::selectOne("SELECT has_table_privilege(current_user, 'visitors', 'INSERT') AS ok")->ok)->toBeTrue();
    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $right) {
        expect(DB::selectOne("SELECT has_table_privilege(current_user, 'audit_logs', '$right') AS ok")->ok)->toBeFalse();
    }
    expect(DB::selectOne('SELECT rolcreatedb OR rolcreaterole OR rolsuper AS elevated FROM pg_roles WHERE rolname=current_user')->elevated)->toBeFalse();
});
