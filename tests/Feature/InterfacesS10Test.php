<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Visits;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * S10 — interfaces opérationnelles : centre de supervision, consultations du poste (P2 et soute), parcours de l'atelier. Données fictives uniquement.
 */

const S10_TOKEN = '1010101010101010101010101010101010101010101010101010101010101010';

function s10User(string $role): User
{
    $user = User::create(['name' => 'Compte fictif S10', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function s10Headers(): array
{
    return ['X-Device-Token' => S10_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

function s10Visitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

/** Entrée enregistrée comme l'accueil le ferait (badge attribué, mouvement « entrée », personne présente). */
function s10Enter(Badge $badge, int $i): void
{
    test()->travel(1)->seconds();
    app(BadgeService::class)->associer($badge, s10Visitor($i), test()->agent, DemoSeeder::id('device'));
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => s10Visitor($i), 'badge_id' => $badge->id, 'actor_id' => test()->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('visitors')->where('id', s10Visitor($i))->update(['status' => 'present', 'updated_at' => now()]);
    test()->travel(1)->seconds();
}

function s10RefusedControl(string $deviceId, string $reason = 'annule'): void
{
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => null, 'badge_id' => null, 'actor_id' => test()->agent->id, 'type' => 'controle', 'checkpoint' => 'Point fictif', 'occurred_at' => now(),
        'details' => json_encode(['verdict' => 'refuse', 'raison' => $reason, 'poste_id' => $deviceId], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->event = DemoSeeder::id('event');
    $this->deviceId = DemoSeeder::id('device');
    $this->admin = s10User('administrateur');
    $this->sup = s10User('superviseur');
    $this->agent = s10User('agent_accueil');
    DB::table('devices')->where('id', $this->deviceId)->update(['token_hash' => hash('sha256', S10_TOKEN), 'point_code' => 'poste_2']);
    $this->vipBadges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate($this->event, DemoSeeder::id('category0'), 2, $this->admin)->id)->orderBy('code')->get();
    $this->visitorBadges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate($this->event, DemoSeeder::id('category1'), 2, $this->admin)->id)->orderBy('code')->get();
});

// --- Centre de supervision ------------------------------------------------------------------------------------------------------------

it('rassemble dans le tableau de bord tout ce que le superviseur doit voir, à partir des données existantes', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    s10Enter($this->vipBadges[0], 0);
    s10Enter($this->visitorBadges[0], 1);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event, 'visitor_id' => s10Visitor(1), 'badge_id' => $this->visitorBadges[0]->id, 'actor_id' => $this->agent->id, 'type' => 'sortie', 'checkpoint' => 'Poste fictif', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('visitors')->where('id', s10Visitor(1))->update(['status' => 'sorti']);
    app(Visits::class)->announce($this->admin, s10Visitor(2), '2027-01-10 09:00:00');
    DB::table('vehicles')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event, 'visitor_id' => s10Visitor(0), 'plate' => 'CE-100', 'plate_normalized' => 'CE100', 'kind' => 'voiture', 'status' => 'present', 'entered_at' => now(), 'entered_by' => $this->agent->id, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => s10Visitor(3), 'actor_id' => $this->agent->id, 'reason' => 'Motif fictif', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    s10RefusedControl($this->deviceId);
    DB::table('sync_operations')->insert(['id' => (string) Str::uuid(), 'device_id' => $this->deviceId, 'user_id' => $this->agent->id, 'type' => 'entree', 'payload_hash' => 'x', 'received_at' => now(), 'processed_at' => now(), 'status_code' => 200, 'result' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->sup)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->component('Pilotage/Index')
        ->has('centre', fn ($c) => $c->has('now')->where('postes.0.last_operation.type', 'entree')->where('postes.0.last_operation.accepted', true)->where('postes.0.state', 'actif')
            ->where('operations.0.type', 'entree')->where('presents.count', 1)->has('presents.rows', 1)->has('movements')
            ->where('visits.counts.en_retard', 1)->has('visits.rows', 1)->where('vehicles_count', 1)->where('vehicles.0.plate', 'CE-100')
            ->where('badges_to_return.count', 1)->where('badges_to_return.rows.0.code', $this->visitorBadges[0]->code)
            ->where('refusals.pending', 1)->has('incidents', 1)->has('closable', 1)->where('closable.0.badge_code', $this->vipBadges[0]->code)->etc())->etc());
});

it('ne propose les cibles de clôture qu’au détenteur du droit dédié', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    s10Enter($this->vipBadges[0], 0);
    $this->actingAs($this->admin)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->has('centre.closable', 0)->etc());
    $this->actingAs($this->sup)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->has('centre.closable', 1)->etc());
});

it('affiche un centre vide sans erreur quand rien n’a encore eu lieu', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['token_hash' => null]);
    $this->actingAs($this->sup)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->where('centre.presents.count', 0)->where('centre.vehicles_count', 0)->where('centre.badges_to_return.count', 0)->has('centre.postes', 0)->has('centre.closable', 0)->etc());
});

it('signale un poste sans nouvelles et un poste révoqué', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['last_synced_at' => now()->subHours(3)]);
    $this->actingAs($this->sup)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->where('centre.postes.0.state', 'silencieux')->etc());
    DB::table('devices')->where('id', $this->deviceId)->update(['revoked_at' => now()]);
    $this->actingAs($this->sup)->get('/pilotage?event='.$this->event)->assertOk()->assertInertia(fn ($page) => $page->where('centre.postes.0.state', 'revoque')->etc());
});

it('ne laisse au superviseur aucune action réservée à l’agent ou à l’administrateur', function () {
    $this->actingAs($this->sup);
    $id = s10Visitor(0);
    // agent : opérations de poste
    foreach ([['post', '/poste/api/sortie', ['code' => 'B-001']], ['post', '/poste/api/restitution', ['code' => 'B-001']], ['post', '/poste/api/entree', ['visitor_id' => $id]], ['post', '/poste/api/vehicules/entree', ['vehicle_id' => (string) Str::uuid()]],
        ['post', '/poste/api/vehicules/sortie', ['vehicle_id' => (string) Str::uuid()]], ['get', '/poste/api/attendus', []], ['get', '/poste/api/incidents', []]] as [$method, $url, $body]) {
        $this->json($method, $url, $body, s10Headers())->assertForbidden();
    }
    // administrateur : annonces, véhicules, réglages, atelier
    foreach ([['post', "/administration/participations/$id/annonce", ['expected_at' => '2027-01-10 14:00:00']], ['post', "/administration/participations/$id/vehicules", ['plate' => 'ZZ-1', 'kind' => 'voiture']],
        ['delete', '/administration/vehicules/'.Str::uuid(), []], ['put', '/administration/postes/reglages', ['inactivity' => 10, 'skew' => 600, 'pin' => 12]], ['get', '/administration/atelier/parcours', []], ['get', '/administration/atelier', []]] as [$method, $url, $body]) {
        $this->json($method, $url, $body)->assertForbidden();
    }
});

// --- Consultations du poste : accueil (P2) et soute (P5) ---------------------------------------------------------------------------------

it('montre au poste d’accueil les visites attendues et en retard, sans les personnes déjà arrivées', function () {
    app(Visits::class)->announce($this->admin, s10Visitor(0), '2027-01-10 10:30:00');
    app(Visits::class)->announce($this->admin, s10Visitor(1), '2027-01-10 09:00:00');
    app(Visits::class)->announce($this->admin, s10Visitor(2), '2027-01-10 09:00:00');
    DB::table('visitors')->where('id', s10Visitor(2))->update(['status' => 'present']);
    $rows = $this->actingAs($this->agent)->getJson('/poste/api/attendus', s10Headers())->assertOk()->json('results');
    expect(collect($rows)->pluck('state', 'id')->all())->toBe([s10Visitor(0) => 'attendu', s10Visitor(1) => 'en_retard']);
    expect(array_keys($rows[0]))->toContain('full_name')->toContain('plates');
});

it('refuse les visites attendues à un poste sans capacité d’accueil, hors agent ou sans identification', function () {
    $this->actingAs($this->agent);
    foreach (['soute', 'poste_1', 'hotel', null] as $point) {
        DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => $point]);
        $this->getJson('/poste/api/attendus', s10Headers())->assertForbidden();
    }
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'poste_2']);
    $this->actingAs($this->admin)->getJson('/poste/api/attendus', s10Headers())->assertForbidden();
});

it('montre à l’accueil les contrôles refusés de ce poste et les accès refusés de l’activité', function () {
    s10RefusedControl($this->deviceId, 'annule');
    s10RefusedControl((string) Str::uuid(), 'inconnu'); // un autre poste : jamais montré
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => s10Visitor(3), 'actor_id' => $this->agent->id, 'reason' => 'Motif fictif', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $view = $this->actingAs($this->agent)->getJson('/poste/api/incidents', s10Headers())->assertOk()->json();
    expect($view['can_see_refusals'])->toBeTrue()->and($view['controls'])->toHaveCount(1)->and($view['controls'][0]['reason'])->toBe('annule')->and($view['refusals'])->toHaveCount(1);
});

it('limite la soute à ses propres contrôles refusés et lui interdit les visites attendues', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    s10RefusedControl($this->deviceId, 'hors_periode');
    DB::table('refusals')->insert(['id' => (string) Str::uuid(), 'visitor_id' => s10Visitor(3), 'actor_id' => $this->agent->id, 'reason' => 'Motif fictif', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent);
    $view = $this->getJson('/poste/api/incidents', s10Headers())->assertOk()->json();
    expect($view['can_see_refusals'])->toBeFalse()->and($view['refusals'])->toBe([])->and($view['controls'])->toHaveCount(1)->and($view['controls'][0]['reason'])->toBe('hors_periode');
    $this->getJson('/poste/api/attendus', s10Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    // la soute ne sort ni ne restitue : les opérations d'accueil restent refusées par le serveur
    $this->postJson('/poste/api/sortie', ['code' => 'B-001'], s10Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/restitution', ['code' => 'B-001'], s10Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
});

it('refuse la consultation des incidents à un poste sans capacité', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'poste_1']);
    $this->actingAs($this->agent)->getJson('/poste/api/incidents', s10Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
});

it('expose à la tablette les capacités du poste pour adapter l’écran', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    $this->actingAs($this->agent)->getJson('/poste/api/liste', s10Headers())->assertOk()->assertJsonPath('poste.point_code', 'soute')->assertJsonPath('poste.capabilities', ['scan']);
});

// --- Parcours de l'atelier -------------------------------------------------------------------------------------------------------------

it('range les parcours en deux domaines distincts avec leurs nombres, pour l’administrateur seulement', function () {
    $this->actingAs($this->admin)->get('/administration/atelier/parcours')->assertOk()->assertInertia(fn ($page) => $page->component('Admin/AtelierParcours')
        ->has('visitors.badges')->where('visitors.badges.Disponible', fn ($n) => $n >= 4)->has('visitors.batches')->has('visitors.print_runs')->has('visitors.to_return')
        ->has('cards', fn ($c) => $c->has('holders')->has('active')->has('inactive')->has('prints')));
    foreach (['superviseur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(s10User($role))->get('/administration/atelier/parcours')->assertForbidden();
    }
});

it('ne compte pas les badges des cartes permanentes parmi les badges visiteurs', function () {
    $before = DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false)->count();
    $this->actingAs($this->admin)->get('/administration/atelier/parcours')->assertOk()->assertInertia(fn ($page) => $page->where('visitors.badges.Disponible', DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false)->where('b.status', 'Disponible')->count())->etc());
    expect($before)->toBeGreaterThan(0);
});
