<?php

use App\Models\User;
use App\Services\VisitorSearch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->travelTo('2027-01-10 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->eventId = (string) Str::uuid();
    $this->categoryId = (string) Str::uuid();

    DB::table('events')->insert([
        'id' => $this->eventId,
        'name' => 'Événement de classement fictif',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('categories')->insert([
        'id' => $this->categoryId,
        'event_id' => $this->eventId,
        'name' => 'Visiteur',
        'color' => '#0066B3',
        'priority_circuit' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Les quatre variantes sont équivalentes pour le moteur de recherche.
    // Leur départage doit être indépendant de la collation du système.
    $this->persons = [
        'Ecole' => '00000000-0000-4000-8000-000000000001',
        'ecole' => '00000000-0000-4000-8000-000000000002',
        'École' => '00000000-0000-4000-8000-000000000003',
        'école' => '00000000-0000-4000-8000-000000000004',
    ];

    foreach ($this->persons as $name => $id) {
        DB::table('visitors')->insert([
            'id' => $id,
            'event_id' => $this->eventId,
            'category_id' => $this->categoryId,
            'full_name' => $name,
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addHour(),
            'source' => 'listé',
            'status' => 'attendu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

it('départage les homonymes accentués dans un ordre stable en aperçu et en recherche', function () {
    $expected = array_values($this->persons);

    $preview = app(VisitorSearch::class)->preview($this->eventId);
    expect(array_column($preview, 'id'))->toBe($expected);

    $results = app(VisitorSearch::class)->searchDetailed($this->eventId, 'ecole');
    expect(array_column($results, 'id'))->toBe($expected);

    $resultsWithAccents = app(VisitorSearch::class)->searchDetailed($this->eventId, 'école');
    expect(array_column($resultsWithAccents, 'id'))->toBe($expected);
});

it('pagine la liste administrative avec le même départage indépendant de la distribution', function () {
    $admin = User::create([
        'name' => 'Administrateur fictif de recette',
        'email' => Str::uuid().'@exemple.test',
        'password' => Str::password(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('administrateur');
    $this->actingAs($admin);

    $this->get('/administration/invites?event_id='.$this->eventId)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Visitors')
            ->where('list.data.0.id', $this->persons['Ecole'])
            ->where('list.data.1.id', $this->persons['ecole'])
            ->where('list.data.2.id', $this->persons['École'])
            ->where('list.data.3.id', $this->persons['école'])
            ->etc());
});
