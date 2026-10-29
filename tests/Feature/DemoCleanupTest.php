<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
});

it('annonce le périmètre en simulation et ne supprime rien', function () {
    $event = DemoSeeder::id('event');
    $this->artisan('demo:purger')->assertExitCode(0);
    expect(DB::table('events')->where('id', $event)->exists())->toBeTrue();
    expect(DB::table('visitors')->where('event_id', $event)->count())->toBe(300);
    expect(DB::table('badges')->where('event_id', $event)->count())->toBe(400);
});

it('refuse de supprimer le seul compte administrateur fictif', function () {
    $this->artisan('demo:purger', ['--confirmer' => true])->assertExitCode(1);
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeTrue();
    expect(User::where('email', 'administrateur@exemple.test')->exists())->toBeTrue();
});

it('nettoie uniquement le jeu seedé avec un administrateur réel confirmé', function () {
    $realAdmin = User::create([
        'name' => 'Administration réelle',
        'email' => 'administration-fiable@organisation.test',
        'password' => 'Test-aA1!'.Str::random(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $realAdmin->assignRole('administrateur');
    $otherEvent = (string) Str::uuid();
    $now = now();
    DB::table('events')->insert([
        'id' => $otherEvent, 'name' => 'Événement réel conservé',
        'starts_at' => $now, 'ends_at' => $now->copy()->addDay(),
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $this->artisan('demo:purger', ['--confirmer' => true])->assertExitCode(0);
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeFalse()
        ->and(DB::table('events')->where('id', $otherEvent)->exists())->toBeTrue()
        ->and(User::where('email', 'administration-fiable@organisation.test')->exists())->toBeTrue()
        ->and(User::where('email', 'administrateur@exemple.test')->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'demo.donnees_purgees')->exists())->toBeTrue();

    $this->artisan('demo:purger')->assertExitCode(0);
});

it('refuse tout effacement si des opérations ont été réalisées dans le jeu fictif', function () {
    $realAdmin = User::create([
        'name' => 'Administration réelle',
        'email' => 'administration-fiable@organisation.test',
        'password' => 'Test-aA1!'.Str::random(24),
        'two_factor_confirmed_at' => now(),
    ]);
    $realAdmin->assignRole('administrateur');
    $now = now();
    DB::table('alerts')->insert([
        'id' => (string) Str::uuid(),
        'event_id' => DemoSeeder::id('event'),
        'type' => 'refus',
        'severity' => 'normale',
        'message' => 'Alerte fictive de contrôle',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->artisan('demo:purger', ['--confirmer' => true])->assertExitCode(1);
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeTrue()
        ->and(User::where('email', 'administrateur@exemple.test')->exists())->toBeTrue();
});
