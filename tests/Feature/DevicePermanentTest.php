<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\Devices;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

function permanentActivity(string $name): string
{
    $row = (array) DB::table('events')->where('id', DemoSeeder::id('event'))->first();
    $row['id'] = (string) Str::uuid();
    $row['name'] = $name;
    $row['is_demo'] = false;
    DB::table('events')->insert($row);

    return $row['id'];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Admin fictif tablettes', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
    $this->a = DemoSeeder::id('event');
    $this->b = permanentActivity('Activité fictive B');
    $this->devices = app(Devices::class);
});

it('crée une tablette sans activité, avec ses activités autorisées, depuis l’administration', function () {
    $this->post('/administration/postes', ['name' => 'Tablette fictive permanente', 'station' => 'Poste fictif', 'point_code' => 'poste_1', 'event_ids' => [$this->a, $this->b]])->assertSessionHasNoErrors();
    $device = Device::where('name', 'Tablette fictive permanente')->firstOrFail();
    expect($device->event_id)->toBeNull()->and(DB::table('device_activities')->where('device_id', $device->id)->pluck('event_id')->sort()->values()->all())->toBe(collect([$this->a, $this->b])->sort()->values()->all());
    $this->get('/administration/postes')->assertOk();
});

it('enrôle une tablette en mode site (sans activité) quand aucune ou plusieurs activités sont autorisées, et assigne l’unique activité autorisée', function () {
    $none = $this->devices->create(null, 'Tablette fictive 0', null, $this->admin, 'poste_1');
    $enrolled = $this->devices->enrol($this->devices->issueCode($none, $this->admin), $this->admin);
    expect($enrolled['poste']['event_id'])->toBeNull()->and($enrolled['poste']['event_name'])->toBeNull()->and($none->refresh()->event_id)->toBeNull()->and($none->token_hash)->not->toBeNull();

    $many = $this->devices->create(null, 'Tablette fictive 2', null, $this->admin, 'poste_1');
    $this->devices->authorize($many, [$this->a, $this->b], $this->admin);
    $enrolledMany = $this->devices->enrol($this->devices->issueCode($many, $this->admin), $this->admin);
    expect($enrolledMany['poste']['event_id'])->toBeNull()->and($many->refresh()->event_id)->toBeNull();

    $one = $this->devices->create(null, 'Tablette fictive 1', null, $this->admin, 'poste_1');
    $this->devices->authorize($one, [$this->b], $this->admin);
    $code = $this->devices->issueCode($one, $this->admin);
    expect($code)->not->toBe('')->and($one->refresh()->event_id)->toBe($this->b);
});

it('choisit l’activité en cours parmi les activités autorisées seulement, et jamais pour une tablette enrôlée', function () {
    $device = $this->devices->create(null, 'Tablette fictive 3', null, $this->admin, 'poste_1');
    $this->devices->authorize($device, [$this->a], $this->admin);
    expect(fn () => $this->devices->setCurrent($device, $this->b, $this->admin))->toThrow(DeskRefused::class, 'pas autorisée');
    $this->devices->setCurrent($device, $this->a, $this->admin);
    expect($device->refresh()->event_id)->toBe($this->a);
    DB::table('devices')->where('id', $device->id)->update(['token_hash' => hash('sha256', 'jeton fictif'), 'enrolled_at' => now()]);
    $this->devices->authorize($device, [$this->a, $this->b], $this->admin);
    expect(fn () => $this->devices->setCurrent($device->refresh(), $this->b, $this->admin))->toThrow(DeskRefused::class, 'enrôlée');
});

it('refuse de retirer l’activité en cours d’une tablette', function () {
    $device = $this->devices->create($this->a, 'Tablette fictive 4', null, $this->admin, 'poste_1');
    $this->devices->authorize($device, [$this->a, $this->b], $this->admin);
    expect(fn () => $this->devices->authorize($device, [$this->b], $this->admin))->toThrow(DeskRefused::class, 'ne peut pas être retirée');
    $this->devices->authorize($device, [$this->a], $this->admin);
    expect(DB::table('device_activities')->where('device_id', $device->id)->pluck('event_id')->all())->toBe([$this->a]);
});

it('garantit en base que l’activité en cours est toujours une activité autorisée', function () {
    $device = $this->devices->create(null, 'Tablette fictive 5', null, $this->admin, 'poste_1');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    expect(fn () => DB::transaction(fn () => DB::table('devices')->where('id', $device->id)->update(['event_id' => $this->b])))->toThrow(QueryException::class);
});

it('garde les réservations de badges d’une activité intactes quand la tablette en change', function () {
    $device = $this->devices->create($this->a, 'Tablette fictive 6', null, $this->admin, 'poste_1');
    $this->devices->authorize($device, [$this->a, $this->b], $this->admin);
    $badge = DB::table('badges')->where('event_id', $this->a)->first();
    expect($badge)->not->toBeNull();
    DB::table('badges')->where('id', $badge->id)->update(['reserved_device_id' => $device->id]);
    expect(fn () => $this->devices->authorize($device, [$this->a], $this->admin))->not->toThrow(DeskRefused::class);
    DB::table('badges')->where('id', $badge->id)->update(['reserved_device_id' => null]);
    $other = DB::table('badges')->where('event_id', $this->a)->where('id', '!=', $badge->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('badges')->where('id', $other->id)->update(['reserved_device_id' => (string) Str::uuid()])))->toThrow(QueryException::class);
});

it('affiche le refus d’un code d’enrôlement dans la page (pas en JSON brut) quand l’activité en cours est clôturée', function () {
    $device = $this->devices->create(null, 'Tablette fictive activité clôturée', null, $this->admin, 'poste_1');
    $this->devices->authorize($device, [$this->a], $this->admin);
    $this->devices->setCurrent($device, $this->a, $this->admin);
    $status = DB::table('events')->where('id', $this->a)->value('status');
    DB::table('events')->where('id', $this->a)->update(['status' => 'clos']);
    $this->post('/administration/postes/'.$device->id.'/code')->assertRedirect()->assertSessionHasErrors(['poste_'.$device->id]);
    expect(session('errors')->first('poste_'.$device->id))->toContain('clôturé');
    DB::table('events')->where('id', $this->a)->update(['status' => $status]);
    $this->post('/administration/postes/'.$device->id.'/code')->assertRedirect()->assertSessionHasNoErrors();
    expect(session('enrol_code.device_id'))->toBe($device->id);
});
