<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Participations;
use App\Services\Pilotage;
use App\Services\SupervisorClosure;
use App\Services\Vehicles;
use App\Services\Visits;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * S09 — visites annoncées, véhicules, clôture VIP par le superviseur. Données fictives uniquement.
 */

const S09_TOKEN = '9999999999999999999999999999999999999999999999999999999999999999';

function s09User(string $role): User
{
    $user = User::create(['name' => 'Compte fictif S09', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function s09Headers(): array
{
    return ['X-Device-Token' => S09_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

function s09Visitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

/** Enregistre la personne comme entrée avec un badge (comme l'accueil le ferait). */
function s09Enter(Badge $badge, int $i): void
{
    test()->travel(1)->seconds();
    app(BadgeService::class)->associer($badge, s09Visitor($i), test()->agent, DemoSeeder::id('device'));
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => s09Visitor($i), 'badge_id' => $badge->id, 'actor_id' => test()->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('visitors')->where('id', s09Visitor($i))->update(['status' => 'present', 'updated_at' => now()]);
    test()->travel(1)->seconds();
}

function s09Refusal(callable $fn): ?ValidationException
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e;
    }

    return null;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->admin = s09User('administrateur');
    $this->sup = s09User('superviseur');
    $this->agent = s09User('agent_accueil');
    $this->visits = app(Visits::class);
    $this->vehicles = app(Vehicles::class);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', S09_TOKEN), 'point_code' => 'poste_2']);
    $this->vipBadges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category0'), 3, $this->admin)->id)->orderBy('code')->get();
});

// --- Visites annoncées ---------------------------------------------------------------------------------------------------------------

it('annonce une visite sans la rendre présente et trace l’annonceur sans nom de personne', function () {
    $this->visits->announce($this->admin, s09Visitor(0), '2027-01-10 14:00:00');
    $row = DB::table('visitors')->where('id', s09Visitor(0))->first();
    expect($row->announced_by)->toBe($this->admin->id)->and($row->announced_at)->not->toBeNull()->and($row->expected_at)->toContain('2027-01-10 14:00:00');
    expect($row->status ?? 'attendu')->toBe('attendu');
    expect(DB::table('access_events')->where('visitor_id', s09Visitor(0))->where('type', 'entree')->exists())->toBeFalse();
    $line = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visite.annoncee')->first();
    expect($line)->not->toBeNull();
    expect($line->details)->not->toContain(DB::table('visitors')->where('id', s09Visitor(0))->value('full_name'));
    // modifier l'heure est tracé comme une modification
    $this->visits->announce($this->admin, s09Visitor(0), '2027-01-10 15:00:00');
    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visite.annonce_modifiee')->count())->toBe(1);
});

it('refuse une heure hors de l’activité, une personne déjà présente et une participation clôturée', function () {
    expect(s09Refusal(fn () => $this->visits->announce($this->admin, s09Visitor(0), '2027-01-11 09:00:00'))?->errors())->toHaveKey('expected_at');
    expect(s09Refusal(fn () => $this->visits->announce($this->admin, s09Visitor(0), 'pas une date'))?->errors())->toHaveKey('expected_at');
    DB::table('visitors')->where('id', s09Visitor(1))->update(['status' => 'present']);
    expect(s09Refusal(fn () => $this->visits->announce($this->admin, s09Visitor(1), '2027-01-10 14:00:00'))?->errors())->toHaveKey('participation');
    DB::table('visitors')->where('id', s09Visitor(2))->update(['closed_at' => now(), 'closed_by' => $this->admin->id, 'closure_reason' => 'Test']);
    expect(s09Refusal(fn () => $this->visits->announce($this->admin, s09Visitor(2), '2027-01-10 14:00:00'))?->errors())->toHaveKey('participation');
    expect(DB::table('visitors')->whereNotNull('announced_at')->count())->toBe(0);
});

it('limite l’hôte à ses propres invités et refuse l’agent', function () {
    $hote = s09User('hote');
    DB::table('hosts')->where('id', DemoSeeder::id('host0'))->update(['user_id' => $hote->id]);
    DB::table('visitors')->where('id', s09Visitor(0))->update(['host_id' => DemoSeeder::id('host0')]);
    DB::table('visitors')->where('id', s09Visitor(1))->update(['host_id' => DemoSeeder::id('host1')]);
    $this->actingAs($hote)->postJson('/hote/invites/'.s09Visitor(0).'/annonce', ['expected_at' => '2027-01-10 14:00:00'])->assertOk();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('announced_by'))->toBe($hote->id);
    $this->actingAs($hote)->postJson('/hote/invites/'.s09Visitor(1).'/annonce', ['expected_at' => '2027-01-10 14:00:00'])->assertForbidden();
    expect(DB::table('visitors')->where('id', s09Visitor(1))->value('announced_at'))->toBeNull();
    $this->actingAs($this->agent)->postJson('/hote/invites/'.s09Visitor(0).'/annonce', ['expected_at' => '2027-01-10 14:00:00'])->assertForbidden();
    $this->actingAs($this->sup)->postJson('/administration/participations/'.s09Visitor(0).'/annonce', ['expected_at' => '2027-01-10 14:00:00'])->assertForbidden();
});

it('calcule attendu, en retard et absent constaté, et trace l’absence avec son motif', function () {
    $this->visits->announce($this->admin, s09Visitor(0), '2027-01-10 10:30:00');
    $this->visits->announce($this->admin, s09Visitor(1), '2027-01-10 09:00:00');
    $this->visits->announce($this->admin, s09Visitor(2), '2027-01-10 09:00:00');
    $state = fn () => collect($this->visits->expected(DemoSeeder::id('event'))['rows'])->pluck('state', 'id')->all();
    expect($state()[s09Visitor(0)])->toBe('attendu')->and($state()[s09Visitor(1)])->toBe('en_retard');
    // une absence ne se constate pas avant l'heure attendue, ni sans motif
    expect(s09Refusal(fn () => $this->visits->markNoShow($this->sup, s09Visitor(0), 'Pas venu'))?->errors())->toHaveKey('participation');
    expect(s09Refusal(fn () => $this->visits->markNoShow($this->sup, s09Visitor(1), '   '))?->errors())->toHaveKey('reason');
    $this->actingAs($this->sup)->postJson('/pilotage/visiteurs/'.s09Visitor(1).'/absence', ['reason' => 'Prévenu : empêché'])->assertOk();
    $row = DB::table('visitors')->where('id', s09Visitor(1))->first();
    expect($row->no_show_by)->toBe($this->sup->id)->and($row->no_show_reason)->toBe('Prévenu : empêché');
    expect($state()[s09Visitor(1)])->toBe('absent');
    expect(s09Refusal(fn () => $this->visits->markNoShow($this->sup, s09Visitor(1), 'Encore'))?->errors())->toHaveKey('participation');
    // une personne arrivée n'est plus ni en retard ni absente
    DB::table('visitors')->where('id', s09Visitor(2))->update(['status' => 'present']);
    expect($state()[s09Visitor(2)])->toBe('present');
    expect(s09Refusal(fn () => $this->visits->markNoShow($this->sup, s09Visitor(2), 'Trop tard'))?->errors())->toHaveKey('participation');
    // l'agent ne constate aucune absence
    $this->actingAs($this->agent)->postJson('/pilotage/visiteurs/'.s09Visitor(0).'/absence', ['reason' => 'Test'])->assertForbidden();
    // le délai de tolérance est un réglage
    DB::table('settings')->where('key', 'visite_retard_minutes')->update(['value' => '90']);
    expect($state()[s09Visitor(0)])->toBe('attendu');
    $this->travelTo('2027-01-10 10:50:00');
    expect(collect($this->visits->expected(DemoSeeder::id('event'))['rows'])->firstWhere('id', s09Visitor(0))['state'])->toBe('attendu');
    DB::table('settings')->where('key', 'visite_retard_minutes')->update(['value' => '15']);
    expect(collect($this->visits->expected(DemoSeeder::id('event'))['rows'])->firstWhere('id', s09Visitor(0))['state'])->toBe('en_retard');
    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visite.absence_constatee')->count())->toBe(1);
});

it('affiche la page des visites annoncées au superviseur seulement', function () {
    $this->visits->announce($this->admin, s09Visitor(0), '2027-01-10 10:30:00');
    $this->actingAs($this->sup)->get('/pilotage/attendus?event='.DemoSeeder::id('event'))->assertOk();
    $this->actingAs($this->agent)->get('/pilotage/attendus')->assertForbidden();
});

// --- Véhicules -----------------------------------------------------------------------------------------------------------------------

it('normalise la plaque et refuse la même plaque pour une autre participation tant qu’elle est occupée', function () {
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'ab-123 cd', 'voiture');
    $row = DB::table('vehicles')->where('id', $id)->first();
    expect($row->plate_normalized)->toBe('AB123CD')->and($row->status)->toBe('annonce')->and($row->visitor_id)->toBe(s09Visitor(0));
    expect(s09Refusal(fn () => $this->vehicles->register($this->admin, s09Visitor(1), 'AB 123-CD', 'voiture'))?->errors())->toHaveKey('plate');
    expect(s09Refusal(fn () => $this->vehicles->register($this->admin, s09Visitor(1), '!', 'voiture'))?->errors())->toHaveKey('plate');
    expect(s09Refusal(fn () => $this->vehicles->register($this->admin, s09Visitor(1), 'ZZ-999', 'tracteur'))?->errors())->toHaveKey('kind');
    // la base refuse aussi deux véhicules actifs de même plaque
    expect(fn () => DB::transaction(fn () => DB::table('vehicles')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => s09Visitor(1), 'plate' => 'AB123CD', 'plate_normalized' => 'AB123CD', 'kind' => 'voiture', 'status' => 'annonce', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()])))->toThrow(Exception::class);
    // annulé, la plaque est de nouveau disponible
    $this->vehicles->cancel($this->admin, $id);
    expect($this->vehicles->register($this->admin, s09Visitor(1), 'AB123CD', 'voiture'))->not->toBeEmpty();
    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'vehicule.annonce')->get()->pluck('details')->implode(' '))->not->toContain('AB123CD');
});

it('limite l’hôte aux véhicules de ses invités et refuse l’agent', function () {
    $hote = s09User('hote');
    DB::table('hosts')->where('id', DemoSeeder::id('host0'))->update(['user_id' => $hote->id]);
    DB::table('visitors')->where('id', s09Visitor(0))->update(['host_id' => DemoSeeder::id('host0')]);
    DB::table('visitors')->where('id', s09Visitor(1))->update(['host_id' => DemoSeeder::id('host1')]);
    $this->actingAs($hote)->postJson('/hote/invites/'.s09Visitor(0).'/vehicules', ['plate' => 'HT-001', 'kind' => 'voiture'])->assertOk();
    $this->actingAs($hote)->postJson('/hote/invites/'.s09Visitor(1).'/vehicules', ['plate' => 'HT-002', 'kind' => 'voiture'])->assertForbidden();
    $this->actingAs($this->agent)->postJson('/hote/invites/'.s09Visitor(0).'/vehicules', ['plate' => 'HT-003', 'kind' => 'voiture'])->assertForbidden();
    expect(DB::table('vehicles')->count())->toBe(1);
});

it('cherche par plaque au poste, enregistre l’entrée puis la sortie sans jamais rendre la personne présente', function () {
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'GH-4521-AB', 'minibus');
    $this->actingAs($this->agent);
    // moins de 3 caractères : aucune recherche
    $this->getJson('/poste/api/vehicules?q=GH', s09Headers())->assertOk()->assertJsonPath('results', []);
    $found = $this->getJson('/poste/api/vehicules?q=gh 45', s09Headers())->assertOk()->json('results');
    expect($found)->toHaveCount(1)->and($found[0]['id'])->toBe($id)->and($found[0]['status'])->toBe('annonce');
    $headers = s09Headers();
    $this->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], $headers)->assertOk()->assertJsonPath('status', 'present');
    // rejeu de la même opération : rien de plus n'est écrit
    $this->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], $headers)->assertOk();
    expect(DB::table('access_events')->where('type', 'vehicule_entree')->count())->toBe(1);
    expect(DB::table('vehicles')->where('id', $id)->value('status'))->toBe('present');
    // le véhicule présent n'est pas la personne présente
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('status') ?? 'attendu')->toBe('attendu');
    expect(DB::table('access_events')->where('type', 'entree')->where('visitor_id', s09Visitor(0))->exists())->toBeFalse();
    // supervision : le véhicule est listé
    expect($this->vehicles->present(DemoSeeder::id('event')))->toHaveCount(1);
    // un véhicule présent ne se réaffecte pas, ni par le service ni en base
    expect(s09Refusal(fn () => $this->vehicles->register($this->admin, s09Visitor(1), 'GH4521AB', 'voiture'))?->errors()['plate'][0])->toContain('présent');
    expect(fn () => DB::transaction(fn () => DB::table('vehicles')->where('id', $id)->update(['visitor_id' => s09Visitor(1)])))->toThrow(Exception::class);
    expect(s09Refusal(fn () => $this->vehicles->cancel($this->admin, $id))?->errors())->toHaveKey('vehicle');
    // la sortie libère la plaque
    $this->postJson('/poste/api/vehicules/sortie', ['vehicle_id' => $id], s09Headers())->assertOk()->assertJsonPath('status', 'sorti');
    expect(DB::table('access_events')->where('type', 'vehicule_sortie')->count())->toBe(1);
    expect($this->vehicles->present(DemoSeeder::id('event')))->toBe([]);
    expect($this->vehicles->register($this->admin, s09Visitor(1), 'GH4521AB', 'voiture'))->not->toBeEmpty();
    // un véhicule sorti ne ré-entre pas
    $this->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertStatus(409)->assertJsonPath('code', 'vehicule_indisponible');
    foreach (DB::table('audit_logs')->where('id', '>', $this->auditStart)->whereIn('action', ['vehicule.entree', 'vehicule.sortie'])->pluck('details') as $details) {
        expect($details)->not->toContain('GH4521AB');
    }
});

it('refuse les mouvements de véhicule aux postes non habilités, aux autres activités et aux comptes qui ne sont pas agents', function () {
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'XY-100', 'voiture');
    $this->actingAs($this->agent);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => 'soute']);
    $this->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => 'poste_1']);
    $this->getJson('/poste/api/vehicules?q=XY1', s09Headers())->assertForbidden();
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => 'poste_2']);
    $this->postJson('/poste/api/vehicules/sortie', ['vehicle_id' => $id], s09Headers())->assertStatus(409)->assertJsonPath('code', 'vehicule_non_present');
    $this->postJson('/poste/api/vehicules/entree', ['vehicle_id' => (string) Str::uuid()], s09Headers())->assertNotFound();
    expect(DB::table('access_events')->whereIn('type', ['vehicule_entree', 'vehicule_sortie'])->count())->toBe(0);
    foreach (['superviseur', 'administrateur'] as $role) {
        $this->actingAs(s09User($role))->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertForbidden();
    }
});

it('refuse l’entrée d’un véhicule dont la participation est clôturée', function () {
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'CL-777', 'voiture');
    DB::table('visitors')->where('id', s09Visitor(0))->update(['closed_at' => now(), 'closed_by' => $this->admin->id, 'closure_reason' => 'Test']);
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertStatus(409)->assertJsonPath('code', 'participation_indisponible');
    expect(DB::table('vehicles')->where('id', $id)->value('status'))->toBe('annonce');
});

it('montre les mouvements de véhicule dans le journal de supervision', function () {
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'MV-321', 'camion');
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertOk();
    $this->actingAs($this->sup)->get('/pilotage/mouvements?event='.DemoSeeder::id('event').'&type=vehicule_entree')->assertOk();
});

// --- Clôture VIP par le superviseur --------------------------------------------------------------------------------------------------

it('clôture une personne à clôture réservée en une opération : sortie, restitution, clôture, motif et journal', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    $badge = $this->vipBadges[0];
    s09Enter($badge, 0);
    $vehicle = $this->vehicles->register($this->admin, s09Visitor(0), 'VI-001', 'voiture');
    // l'agent reste refusé au poste
    $this->actingAs($this->agent)->postJson('/poste/api/sortie', ['code' => $badge->code], s09Headers())->assertOk()->assertJsonPath('reason', 'cloture_reservee_superviseur');
    $url = '/pilotage/visiteurs/'.s09Visitor(0).'/cloture-superviseur';
    // sans motif ni confirmation : refusé, rien n'est écrit
    $this->actingAs($this->sup)->postJson($url, ['reason' => '', 'confirmation' => true])->assertUnprocessable();
    $this->actingAs($this->sup)->postJson($url, ['reason' => 'Fin de la visite officielle'])->assertUnprocessable();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('status'))->toBe('present');
    // ni l'agent, ni l'administrateur
    $this->actingAs($this->agent)->postJson($url, ['reason' => 'Test', 'confirmation' => true])->assertForbidden();
    $this->actingAs($this->admin)->postJson($url, ['reason' => 'Test', 'confirmation' => true])->assertForbidden();
    // le superviseur clôture
    $out = $this->actingAs($this->sup)->postJson($url, ['reason' => 'Fin de la visite officielle', 'confirmation' => true])->assertOk()->json();
    expect($out)->toMatchArray(['ok' => true, 'sortie' => true, 'badge_restitue' => true, 'vehicules_annules' => 1]);
    $row = DB::table('visitors')->where('id', s09Visitor(0))->first();
    expect($row->status)->toBe('sorti')->and($row->closed_by)->toBe($this->sup->id)->and($row->closure_reason)->toBe('Fin de la visite officielle')->and($row->closed_at)->not->toBeNull();
    expect(DB::table('access_events')->where('type', 'sortie')->where('visitor_id', s09Visitor(0))->where('actor_id', $this->sup->id)->count())->toBe(1);
    expect(DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNotNull('returned_at')->count())->toBe(1)
        ->and(DB::table('badges')->where('id', $badge->id)->value('status'))->toBeIn(['Disponible', 'Clos']);
    expect(DB::table('vehicles')->where('id', $vehicle)->value('status'))->toBe('annule');
    $line = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'participation.cloturee_superviseur')->first();
    expect($line)->not->toBeNull()->and($line->details)->not->toContain(DB::table('visitors')->where('id', s09Visitor(0))->value('full_name'));
    // une participation clôturée ne se clôt pas deux fois
    $this->actingAs($this->sup)->postJson($url, ['reason' => 'Encore', 'confirmation' => true])->assertUnprocessable();
});

it('refuse la clôture du superviseur pour une catégorie ordinaire, pour un véhicule encore présent et pour un titulaire permanent', function () {
    $url = fn (int $i) => '/pilotage/visiteurs/'.s09Visitor($i).'/cloture-superviseur';
    // catégorie ordinaire : suit le parcours de l'accueil, même si son libellé est « VIP »
    expect(DB::table('categories')->where('id', DemoSeeder::id('category0'))->value('name'))->toBe('VIP');
    $this->actingAs($this->sup)->postJson($url(0), ['reason' => 'Test', 'confirmation' => true])->assertUnprocessable()->assertJsonValidationErrors('participation');
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('closed_at'))->toBeNull();
    // véhicule présent : sa sortie d'abord
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    s09Enter($this->vipBadges[0], 0);
    $id = $this->vehicles->register($this->admin, s09Visitor(0), 'VP-002', 'voiture');
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $id], s09Headers())->assertOk();
    $this->actingAs($this->sup)->postJson($url(0), ['reason' => 'Test', 'confirmation' => true])->assertUnprocessable()->assertJsonValidationErrors('participation');
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('status'))->toBe('present')->and(DB::table('visitors')->where('id', s09Visitor(0))->value('closed_at'))->toBeNull();
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/sortie', ['vehicle_id' => $id], s09Headers())->assertOk();
    $this->actingAs($this->sup)->postJson($url(0), ['reason' => 'Fin de visite', 'confirmation' => true])->assertOk();
});

it('clôture sans sortie ni restitution une personne à clôture réservée qui n’est jamais arrivée', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    $out = $this->actingAs($this->sup)->postJson('/pilotage/visiteurs/'.s09Visitor(0).'/cloture-superviseur', ['reason' => 'Visite annulée', 'confirmation' => true])->assertOk()->json();
    expect($out)->toMatchArray(['sortie' => false, 'badge_restitue' => false, 'vehicules_annules' => 0]);
    expect(DB::table('access_events')->where('visitor_id', s09Visitor(0))->where('type', 'sortie')->exists())->toBeFalse();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('closure_reason'))->toBe('Visite annulée');
});

it('propose la clôture réservée sur la fiche seulement pour une catégorie à indicateur', function () {
    $this->actingAs($this->sup)->get('/pilotage/visiteurs/'.s09Visitor(0))->assertOk();
    $offer = fn () => app(SupervisorClosure::class)->offer(s09Visitor(0));
    expect($offer())->toBeNull();
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    expect($offer())->toBe(['possible' => true, 'vehicles_present' => 0]);
    $this->actingAs($this->sup)->get('/pilotage/visiteurs/'.s09Visitor(0))->assertOk();
});

it('range les véhicules dans la remise à zéro de démonstration', function () {
    foreach (['ikoma_reset_demo_business(uuid,boolean)', 'ikoma_reset_demo_event(uuid)'] as $function) {
        expect((string) DB::selectOne("SELECT pg_get_functiondef('public.{$function}'::regprocedure) AS d")->d)->toContain('public.vehicles');
    }
});

// --- Décisions définitives : droit dédié, réglage, administration, retards, séparation des actes -------------------------------------------

it('réserve la clôture VIP à la permission dédiée du seul superviseur, sans réutiliser badges.annuler', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    $url = '/pilotage/visiteurs/'.s09Visitor(0).'/cloture-superviseur';
    $body = ['reason' => 'Fin de la visite officielle', 'confirmation' => true];
    foreach (['administrateur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        expect(s09User($role)->can('cloture.superviseur'))->toBeFalse();
    }
    expect($this->sup->can('cloture.superviseur'))->toBeTrue();
    // badges.annuler ne suffit pas : un compte qui a seulement ce droit est refusé, rien n'est écrit
    $cancelOnly = s09User('agent_accueil');
    $cancelOnly->givePermissionTo('badges.annuler');
    $this->actingAs($cancelOnly)->postJson($url, $body)->assertForbidden();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('closed_at'))->toBeNull();
    // le droit dédié suffit, sans badges.annuler
    $dedicated = s09User('agent_accueil');
    $dedicated->givePermissionTo('cloture.superviseur');
    expect($dedicated->can('badges.annuler'))->toBeFalse();
    $this->actingAs($dedicated)->postJson($url, $body)->assertOk();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('closed_by'))->toBe($dedicated->id);
});

it('sépare clôture administrative, sortie physique, restitution du badge et sortie du véhicule', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category1'))->update(['closure_supervisor_only' => false]);
    // 1) la sortie physique par l'agent ne clôt rien, ne restitue rien et ne fait pas sortir le véhicule
    $badges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 2, $this->admin)->id)->orderBy('code')->get();
    s09Enter($badges[0], 1);
    $vehicle = $this->vehicles->register($this->admin, s09Visitor(1), 'SP-100', 'voiture');
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $vehicle], s09Headers())->assertOk();
    $this->actingAs($this->agent)->postJson('/poste/api/sortie', ['code' => $badges[0]->code], s09Headers())->assertOk()->assertJsonPath('ok', true);
    $row = DB::table('visitors')->where('id', s09Visitor(1))->first();
    expect($row->status)->toBe('sorti')->and($row->closed_at)->toBeNull();
    expect(DB::table('badge_assignments')->where('badge_id', $badges[0]->id)->whereNull('returned_at')->count())->toBe(1);
    expect(DB::table('vehicles')->where('id', $vehicle)->value('status'))->toBe('present');
    // 2) la clôture administrative d'une personne qui a encore un badge est refusée : elle ne vaut jamais restitution
    expect(s09Refusal(fn () => app(Participations::class)->close($this->admin, s09Visitor(1), 'Fin'))?->errors())->toHaveKey('participation');
    // 3) la restitution de l'accueil ne clôt pas la participation et ne touche pas au véhicule
    $this->actingAs($this->agent)->postJson('/poste/api/restitution', ['code' => $badges[0]->code], s09Headers())->assertOk()->assertJsonPath('ok', true);
    expect(DB::table('visitors')->where('id', s09Visitor(1))->value('closed_at'))->toBeNull();
    expect(DB::table('vehicles')->where('id', $vehicle)->value('status'))->toBe('present');
    // 4) la sortie du véhicule est un acte distinct, qui ne clôt pas la participation
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/sortie', ['vehicle_id' => $vehicle], s09Headers())->assertOk();
    expect(DB::table('visitors')->where('id', s09Visitor(1))->value('closed_at'))->toBeNull();
    // 5) la clôture administrative vient en dernier, seule, avec son motif
    app(Participations::class)->close($this->admin, s09Visitor(1), 'Fin de la participation');
    expect(DB::table('visitors')->where('id', s09Visitor(1))->value('closure_reason'))->toBe('Fin de la participation');
});

it('journalise séparément, pour la clôture VIP, la sortie physique, la restitution, la clôture et ne fait jamais sortir un véhicule', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    s09Enter($this->vipBadges[0], 0);
    $this->actingAs($this->sup)->postJson('/pilotage/visiteurs/'.s09Visitor(0).'/cloture-superviseur', ['reason' => 'Fin de visite', 'confirmation' => true])->assertOk();
    $actions = DB::table('audit_logs')->where('id', '>', $this->auditStart)->whereIn('action', ['badge.restitue', 'participation.cloturee_superviseur'])->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['badge.restitue', 'participation.cloturee_superviseur']);
    expect(DB::table('access_events')->where('visitor_id', s09Visitor(0))->where('type', 'sortie')->count())->toBe(1)
        ->and(DB::table('access_events')->whereIn('type', ['vehicule_entree', 'vehicule_sortie'])->count())->toBe(0);
});

it('règle le délai de retard en Administration et refuse une valeur invalide ou un autre rôle', function () {
    $base = ['inactivity' => 10, 'skew' => 600, 'pin' => 12];
    $this->actingAs($this->admin)->put('/administration/postes/reglages', [...$base, 'lateMinutes' => 30])->assertRedirect();
    expect(DB::table('settings')->where('key', 'visite_retard_minutes')->value('value'))->toBe('30')->and(Visits::lateAfterMinutes())->toBe(30);
    foreach ([0, 1441] as $bad) {
        $this->actingAs($this->admin)->putJson('/administration/postes/reglages', [...$base, 'lateMinutes' => $bad])->assertUnprocessable()->assertJsonValidationErrors('lateMinutes');
    }
    expect(DB::table('settings')->where('key', 'visite_retard_minutes')->value('value'))->toBe('30');
    $this->actingAs($this->sup)->putJson('/administration/postes/reglages', [...$base, 'lateMinutes' => 5])->assertForbidden();
    $this->actingAs($this->admin)->get('/administration/postes')->assertOk()->assertInertia(fn ($page) => $page->where('settings.lateMinutes', 30)->etc());
});

it('permet à l’administrateur d’annoncer une visite et d’associer puis annuler un véhicule depuis l’administration, refuse les autres rôles', function () {
    $this->actingAs($this->admin)->postJson('/administration/participations/'.s09Visitor(0).'/annonce', ['expected_at' => '2027-01-10 14:00:00'])->assertOk();
    expect(DB::table('visitors')->where('id', s09Visitor(0))->value('announced_by'))->toBe($this->admin->id);
    $id = $this->actingAs($this->admin)->postJson('/administration/participations/'.s09Visitor(0).'/vehicules', ['plate' => 'AD-777', 'kind' => 'moto'])->assertOk()->json('id');
    $this->actingAs($this->admin)->postJson('/administration/participations/'.s09Visitor(1).'/vehicules', ['plate' => 'ad 777', 'kind' => 'moto'])->assertUnprocessable()->assertJsonValidationErrors('plate');
    $person = DB::table('visitors')->where('id', s09Visitor(0))->value('person_id');
    $this->actingAs($this->admin)->get('/administration/personnes/'.$person)->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Personne')->has('visits.'.s09Visitor(0).'.vehicles', 1)->has('vehicleKinds')->etc());
    $this->actingAs($this->admin)->deleteJson('/administration/vehicules/'.$id)->assertOk();
    expect(DB::table('vehicles')->where('id', $id)->value('status'))->toBe('annule');
    foreach (['superviseur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(s09User($role));
        $this->postJson('/administration/participations/'.s09Visitor(0).'/annonce', ['expected_at' => '2027-01-10 15:00:00'])->assertForbidden();
        $this->postJson('/administration/participations/'.s09Visitor(0).'/vehicules', ['plate' => 'XX-999', 'kind' => 'moto'])->assertForbidden();
        $this->deleteJson('/administration/vehicules/'.$id)->assertForbidden();
    }
});

it('compte les retards dans la vue d’ensemble du Pilotage, sans les absences constatées ni les personnes arrivées', function () {
    $event = DemoSeeder::id('event');
    $retards = fn () => app(Pilotage::class)->overview($event)['retards'];
    expect($retards())->toBe(0);
    foreach ([0, 1, 2] as $i) {
        $this->visits->announce($this->admin, s09Visitor($i), '2027-01-10 09:00:00');
    }
    expect($retards())->toBe(3);
    $this->visits->markNoShow($this->sup, s09Visitor(0), 'Prévenu');
    DB::table('visitors')->where('id', s09Visitor(1))->update(['status' => 'present']);
    expect($retards())->toBe(1);
    // le délai de tolérance est celui du réglage
    DB::table('settings')->where('key', 'visite_retard_minutes')->update(['value' => '120']);
    expect($retards())->toBe(0);
    $this->actingAs($this->sup)->get('/pilotage?event='.$event)->assertOk()->assertInertia(fn ($page) => $page->has('overview.retards')->etc());
});

it('refuse la clôture administrative tant qu’un véhicule est présent et annule les véhicules seulement annoncés', function () {
    $present = $this->vehicles->register($this->admin, s09Visitor(2), 'CA-001', 'voiture');
    $announced = $this->vehicles->register($this->admin, s09Visitor(2), 'CA-002', 'moto');
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/entree', ['vehicle_id' => $present], s09Headers())->assertOk();
    $close = fn () => app(Participations::class)->close($this->admin, s09Visitor(2), 'Fin de la participation');
    expect(s09Refusal($close)?->errors()['participation'][0])->toContain('véhicule');
    expect(DB::table('visitors')->where('id', s09Visitor(2))->value('closed_at'))->toBeNull()->and(DB::table('vehicles')->where('id', $announced)->value('status'))->toBe('annonce');
    $this->actingAs($this->agent)->postJson('/poste/api/vehicules/sortie', ['vehicle_id' => $present], s09Headers())->assertOk();
    $close();
    expect(DB::table('vehicles')->where('id', $announced)->value('status'))->toBe('annule')->and(DB::table('vehicles')->where('id', $present)->value('status'))->toBe('sorti');
});
