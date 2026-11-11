<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\ListSnapshot;
use App\Services\ZoneAccess;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

/** Zones du site réutilisées par les activités, étape Z1 : une catégorie ou un poste vise une zone du site ; le contrôle et la copie hors ligne la lisent. */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif zones', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
    $this->event = DemoSeeder::id('event');
    $this->category = DemoSeeder::id('category0');
    $this->device = DemoSeeder::id('device');
    $this->post('/administration/site/zones', ['name' => 'Hall commun fictif'])->assertSessionHasNoErrors();
    $this->siteZone = (string) DB::table('zones')->whereNull('event_id')->where('name', 'Hall commun fictif')->value('id');
    $this->checkpoint = (string) DB::table('checkpoints')->where('code', 'poste_2')->value('id');
    DB::table('devices')->where('id', $this->device)->update(['checkpoint_id' => $this->checkpoint]);
    $this->selectZones = function (array $zoneIds) {
        $category = DB::table('categories')->where('id', $this->category)->first();

        return $this->put('/administration/categories/'.$this->category, ['event_id' => $this->event, 'name' => $category->name, 'color' => $category->color, 'priority_circuit' => (bool) $category->priority_circuit, 'closure_supervisor_only' => false, 'zone_ids' => $zoneIds]);
    };
});

it('laisse une catégorie choisir une zone du site, en plus des zones de son activité', function () {
    $own = DB::table('category_zone')->where('category_id', $this->category)->pluck('zone_id')->all();
    ($this->selectZones)([...$own, $this->siteZone])->assertSessionHasNoErrors();
    expect(DB::table('category_zone')->where('category_id', $this->category)->where('zone_id', $this->siteZone)->where('event_id', $this->event)->exists())->toBeTrue();
    $this->get('/administration/categories')->assertOk()->assertInertia(fn ($page) => $page->where('zones', fn ($zones) => collect($zones)->contains(fn ($z) => $z['id'] === $this->siteZone && $z['event_id'] === null)));
});

it('refuse en base une zone d’une AUTRE activité, mais accepte une zone du site', function () {
    $otherEvent = (string) Str::uuid();
    DB::table('events')->insert(['id' => $otherEvent, 'name' => 'Autre activité fictive', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $foreign = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $foreign, 'event_id' => $otherEvent, 'name' => 'Zone étrangère', 'created_at' => now(), 'updated_at' => now()]);
    $row = fn (string $zone) => ['id' => (string) Str::uuid(), 'event_id' => $this->event, 'category_id' => $this->category, 'zone_id' => $zone, 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::transaction(fn () => DB::table('category_zone')->insert($row($foreign))))->toThrow(QueryException::class, 'sa propre activité');
    DB::table('category_zone')->insert($row($this->siteZone));
    expect(DB::table('category_zone')->where('zone_id', $this->siteZone)->exists())->toBeTrue();
});

it('ne change pas le jugement d’un poste tant que l’activité ne réutilise pas la zone du site qu’il garde', function () {
    $access = app(ZoneAccess::class);
    $before = $access->judgedZones($this->event, ['type' => 'poste', 'id' => $this->device]);
    $this->post("/administration/site/postes/{$this->checkpoint}/zones-site", ['zone_id' => $this->siteZone])->assertSessionHasNoErrors();
    expect($access->judgedZones($this->event, ['type' => 'poste', 'id' => $this->device]))->toBe($before);
});

it('fait juger la zone du site par le poste dès qu’une catégorie de l’activité la réutilise, et accepte alors un visiteur de cette catégorie', function () {
    $access = app(ZoneAccess::class);
    $this->post("/administration/site/postes/{$this->checkpoint}/zones-site", ['zone_id' => $this->siteZone])->assertSessionHasNoErrors();
    $own = DB::table('category_zone')->where('category_id', $this->category)->pluck('zone_id')->all();
    ($this->selectZones)([...$own, $this->siteZone])->assertSessionHasNoErrors();
    $judged = $access->judgedZones($this->event, ['type' => 'poste', 'id' => $this->device]);
    expect($judged)->toContain($this->siteZone);
    $visitor = (string) DB::table('visitors')->where('category_id', $this->category)->value('id');
    $zones = $access->zonesFor($visitor, $this->category);
    expect($zones->pluck('id')->all())->toContain($this->siteZone)->and(ZoneAccess::decide($judged, $zones))->toBeTrue();
    // une catégorie qui ne réutilise pas la zone du site est refusée par ce même poste (la zone est jugée, pas ouverte)
    $otherCategory = DemoSeeder::id('category1');
    $visitorOther = (string) DB::table('visitors')->where('category_id', $otherCategory)->value('id');
    if ($visitorOther !== '') {
        $others = $access->zonesFor($visitorOther, $otherCategory);
        expect($others->pluck('id')->all())->not->toContain($this->siteZone);
    }
});

it('envoie à la tablette les zones du site réutilisées et change la version de la copie', function () {
    $poste = Device::findOrFail($this->device);
    $snapshot = app(ListSnapshot::class);
    $v0 = $snapshot->version($poste);
    $this->post("/administration/site/postes/{$this->checkpoint}/zones-site", ['zone_id' => $this->siteZone]);
    expect($snapshot->version($poste->refresh()))->not->toBe($v0); // le poste garde une zone de plus
    $v1 = $snapshot->version($poste);
    $own = DB::table('category_zone')->where('category_id', $this->category)->pluck('zone_id')->all();
    ($this->selectZones)([...$own, $this->siteZone])->assertSessionHasNoErrors();
    expect($snapshot->version($poste))->not->toBe($v1);
    $copy = $snapshot->build($poste->refresh());
    expect(collect($copy['zones'])->pluck('id')->all())->toContain($this->siteZone)
        ->and(collect($copy['category_zone'])->contains(fn ($row) => $row->zone_id === $this->siteZone && $row->category_id === $this->category))->toBeTrue()
        ->and(collect($copy['point_zones'])->contains(fn ($row) => $row->zone_id === $this->siteZone && $row->device_id === $this->device))->toBeTrue();
});
