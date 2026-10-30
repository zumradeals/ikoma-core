<?php

use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\DataLifecycle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

it('supprime un badge strictement inutilisé et conserve la protection anti-réattribution', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::create(['name' => 'Administrateur test', 'email' => Str::uuid().'@exemple.test',
        'password' => 'test-secret-strong', 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('administrateur');
    $event = Event::create(['name' => 'Événement de test', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    $category = Category::create(['event_id' => $event->id, 'name' => 'Visiteur',
        'color' => '#0066B3', 'priority_circuit' => false]);
    $batch = app(BadgeLots::class)->generate($event->id, $category->id, 2, $admin);
    $badge = DB::table('badges')->where('badge_batch_id', $batch->id)->first();
    $report = app(DataLifecycle::class)->inspect('badge', $badge->id, $admin);
    expect($report['allowed'])->toBeTrue();
    app(DataLifecycle::class)->delete('badge', $badge->id, $admin, $report['fingerprint']);
    expect(DB::table('badges')->where('id', $badge->id)->exists())->toBeFalse()
        ->and(DB::table('retired_badge_identifiers')->where('event_id', $event->id)->where('code', $badge->code)->exists())->toBeTrue()
        ->and(DB::table('badge_batches')->where('id', $batch->id)->value('quantity'))->toBe($batch->quantity - 1);
});

it('refuse de supprimer un badge dont le lot a été présenté à impression', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::create(['name' => 'Administrateur test', 'email' => Str::uuid().'@exemple.test',
        'password' => 'test-secret-strong', 'two_factor_confirmed_at' => now()]);
    $admin->assignRole('administrateur');
    $event = Event::create(['name' => 'Événement de test', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    $category = Category::create(['event_id' => $event->id, 'name' => 'Visiteur',
        'color' => '#0066B3', 'priority_circuit' => false]);
    $batch = app(BadgeLots::class)->generate($event->id, $category->id, 2, $admin);
    DB::table('badge_batches')->where('id', $batch->id)->update(['print_exposed_at' => now()]);
    $id = DB::table('badges')->where('badge_batch_id', $batch->id)->value('id');
    expect(app(DataLifecycle::class)->inspect('badge', $id, $admin)['allowed'])->toBeFalse();
});
