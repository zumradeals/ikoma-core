<?php

use App\Models\User;
use App\Services\DemoLab;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::create([
        'name' => 'Responsable de test',
        'email' => Str::uuid().'@exemple.test',
        'password' => 'Motdepasse-De-Test-12345!',
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('administrateur');
});

it('génère depuis l’interface un scénario identifiable sans comptes fictifs', function () {
    $beforeUsers = DB::table('users')->count();
    $this->actingAs($this->admin)->get('/administration/demonstration')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/DemoLab')
            ->has('totals')->has('scenarios'));
    DB::table('settings')->where('key', 'module_f06_active')->update(['value' => '1']);
    $this->post('/administration/demonstration/generer', [
        'scenario' => 'formation', 'quantity' => 20,
    ])->assertRedirect('/administration/demonstration');
    $event = DB::table('events')->where('is_demo', true)->latest('created_at')->first();
    expect($event)->not->toBeNull()
        ->and($event->demo_scenario)->toBe('formation')
        ->and(DB::table('visitors')->where('event_id', $event->id)->count())->toBe(20)
        ->and(DB::table('badges')->where('event_id', $event->id)->count())->toBe(22)
        ->and(DB::table('groups')->where('event_id', $event->id)->count())->toBe(1)
        ->and(DB::table('group_members')->whereIn('group_id', DB::table('groups')->where('event_id', $event->id)->select('id'))->count())->toBe(10)
        ->and(DB::table('users')->count())->toBe($beforeUsers);
});

it('refuse la démonstration de groupes si F06 est inactif sans modifier les données', function () {
    $before = DB::table('groups')->count();
    $this->actingAs($this->admin)->post('/administration/demonstration/generer', [
        'scenario' => 'formation', 'quantity' => 20,
    ])->assertSessionHasErrors('scenario');
    expect(DB::table('groups')->count())->toBe($before);
    $this->post('/administration/demonstration/generer', [
        'scenario' => 'entreprise', 'quantity' => 20,
    ])->assertRedirect('/administration/demonstration');
});

it('efface un scénario utilisé sans toucher aux événements ordinaires', function () {
    $lab = app(DemoLab::class);
    $demoId = $lab->generate('entreprise', 20, $this->admin);
    $ordinary = (string) Str::uuid();
    DB::table('events')->insert([
        'id' => $ordinary, 'name' => 'Événement ordinaire à conserver',
        'starts_at' => now(), 'ends_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $person = DB::table('visitors')->where('event_id', $demoId)->value('id');
    DB::table('access_events')->insert([
        'id' => (string) Str::uuid(), 'event_id' => $demoId,
        'visitor_id' => $person, 'type' => 'validation', 'occurred_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($this->admin)->post('/administration/demonstration/'.$demoId.'/nettoyer', [
        'confirmation' => 'SUPPRIMER DEMO', 'password' => 'Motdepasse-De-Test-12345!',
    ])->assertRedirect('/administration/demonstration');
    expect(DB::table('events')->where('id', $demoId)->exists())->toBeFalse()
        ->and(DB::table('events')->where('id', $ordinary)->exists())->toBeTrue()
        ->and(DB::table('access_events')->where('event_id', $demoId)->exists())->toBeFalse();
});

it('ne permet jamais de purger un événement ordinaire par le reset ciblé', function () {
    $ordinary = (string) Str::uuid();
    DB::table('events')->insert(['id' => $ordinary, 'name' => 'Ne pas toucher',
        'starts_at' => now(), 'ends_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->admin)->post('/administration/demonstration/'.$ordinary.'/nettoyer', [
        'confirmation' => 'SUPPRIMER DEMO', 'password' => 'Motdepasse-De-Test-12345!',
    ])->assertNotFound();
    expect(DB::table('events')->where('id', $ordinary)->exists())->toBeTrue();
});

it('refuse les tentatives de remise à zéro sans phrase ou mot de passe correct', function () {
    $id = app(DemoLab::class)->generate('entreprise', 20, $this->admin);
    $this->actingAs($this->admin)->post('/administration/demonstration/remettre-a-zero', [
        'confirmation' => 'MAUVAISE PHRASE', 'password' => 'Motdepasse-De-Test-12345!',
        'acknowledge' => true, 'clear_users' => false,
    ])->assertSessionHasErrors('confirmation');
    $this->post('/administration/demonstration/remettre-a-zero', [
        'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'mauvais',
        'acknowledge' => true, 'clear_users' => false,
    ])->assertSessionHasErrors('password');
    expect(DB::table('events')->where('id', $id)->exists())->toBeTrue();
});

it('remet à zéro toutes les données métier et conserve le compte administrateur', function () {
    $demoId = app(DemoLab::class)->generate('evenement', 20, $this->admin);
    $ordinary = (string) Str::uuid();
    DB::table('events')->insert(['id' => $ordinary, 'name' => 'Séminaire de test',
        'starts_at' => now(), 'ends_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->admin)->post('/administration/demonstration/remettre-a-zero', [
        'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'Motdepasse-De-Test-12345!',
        'acknowledge' => true, 'clear_users' => false,
    ])->assertRedirect('/administration/demonstration');
    expect(DB::table('events')->count())->toBe(0)
        ->and(DB::table('badges')->count())->toBe(0)
        ->and(DB::table('visitors')->count())->toBe(0)
        ->and(DB::table('devices')->count())->toBe(0)
        ->and(DB::table('retired_badge_identifiers')->count())->toBe(0)
        ->and(User::find($this->admin->id))->not->toBeNull()
        ->and(DB::table('settings')->where('key', 'installation_terminee')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'demo.remise_zero_metier')->exists())->toBeTrue();
});

it('interdit la remise à zéro sans rôle administrateur', function () {
    $actor = User::create(['name' => 'Agent fictif', 'email' => Str::uuid().'@exemple.test',
        'password' => 'Autre-Mot-de-passe-123!', 'two_factor_confirmed_at' => now()]);
    $actor->assignRole('agent_accueil');
    $this->actingAs($actor)->get('/administration/demonstration')->assertForbidden();
    $this->post('/administration/demonstration/remettre-a-zero', [
        'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'Autre-Mot-de-passe-123!',
        'acknowledge' => true, 'clear_users' => false,
    ])->assertForbidden();
});

it('peut supprimer les comptes secondaires après confirmation explicite du reset', function () {
    $other = User::create(['name' => 'Agent secondaire', 'email' => Str::uuid().'@exemple.test',
        'password' => 'Compte-secondaire-123!', 'two_factor_confirmed_at' => now()]);
    $other->assignRole('agent_accueil');
    $this->actingAs($this->admin)->post('/administration/demonstration/remettre-a-zero', [
        'confirmation' => 'REMETTRE A ZERO IKOMA',
        'password' => 'Motdepasse-De-Test-12345!',
        'acknowledge' => true, 'clear_users' => true,
    ])->assertRedirect('/administration/demonstration');
    expect(User::find($this->admin->id))->not->toBeNull()
        ->and(User::find($other->id))->toBeNull();
});
