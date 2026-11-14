<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\ParticipationEntitlements;
use App\Services\Presence;
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
    DB::table('devices')->where('id', $this->deviceId)->update(['last_synced_at' => now()]);
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
        ['delete', '/administration/vehicules/'.Str::uuid(), []], ['put', '/administration/postes/reglages', ['inactivity' => 10, 'skew' => 600, 'pin' => 12]], ['get', '/administration/atelier', []]] as [$method, $url, $body]) {
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
    // Ordre chronologique : la visite de 09 h (en retard) précède celle de 10 h 30.
    expect(collect($rows)->pluck('state', 'id')->all())->toEqual([s10Visitor(1) => 'en_retard', s10Visitor(0) => 'attendu']);
    expect(array_keys($rows[0]))->toContain('full_name')->toContain('plates');
});

it('refuse les visites attendues à un poste sans capacité d’accueil et à tout compte qui n’est pas agent', function () {
    $this->actingAs($this->agent);
    foreach (['soute', 'poste_1', 'hotel'] as $point) {
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
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'hotel']);
    $this->actingAs($this->agent)->getJson('/poste/api/incidents', s10Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
});

it('expose à la tablette les capacités du poste pour adapter l’écran', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    $this->actingAs($this->agent)->getJson('/poste/api/liste', s10Headers())->assertOk()->assertJsonPath('poste.point_code', 'soute')->assertJsonPath('poste.capabilities', ['scan']);
});

// --- Parcours de l'atelier -------------------------------------------------------------------------------------------------------------

it('ne compte pas les badges des cartes permanentes parmi les badges visiteurs dans les compteurs de l’espace', function () {
    $before = DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->count();
    $available = DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('b.status', 'Disponible')->count();
    $this->actingAs($this->admin)->get('/administration/badges')->assertOk()->assertInertia(fn ($page) => $page->where('badgesCartes.visitors.available', $available)->has('badgesCartes.cards.holders'));
    expect($before)->toBeGreaterThan(0)->and($available)->toBeGreaterThanOrEqual(4);
});

// --- Soute (P5) : sens du passage choisi avant le scan --------------------------------------------------------------------------------

function s10Scan(string $code, ?string $direction, ?array $point = null)
{
    $body = array_filter(['code' => $code, 'point' => $point, 'direction' => $direction], fn ($v) => $v !== null);

    return test()->postJson('/poste/api/controle', $body, s10Headers());
}

it('exige le sens Entrée ou Sortie à la soute avant tout scan et n’écrit rien sans lui', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    s10Enter($this->visitorBadges[0], 1);
    $before = DB::table('access_events')->where('type', 'controle')->count();
    $this->actingAs($this->agent);
    s10Scan($this->visitorBadges[0]->code, null)->assertStatus(422)->assertJsonPath('code', 'sens_requis');
    s10Scan($this->visitorBadges[0]->code, 'nord')->assertStatus(422)->assertJsonValidationErrors('direction');
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe($before);
});

it('inscrit le sens dans le mouvement et l’expose au journal de supervision, sans changer la présence', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    s10Enter($this->visitorBadges[0], 1);
    $presence = app(Presence::class);
    $present = $presence->count($this->event);
    $this->actingAs($this->agent);
    $zone = ['type' => 'zone', 'id' => DemoSeeder::id('zone1')];
    s10Scan($this->visitorBadges[0]->code, 'entree', $zone)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('direction', 'entree');
    $this->travel(5)->seconds();
    s10Scan($this->visitorBadges[0]->code, 'sortie', $zone)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('direction', 'sortie');
    $events = DB::table('access_events')->where('type', 'controle')->orderBy('occurred_at')->get();
    expect($events->map(fn ($e) => json_decode($e->details, true)['direction'])->all())->toBe(['entree', 'sortie']);
    // un passage à la soute n'est ni une entrée ni une sortie du site
    expect($presence->count($this->event))->toBe($present)->and(DB::table('access_events')->whereIn('type', ['entree', 'sortie'])->where('checkpoint', '<>', 'Poste fictif')->count())->toBe(0);
    $this->actingAs($this->sup)->get('/pilotage/mouvements?event='.$this->event.'&type=controle')->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows.0.direction', 'sortie')->where('rows.1.direction', 'entree')->etc());
});

it('applique les habilitations dans les deux sens, y compris la zone refusée', function () {
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    s10Enter($this->visitorBadges[0], 1);
    $this->actingAs($this->agent);
    $denied = ['type' => 'zone', 'id' => DemoSeeder::id('zone2')];
    foreach (['entree', 'sortie'] as $direction) {
        s10Scan($this->visitorBadges[0]->code, $direction, $denied)->assertOk()->assertJson(['valid' => true, 'zone_ok' => false, 'direction' => $direction]);
        $this->travel(5)->seconds();
    }
    // une habilitation exceptionnelle de participation ouvre la zone, dans les deux sens
    app(ParticipationEntitlements::class)->grant(s10Visitor(1), DemoSeeder::id('zone2'), 'exceptionnelle', 'Visite autorisée', now()->subMinute()->toIso8601String(), now()->addHour()->toIso8601String(), $this->admin);
    foreach (['entree', 'sortie'] as $direction) {
        s10Scan($this->visitorBadges[0]->code, $direction, $denied)->assertOk()->assertJson(['valid' => true, 'zone_ok' => true, 'direction' => $direction]);
        $this->travel(5)->seconds();
    }
    // un badge annulé est refusé dans les deux sens, avec son sens consigné
    app(BadgeService::class)->annuler($this->visitorBadges[0], $this->sup, 'Perdu');
    foreach (['entree', 'sortie'] as $direction) {
        s10Scan($this->visitorBadges[0]->code, $direction)->assertOk()->assertJson(['valid' => false, 'direction' => $direction]);
        $this->travel(5)->seconds();
    }
    expect(DB::table('access_events')->where('type', 'controle')->whereRaw("details->>'direction' IS NOT NULL")->count())->toBe(6);
});

it('ne demande aucun sens au poste d’accueil mais accepte celui qu’on lui donne', function () {
    s10Enter($this->visitorBadges[0], 1);
    $this->actingAs($this->agent);
    s10Scan($this->visitorBadges[0]->code, null)->assertOk()->assertJsonPath('valid', true)->assertJsonPath('direction', null);
});

// --- Atelier : gestion des modèles de badges -------------------------------------------------------------------------------------------

function s10OtherEvent(): string
{
    $id = (string) Str::uuid();
    DB::table('events')->insert(['id' => $id, 'name' => 'Autre activité fictive', 'starts_at' => '2027-02-01 08:00:00+00', 'ends_at' => '2027-02-02 20:00:00+00', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function s10SaveModel(string $event, array $values): void
{
    foreach ($values as $key => $value) {
        DB::table('settings')->updateOrInsert(['key' => 'badge_atelier_'.$event.'_'.$key], ['value' => $value]);
    }
}

// --- Soute P5 : synchronisation hors ligne du sens Entrée / Sortie ---------------------------------------------------------------------

/** Opération telle que la tablette de la soute l'enverrait après une coupure : contrôle jugé valide localement, sens choisi, âge de la copie déclaré. */
function s10SouteOp(int $seq, string $code, ?string $direction, array $point): array
{
    $payload = array_filter(['code' => $code, 'point' => $point, 'direction' => $direction, 'local_valid' => true, 'offline' => true, 'known_at' => now()->subMinute()->toIso8601String()], fn ($v) => $v !== null);

    return ['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => 'controle', 'agent_id' => test()->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => $payload];
}

function s10SouteSync(array $operations)
{
    return test()->postJson('/poste/api/sync', ['operations' => $operations], ['X-Device-Token' => S10_TOKEN, 'Accept' => 'application/json']);
}

it('rejoue à la soute les contrôles Entrée et Sortie faits hors ligne, avec le sens, les habilitations et sans effet sur la présence', function () {
    // tablette réellement enrôlée, habilitée à la soute ; délai hors ligne approuvé par l'institution (réglage existant)
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'soute']);
    DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->update(['value' => '12']);
    DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->update(['value' => '1']);
    expect(DB::table('devices')->where('id', $this->deviceId)->where('token_hash', hash('sha256', S10_TOKEN))->whereNull('revoked_at')->exists())->toBeTrue();
    s10Enter($this->visitorBadges[0], 1);
    s10Enter($this->visitorBadges[1], 4); // même catégorie que le badge (1 et 4 sont de la catégorie 1)
    $this->actingAs($this->agent);
    $presence = app(Presence::class);
    $present = $presence->count($this->event);
    $siteMovements = fn () => DB::table('access_events')->whereIn('type', ['entree', 'sortie'])->count();
    $siteBefore = $siteMovements();
    $statusBefore = DB::table('visitors')->whereIn('id', [s10Visitor(1), s10Visitor(4)])->orderBy('id')->pluck('status', 'id')->all();
    $zone1 = ['type' => 'zone', 'id' => DemoSeeder::id('zone1')];
    $zone2 = ['type' => 'zone', 'id' => DemoSeeder::id('zone2')];
    $code1 = $this->visitorBadges[0]->code;
    $knownControls = DB::table('access_events')->where('type', 'controle')->pluck('id')->all();

    $entree = s10SouteOp(1, $code1, 'entree', $zone1);
    $sortie = s10SouteOp(2, $code1, 'sortie', $zone1);
    $denied = s10SouteOp(3, $code1, 'entree', $zone2);
    $missing = s10SouteOp(4, $code1, null, $zone1);
    $invalid = s10SouteOp(5, $code1, 'nord', $zone1);
    $byId = collect(s10SouteSync([$entree, $sortie, $denied, $missing, $invalid])->assertOk()->json('results'))->keyBy('id');

    // (2)(3)(4) Entrée et Sortie appliquées, sens conservé dans le mouvement
    expect($byId[$entree['id']])->toMatchArray(['status' => 'applique', 'valid' => true, 'direction' => 'entree'])
        ->and($byId[$sortie['id']])->toMatchArray(['status' => 'applique', 'valid' => true, 'direction' => 'sortie']);
    $events = DB::table('access_events')->where('type', 'controle')->whereNotIn('id', $knownControls)->get();
    $directions = $events->map(fn ($e) => json_decode($e->details, true)['direction'] ?? null)->sort()->values()->all();
    expect($directions)->toBe(['entree', 'entree', 'sortie']);
    // (5) l'habilitation est appliquée : zone non autorisée = passage valide mais zone refusée, tracé avec son sens
    expect($byId[$denied['id']])->toMatchArray(['status' => 'applique', 'zone_ok' => false, 'direction' => 'entree']);
    // (7) sans sens, ou avec un sens invalide : refusé, mis en quarantaine, aucun mouvement écrit
    expect($byId[$missing['id']])->toMatchArray(['status' => 'conflit', 'code' => 'sens_requis'])
        ->and($byId[$invalid['id']])->toMatchArray(['status' => 'conflit', 'code' => 'sens_invalide']);
    expect(DB::table('sync_conflicts')->whereIn('operation_id', [$missing['id'], $invalid['id']])->count())->toBe(2);
    expect($events)->toHaveCount(3);
    // (6) la présence du site et le statut des personnes ne changent pas
    expect($presence->count($this->event))->toBe($present)->and($siteMovements())->toBe($siteBefore)
        ->and(DB::table('visitors')->whereIn('id', [s10Visitor(1), s10Visitor(4)])->orderBy('id')->pluck('status', 'id')->all())->toBe($statusBefore);

    // (8) rejeu du même lot : mêmes identifiants, aucun mouvement en double
    $count = DB::table('access_events')->where('type', 'controle')->count();
    $replay = collect(s10SouteSync([$entree, $sortie, $denied, $missing, $invalid])->assertOk()->json('results'))->keyBy('id');
    foreach ([$entree, $sortie, $denied, $missing, $invalid] as $op) {
        expect($replay[$op['id']]['replayed'] ?? false)->toBeTrue()->and($replay[$op['id']]['status'])->toBe($byId[$op['id']]['status']);
    }
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe($count)
        ->and(DB::table('sync_conflicts')->whereIn('operation_id', [$missing['id'], $invalid['id']])->count())->toBe(2);

    // (5) badge annulé entre-temps : jugé valide hors ligne, refusé par le serveur — refus tracé avec son sens, conflit signalé
    app(BadgeService::class)->annuler($this->visitorBadges[1], $this->sup, 'Perdu');
    $cancelled = s10SouteOp(6, $this->visitorBadges[1]->code, 'sortie', $zone1);
    s10SouteSync([$cancelled])->assertOk()->assertJsonPath('results.0.status', 'conflit')->assertJsonPath('results.0.code', 'annule_entre_temps');
    $refused = DB::table('access_events')->where('type', 'controle')->whereNotIn('id', [...$knownControls, ...$events->pluck('id')->all()])->get();
    expect($refused)->toHaveCount(1)->and(json_decode($refused[0]->details, true))->toMatchArray(['verdict' => 'refuse', 'direction' => 'sortie'])
        ->and($presence->count($this->event))->toBe($present);

    // le poste redevenu non habilité à la soute est refusé par la même règle qu'en ligne (S02), sans mouvement
    DB::table('devices')->where('id', $this->deviceId)->update(['point_code' => 'poste_1']);
    $count = DB::table('access_events')->where('type', 'controle')->count();
    $notAllowed = s10SouteOp(7, $code1, 'entree', $zone1);
    s10SouteSync([$notAllowed])->assertOk()->assertJsonPath('results.0.status', 'conflit')->assertJsonPath('results.0.code', 'poste_non_habilite');
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe($count);
});
