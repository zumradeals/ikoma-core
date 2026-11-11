<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
});

function sbBadge(array $over = []): string
{
    $id = (string) Str::uuid();
    DB::table('badges')->insert($over + ['id' => $id, 'event_id' => null, 'badge_batch_id' => null, 'category_id' => null, 'code' => 'S-'.Str::upper(Str::random(8)), 'qr_token' => bin2hex(random_bytes(32)), 'status' => 'Disponible', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('accepte un badge du site, sans événement, sans lot ni catégorie', function () {
    $id = sbBadge();
    $row = DB::table('badges')->where('id', $id)->first();
    expect($row->event_id)->toBeNull()->and($row->badge_batch_id)->toBeNull();
});

it('refuse qu’un badge du site porte une catégorie, une personne imprimée ou une réservation', function () {
    $category = (string) DB::table('categories')->value('id');
    $device = (string) Str::uuid();
    foreach ([['category_id' => $category], ['printed_for_visitor_id' => (string) Str::uuid()], ['reserved_device_id' => $device], ['reserved_group_id' => (string) Str::uuid()]] as $over) {
        expect(fn () => DB::transaction(fn () => sbBadge($over)))->toThrow(QueryException::class);
    }
});

it('ne laisse jamais un badge du site devenir un badge d’activité, ni l’inverse', function () {
    $id = sbBadge();
    $event = DemoSeeder::id('event');
    expect(fn () => DB::transaction(fn () => DB::table('badges')->where('id', $id)->update(['event_id' => $event])))->toThrow(QueryException::class);
});

it('garde les identifiants retirés du site non réattribuables, sans collision avec ceux d’une activité', function () {
    $hash = sbHash('retire-site');
    DB::table('retired_badge_identifiers')->insert(['event_id' => null, 'code' => 'S-RETIRE', 'token_hash' => $hash]);
    expect(fn () => DB::transaction(fn () => DB::table('retired_badge_identifiers')->insert(['event_id' => null, 'code' => 'S-RETIRE', 'token_hash' => sbHash('autre')])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => sbBadge(['code' => 'S-RETIRE'])))->toThrow(QueryException::class);
});

function sbHash(string $seed): string
{
    return hash('sha256', $seed);
}
