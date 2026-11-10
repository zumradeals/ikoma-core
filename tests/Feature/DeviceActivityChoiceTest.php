<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CHOICE_TOKEN = '3333333333333333333333333333333333333333333333333333333333333333';

function choiceActivity(string $name, ?string $status = null): string
{
    $row = (array) DB::table('events')->where('id', DemoSeeder::id('event'))->first();
    $row['id'] = (string) Str::uuid();
    $row['name'] = $name;
    $row['is_demo'] = false;
    $row['status'] = $status;
    DB::table('events')->insert($row);

    return $row['id'];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->agent = User::create(['name' => 'Agent fictif activités', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->agent->assignRole('agent_accueil');
    $this->actingAs($this->agent);
    $this->a = DemoSeeder::id('event');
    $this->b = choiceActivity('Activité fictive B');
    $this->closed = choiceActivity('Activité fictive close', 'clos');
    $this->device = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $this->device, 'event_id' => $this->a, 'name' => 'Tablette fictive choix', 'station' => 'Poste fictif', 'point_code' => 'poste_1', 'token_hash' => hash('sha256', CHOICE_TOKEN), 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$this->b, $this->closed] as $event) {
        DB::table('device_activities')->insert(['id' => (string) Str::uuid(), 'device_id' => $this->device, 'event_id' => $event, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->headers = ['X-Device-Token' => CHOICE_TOKEN];
});

it('propose à l’agent les seules activités autorisées et ouvertes, en marquant celle en cours', function () {
    $list = collect($this->getJson('/poste/api/activites', $this->headers)->assertOk()->json('activites'));
    expect($list->pluck('id')->sort()->values()->all())->toBe(collect([$this->a, $this->b])->sort()->values()->all())
        ->and($list->firstWhere('id', $this->a)['current'])->toBeTrue()->and($list->firstWhere('id', $this->b)['current'])->toBeFalse();
});

it('change l’activité du jour, change la version de la copie et sert la liste de la nouvelle activité', function () {
    $before = $this->getJson('/poste/api/liste/version', $this->headers)->assertOk()->json('version');
    $this->postJson('/poste/api/activite', ['event_id' => $this->b], $this->headers)->assertOk();
    expect(DB::table('devices')->where('id', $this->device)->value('event_id'))->toBe($this->b);
    $after = $this->getJson('/poste/api/liste/version', $this->headers)->assertOk()->json('version');
    expect($after)->not->toBe($before);
    expect($this->getJson('/poste/api/liste', $this->headers)->assertOk()->json('event.id'))->toBe($this->b);
    expect(DB::table('audit_logs')->where('action', 'poste.activite_changee')->count())->toBe(1);
});

it('refuse une activité non autorisée ou close, et ne change rien', function () {
    $unknown = choiceActivity('Activité fictive non autorisée');
    $this->postJson('/poste/api/activite', ['event_id' => $unknown], $this->headers)->assertForbidden()->assertJsonPath('code', 'activite_non_autorisee');
    $this->postJson('/poste/api/activite', ['event_id' => $this->closed], $this->headers)->assertStatus(409);
    expect(DB::table('devices')->where('id', $this->device)->value('event_id'))->toBe($this->a);
});

it('permet de quitter une activité devenue close', function () {
    DB::table('events')->where('id', $this->a)->update(['status' => 'clos']);
    $this->getJson('/poste/api/liste/version', $this->headers)->assertStatus(409);
    $this->getJson('/poste/api/activites', $this->headers)->assertOk();
    $this->postJson('/poste/api/activite', ['event_id' => $this->b], $this->headers)->assertOk();
    $this->getJson('/poste/api/liste/version', $this->headers)->assertOk();
});
