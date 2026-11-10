<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('affecte chaque tablette existante à son activité actuelle, sans rien changer d’autre', function () {
    expect(Schema::hasTable('device_activities'))->toBeTrue();
    $orphans = DB::table('devices as d')->whereNotNull('d.event_id')
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('device_activities as a')->whereColumn('a.device_id', 'd.id')->whereColumn('a.event_id', 'd.event_id'))->count();
    expect($orphans)->toBe(0)->and(DB::table('devices')->count())->toBeGreaterThan(0);
});

it('garde la liste à jour quand une tablette est créée avec son activité, sans doublon', function () {
    $device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $device, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette fictive permanente', 'station' => 'Poste fictif', 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('device_activities')->where('device_id', $device)->where('event_id', DemoSeeder::id('event'))->count())->toBe(1);
    DB::table('devices')->where('id', $device)->update(['name' => 'Tablette fictive renommée', 'updated_at' => now()]);
    expect(DB::table('device_activities')->where('device_id', $device)->count())->toBe(1);
});

it('refuse deux fois la même activité pour une tablette et une activité inconnue', function () {
    $row = DB::table('device_activities')->first();
    expect(fn () => DB::transaction(fn () => DB::table('device_activities')->insert(['id' => (string) Str::uuid(), 'device_id' => $row->device_id, 'event_id' => $row->event_id, 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('device_activities')->insert(['id' => (string) Str::uuid(), 'device_id' => $row->device_id, 'event_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});

it('emporte l’affectation avec la tablette (réinitialisation de démonstration) mais jamais l’activité', function () {
    $device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $device, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette fictive éphémère', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('devices')->where('id', $device)->delete();
    expect(DB::table('device_activities')->where('device_id', $device)->count())->toBe(0)->and(DB::table('events')->where('id', DemoSeeder::id('event'))->exists())->toBeTrue();
});
