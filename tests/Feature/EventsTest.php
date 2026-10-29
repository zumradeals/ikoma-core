<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->administrator = User::create([
        'name' => 'Administrateur événement',
        'email' => Str::uuid().'@exemple.test',
        'password' => Str::password(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->administrator->assignRole('administrateur');
});

function createEventPayload(array $values = []): array
{
    return [
        'name' => 'Forum des entreprises',
        'site' => 'Abidjan - Cocody',
        'starts_at' => '2030-11-10T08:00',
        'ends_at' => '2030-11-10T18:00',
        ...$values,
    ];
}

it('affiche les evenements de l administrateur', function () {
    $this->actingAs($this->administrator)->get('/administration/evenements')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Evenements')
            ->has('events', 1)
            ->where('events.0.id', DemoSeeder::id('event')));
});

it('cree un evenement et la zone d accueil puis le selectionne', function () {
    $before = DB::table('events')->count();

    $response = $this->actingAs($this->administrator)
        ->post('/administration/evenements', createEventPayload());

    $event = DB::table('events')->where('name', 'Forum des entreprises')->first();
    expect($event)->not->toBeNull()
        ->and(DB::table('events')->count())->toBe($before + 1)
        ->and($event->site)->toBe('Abidjan - Cocody')
        ->and(DB::table('zones')->where('event_id', $event->id)->where('name', 'Accueil')->where('est_accueil', true)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'evenement.cree')->exists())->toBeTrue();

    $response->assertRedirect('/administration?event='.$event->id)
        ->assertSessionHas('admin_reference_event', $event->id)
        ->assertSessionHas('status');

    $this->get('/administration?event='.$event->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Tableau')
            ->where('event.id', $event->id)
            ->where('steps.0.key', 'zones'));
});

it('refuse les champs invalides et ne cree rien', function () {
    $initial = DB::table('events')->count();

    $this->actingAs($this->administrator)
        ->post('/administration/evenements', createEventPayload([
            'name' => '',
            'site' => str_repeat('A', 256),
            'starts_at' => 'incorrecte',
            'ends_at' => 'incorrecte',
        ]))->assertSessionHasErrors(['name', 'site', 'starts_at', 'ends_at']);

    $this->post('/administration/evenements', createEventPayload([
        'starts_at' => '2030-11-11T18:00',
        'ends_at' => '2030-11-10T08:00',
    ]))->assertSessionHasErrors('ends_at');

    expect(DB::table('events')->count())->toBe($initial);
});

it('interdit la creation au superviseur et aux visiteurs anonymes', function () {
    $initial = DB::table('events')->count();
    $this->post('/administration/evenements', createEventPayload())->assertRedirect('/login');
    $supervisor = User::create([
        'name' => 'Superviseur recette',
        'email' => Str::uuid().'@exemple.test',
        'password' => Str::password(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $supervisor->assignRole('superviseur');
    $this->actingAs($supervisor)->get('/administration/evenements')->assertForbidden();
    $this->post('/administration/evenements', createEventPayload())->assertForbidden();

    expect(DB::table('events')->count())->toBe($initial);
});

it('ne modifie ni les evenements precedents ni les comptes existants', function () {
    $oldEvent = DB::table('events')->where('id', DemoSeeder::id('event'))->first();
    $accounts = DB::table('users')->count();

    $this->actingAs($this->administrator)->post('/administration/evenements', createEventPayload())
        ->assertRedirect();

    expect(DB::table('users')->count())->toBe($accounts)
        ->and(DB::table('events')->where('id', $oldEvent->id)->value('name'))->toBe($oldEvent->name);
});
