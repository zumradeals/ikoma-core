<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\Alerts;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Groups;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\Devices;
use App\Services\Poste\ListSnapshot;
use App\Services\Poste\PostCapabilities;
use App\Services\Poste\SiteControl;
use App\Services\Poste\SyncProcessor;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * S02 — autorisations par rôle et par poste, traçabilité de l'auteur, clôture réservée.
 * Données fictives uniquement. Les tests existants (badges, permanents, synchronisation) restent la référence de non-régression.
 */

const S02_TOKEN = '5555555555555555555555555555555555555555555555555555555555555555';

function s02User(string $role = 'agent_accueil'): User
{
    $user = User::create(['name' => 'Compte fictif S02', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function s02Headers(): array
{
    return ['X-Device-Token' => S02_TOKEN, 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
}

function s02Point(?string $point): void
{
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => $point]);
}

/** Opération telle que la tablette l'enverrait : un contrôle d'un code inconnu, sans effet métier autre que son journal. */
function s02Op(User $author, int $seq, ?string $agentId = null): array
{
    return ['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => 'controle', 'agent_id' => $agentId ?? $author->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => 'B-999999']];
}

/** Associe un badge de la catégorie du visiteur indiqué et l'enregistre comme entré (comme le poste d'accueil le ferait). */
function s02Enter(Badge $badge, int $visitorIndex): void
{
    test()->travel(1)->seconds();
    $visitor = DemoSeeder::id('visitor'.$visitorIndex);
    app(BadgeService::class)->associer($badge, $visitor, test()->agent, DemoSeeder::id('device'));
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => $visitor, 'badge_id' => $badge->id, 'actor_id' => test()->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('visitors')->where('id', $visitor)->update(['status' => 'present', 'updated_at' => now()]);
    test()->travel(1)->seconds();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->agent = s02User();
    $this->actingAs($this->agent);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', S02_TOKEN)]);
    $admin = s02User('administrateur');
    // catégorie 0 = « VIP » du jeu fictif, catégorie 1 = « Visiteur » ; chaque visiteur i du seed relève de la catégorie i.
    $this->vipBadges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category0'), 5, $admin)->id)->orderBy('code')->get();
    $this->badges = Badge::where('badge_batch_id', app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 5, $admin)->id)->orderBy('code')->get();
});

// --- Rôles : supervision consultée par l'administrateur, jamais agie ------------------------------------------------------------------

it('donne à l’administrateur la seule consultation de supervision, alertes comprises, sans aucune action du superviseur', function () {
    $admin = s02User('administrateur');
    $event = DemoSeeder::id('event');
    $uuid = (string) Str::uuid();
    foreach (['pilotage.consulter', 'presences.consulter', 'alertes.consulter'] as $permission) {
        expect($admin->can($permission))->toBeTrue();
    }
    foreach (['alertes.prendre_en_compte', 'cloture.superviseur', 'badges.annuler', 'litiges.decider', 'groupes.suivre', 'cars.suivre', 'badges.controler', 'badges.associer', 'visiteurs.valider'] as $permission) {
        expect($admin->can($permission))->toBeFalse();
    }
    // le superviseur garde les deux : consulter ET prendre en compte
    $supervisor = s02User('superviseur');
    expect($supervisor->can('alertes.consulter'))->toBeTrue()->and($supervisor->can('alertes.prendre_en_compte'))->toBeTrue();
    $this->actingAs($admin);
    foreach (['/pilotage', '/pilotage/alertes', '/pilotage/presents', '/pilotage/evacuation', '/api/presences?event_id='.$event] as $url) {
        expect($this->get($url)->status())->not->toBeIn([401, 403]);
    }
    foreach ([['post', "/pilotage/alertes/$uuid/prendre-en-compte"], ['post', '/pilotage/badges/annuler'], ['get', '/pilotage/litiges'], ['post', "/pilotage/litiges/$uuid/decision"], ['get', '/pilotage/groupes']] as [$method, $url]) {
        $this->json($method, $url, [])->assertForbidden();
    }
    // l'agent ne consulte toujours pas la supervision
    $this->actingAs(s02User('agent_accueil'));
    foreach (['/pilotage', '/pilotage/alertes', '/pilotage/presents', '/pilotage/evacuation'] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('réserve la prise en compte d’une alerte au superviseur, même par appel direct au service', function () {
    $event = DemoSeeder::id('event');
    app(Alerts::class)->raise($event, 'controle_refuse', 'haute', null, null, 'Alerte fictive S02', 's02-alerte');
    $alert = DB::table('alerts')->where('message', 'Alerte fictive S02')->value('id');
    expect($alert)->not->toBeNull();
    foreach (['administrateur', 'agent_accueil', 'hote'] as $role) {
        $user = s02User($role);
        expect(fn () => app(Alerts::class)->acknowledge($alert, $user))->toThrow(DeskRefused::class);
        $this->actingAs($user)->post("/pilotage/alertes/{$alert}/prendre-en-compte")->assertForbidden();
    }
    expect(DB::table('alerts')->where('id', $alert)->whereNotNull('acknowledged_at')->exists())->toBeFalse();
    $this->actingAs(s02User('superviseur'))->post("/pilotage/alertes/{$alert}/prendre-en-compte")->assertRedirect();
    expect(DB::table('alerts')->where('id', $alert)->whereNotNull('acknowledged_at')->exists())->toBeTrue();
});

it('ne fait pas d’un superviseur ou d’un administrateur un agent, par les routes comme par les services', function () {
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    foreach (['superviseur', 'administrateur', 'hote', 'encadreur'] as $role) {
        $user = s02User($role);
        $this->actingAs($user);
        $this->postJson('/poste/api/controle', ['code' => $this->badges[0]->code], s02Headers())->assertForbidden();
        $this->postJson('/poste/api/sortie', ['code' => $this->badges[0]->code], s02Headers())->assertForbidden();
        $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], s02Headers())->assertForbidden();
        $this->get('/poste')->assertForbidden();
        $this->get('/controle')->assertForbidden();
        // appel direct aux services : le rôle est revérifié, même si la route est contournée
        expect(fn () => app(SiteControl::class)->control($poste, $user, $this->badges[0]->code, null))->toThrow(DeskRefused::class)
            ->and(fn () => app(SiteControl::class)->exit($poste, $user, $this->badges[0]->code, null))->toThrow(DeskRefused::class)
            ->and(fn () => app(Desk::class)->refuse($poste, $user, DemoSeeder::id('visitor1'), 'essai'))->toThrow(DeskRefused::class);
    }
    expect(DB::table('access_events')->whereIn('type', ['controle', 'sortie', 'validation'])->count())->toBe(0);
});

it('réserve la clôture globale d’une activité à l’administrateur', function () {
    $event = DemoSeeder::id('event');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(s02User($role));
        $this->post("/administration/evenements/{$event}/cloturer", ['confirmation' => true])->assertForbidden();
        $this->post("/administration/evenements/{$event}/archiver", ['confirmation' => true])->assertForbidden();
    }
    expect(DB::table('events')->where('id', $event)->value('status'))->not->toBeIn(['clos', 'archive']);
    $this->actingAs(s02User('administrateur'));
    expect($this->post("/administration/evenements/{$event}/cloturer", ['confirmation' => true])->status())->not->toBe(403);
});

// --- Postes : un agent ne dispose que des capacités de son point de contrôle --------------------------------------------------------

it('refuse tout scan, toute sortie et toute opération d’accueil aux points sans capacité (poste 1, poste 3 et poste 4 en V1)', function (string $point) {
    s02Point($point);
    $code = $this->badges[0]->code;
    $this->postJson('/poste/api/controle', ['code' => $code], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/sortie', ['code' => $code], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    expect(DB::table('access_events')->count())->toBe(0)
        ->and(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('status'))->not->toBe('refuse');
})->with(['poste_1', 'poste_3', 'poste_4', 'hotel']);

it('limite la soute au contrôle individuel et le poste 2 à l’ensemble des opérations d’agent', function () {
    s02Point('soute');
    // S10 : à la soute le sens du passage est obligatoire avant le scan.
    $this->postJson('/poste/api/controle', ['code' => 'B-999999'], s02Headers())->assertStatus(422)->assertJsonPath('code', 'sens_requis');
    $this->postJson('/poste/api/controle', ['code' => 'B-999999', 'direction' => 'entree'], s02Headers())->assertOk();
    $this->postJson('/poste/api/sortie', ['code' => 'B-999999'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    s02Point('poste_2');
    $this->postJson('/poste/api/controle', ['code' => 'B-999999'], s02Headers())->assertOk();
    $this->postJson('/poste/api/sortie', ['code' => 'B-999999'], s02Headers())->assertOk();
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    foreach (['accueil', 'scan', 'sortie'] as $capability) {
        expect(PostCapabilities::allows($poste, $capability))->toBeTrue();
    }
});

it('applique les mêmes limites de poste au rejeu hors ligne : mise en quarantaine, aucun mouvement enregistré', function () {
    s02Point('poste_1');
    $op = s02Op($this->agent, 1);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('summary.conflicts', 1)->assertJsonPath('results.0.code', 'poste_non_habilite');
    expect(DB::table('sync_conflicts')->where('operation_id', $op['id'])->where('reason', 'poste_non_habilite')->count())->toBe(1)
        ->and(DB::table('access_events')->where('type', 'controle')->count())->toBe(0);
});

it('exige un point de contrôle identifié à la création d’un poste et rejette un point inconnu', function () {
    $admin = s02User('administrateur');
    $this->actingAs($admin);
    $event = DemoSeeder::id('event');
    $this->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02', 'station' => 'Poste fictif'])->assertSessionHasErrors('point_code');
    $this->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02', 'station' => 'Poste fictif', 'point_code' => ''])->assertSessionHasErrors('point_code');
    $this->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02', 'station' => 'Poste fictif', 'point_code' => 'poste_9'])->assertSessionHasErrors('point_code');
    expect(DB::table('devices')->where('name', 'Tablette fictive S02')->exists())->toBeFalse();
    $this->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02', 'station' => 'Poste fictif', 'point_code' => 'poste_2'])->assertRedirect();
    expect(DB::table('devices')->where('name', 'Tablette fictive S02')->value('point_code'))->toBe('poste_2');
    // un agent ne règle pas son propre poste
    $this->actingAs(s02User('agent_accueil'))->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02b', 'point_code' => 'poste_2'])->assertForbidden();
    expect(DB::table('devices')->where('name', 'Tablette fictive S02b')->exists())->toBeFalse();
});

// --- Auteur : jamais attribuable par le client ---------------------------------------------------------------------------------------

it('refuse en bloc des opérations attribuées à un autre compte, sans rien enregistrer ni faire avancer la séquence', function () {
    $other = s02User();
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    $seqBefore = (int) DB::table('devices')->where('id', DemoSeeder::id('device'))->value('sync_seq');
    $own = s02Op($this->agent, 1);
    $forged = s02Op($this->agent, 2, $other->id);
    $this->postJson('/poste/api/sync', ['operations' => [$own, $forged]], $headers)->assertForbidden()->assertJsonPath('code', 'auteur_incoherent');
    expect(DB::table('sync_operations')->whereIn('id', [$own['id'], $forged['id']])->count())->toBe(0)
        ->and(DB::table('access_events')->where('type', 'controle')->count())->toBe(0)
        ->and((int) DB::table('devices')->where('id', DemoSeeder::id('device'))->value('sync_seq'))->toBe($seqBefore)
        ->and(DB::table('devices')->where('id', DemoSeeder::id('device'))->whereNotNull('revoked_at')->exists())->toBeFalse();
    $line = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'sync.auteur_refuse')->first();
    expect($line)->not->toBeNull()->and($line->actor_id)->toBe($this->agent->id);
    expect(json_decode($line->details, true))->toMatchArray(['incoherentes' => 1, 'operations' => 2, 'auteurs_declares' => [$other->id]]);
    // les opérations légitimes du même lot passent ensuite, avec les mêmes séquences : rien n'a été consommé
    $this->postJson('/poste/api/sync', ['operations' => [$own]], $headers)->assertOk()->assertJsonPath('summary.applied', 1);
});

it('refuse un auteur falsifié ou inconnu, sans rien écrire', function (string $declared) {
    $op = s02Op($this->agent, 1, $declared);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'auteur_incoherent');
    expect(DB::table('sync_operations')->where('id', $op['id'])->exists())->toBeFalse();
})->with(['pas-un-uuid', '00000000-0000-4000-8000-000000000000']);

it('traite une opération sans auteur comme invalide, une à une, sans l’exécuter ni refuser les autres', function (bool $empty) {
    $anonymous = s02Op($this->agent, 1);
    $anonymous['agent_id'] = $empty ? '' : null;
    if (! $empty) {
        unset($anonymous['agent_id']);
    }
    $own = s02Op($this->agent, 2);
    $response = $this->postJson('/poste/api/sync', ['operations' => [$anonymous, $own]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertOk();
    expect($response->json('results.0.code'))->toBe('operation_invalide')->and($response->json('results.1.status'))->toBe('applique');
    expect(DB::table('sync_operations')->where('id', $anonymous['id'])->exists())->toBeFalse();
})->with([true, false]);

it('refuse l’usurpation même par appel direct au service, et laisse un second agent envoyer ses propres opérations (tablette partagée)', function () {
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $other = s02User();
    $result = app(SyncProcessor::class)->process($poste, $this->agent, [s02Op($this->agent, 1, $other->id)], null);
    expect($result)->toBe(['author_mismatch' => true]);
    // contexte explicite : chacun envoie ce qu'il a lui-même saisi, sous sa propre session
    $first = s02Op($this->agent, 1);
    $second = s02Op($other, 2);
    $this->postJson('/poste/api/sync', ['operations' => [$first]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertOk()->assertJsonPath('results.0.status', 'applique');
    $this->actingAs($other)->postJson('/poste/api/sync', ['operations' => [$second]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertOk()->assertJsonPath('results.0.status', 'applique');
    expect(DB::table('sync_operations')->where('id', $first['id'])->value('user_id'))->toBe($this->agent->id)
        ->and(DB::table('sync_operations')->where('id', $second['id'])->value('user_id'))->toBe($other->id);
});

it('garde l’idempotence : une opération légitime rejouée renvoie son résultat sans doublon', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    $op = s02Op($this->agent, 1);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.status', 'applique');
    $count = DB::table('access_events')->where('type', 'controle')->count();
    $this->postJson('/poste/api/sync', ['operations' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.replayed', true);
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe($count)->and(DB::table('sync_operations')->where('id', $op['id'])->count())->toBe(1);
});

it('refuse le rejeu d’un autre agent sur l’identifiant d’une opération déjà enregistrée', function () {
    $op = s02Op($this->agent, 1);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertOk();
    $other = s02User();
    $this->actingAs($other)->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'auteur_incoherent');
    expect(DB::table('sync_operations')->where('id', $op['id'])->value('user_id'))->toBe($this->agent->id);
});

// --- C1 : refus par défaut, point de contrôle obligatoire ------------------------------------------------------------------------------

it('refuse toute capacité à un poste non identifié (refus par défaut), côté serveur', function () {
    // Un appareil enrôlé sans point ne peut pas exister en base (contrainte) ; on vérifie donc la règle elle-même, qui protège aussi tout appel direct.
    $untyped = new Device(['event_id' => DemoSeeder::id('event'), 'point_code' => null]);
    expect(PostCapabilities::capabilities($untyped))->toBe([]);
    foreach (['accueil', 'scan', 'sortie'] as $capability) {
        expect(PostCapabilities::allows($untyped, $capability))->toBeFalse();
        expect(fn () => PostCapabilities::require($untyped, $this->agent, $capability))->toThrow(DeskRefused::class, 'Ce poste n’est pas identifié');
    }
    // aucune des opérations de poste ne s'exécute pour un poste non identifié, même avec le rôle d'agent
    expect(fn () => app(SiteControl::class)->control($untyped, $this->agent, 'B-999999', null))->toThrow(DeskRefused::class)
        ->and(fn () => app(SiteControl::class)->exit($untyped, $this->agent, 'B-999999', null))->toThrow(DeskRefused::class)
        ->and(fn () => app(Desk::class)->refuse($untyped, $this->agent, DemoSeeder::id('visitor1'), 'essai'))->toThrow(DeskRefused::class);
    expect(DB::table('access_events')->count())->toBe(0);
});

it('refuse l’enrôlement et l’émission d’un code pour un poste sans point, et interdit en base un appareil enrôlé sans point', function () {
    $admin = s02User('administrateur');
    $untyped = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $untyped, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette sans point', 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(Devices::class)->issueCode(Device::findOrFail($untyped), $admin))->toThrow(DeskRefused::class, 'Précisez d’abord le point');
    // même un code déjà émis (avant le typage) ne permet pas d'enrôler un poste sans point
    DB::table('devices')->where('id', $untyped)->update(['enrollment_hash' => Devices::hash('ABCD-EFGH-2345'), 'enrollment_expires_at' => now()->addMinutes(10)]);
    expect(fn () => app(Devices::class)->enrol('ABCD-EFGH-2345', $this->agent))->toThrow(DeskRefused::class, 'pas de point de contrôle identifié');
    expect(DB::table('devices')->where('id', $untyped)->whereNotNull('token_hash')->exists())->toBeFalse();
    // garantie de base : aucun jeton sans point, ni à l'insertion ni en retirant le point d'un poste enrôlé
    expect(fn () => DB::transaction(fn () => DB::table('devices')->where('id', $untyped)->update(['token_hash' => hash('sha256', 'x')])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['point_code' => null])))->toThrow(QueryException::class);
    expect(DB::table('devices')->where('id', DemoSeeder::id('device'))->value('point_code'))->toBe('poste_2');
    // un point hors liste est refusé en base
    expect(fn () => DB::transaction(fn () => DB::table('devices')->where('id', $untyped)->update(['point_code' => 'poste_9'])))->toThrow(QueryException::class);
});

it('enrôle un poste identifié et lui donne ses capacités dans la réponse du serveur', function () {
    $admin = s02User('administrateur');
    $device = app(Devices::class)->create(DemoSeeder::id('event'), 'Tablette fictive S02c', 'Poste fictif', $admin, 'soute');
    $code = app(Devices::class)->issueCode($device, $admin);
    $enrolled = app(Devices::class)->enrol($code, $this->agent);
    expect($enrolled['device_token'])->toMatch('/^[0-9a-f]{64}$/');
    expect(DB::table('devices')->where('id', $device->id)->value('point_code'))->toBe('soute');
});

// --- C2 : restrictions revérifiées à la copie locale et au rejeu --------------------------------------------------------------------

it('remet à la tablette son point de contrôle et ses capacités, et change de version quand le point change', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    foreach (['poste_1' => [], 'poste_2' => ['accueil', 'scan', 'sortie'], 'poste_3' => [], 'poste_4' => [], 'soute' => ['scan'], 'hotel' => []] as $point => $capabilities) {
        s02Point($point);
        $this->getJson('/poste/api/liste', $headers)->assertOk()->assertJsonPath('poste.point_code', $point)->assertJsonPath('poste.capabilities', $capabilities);
    }
    s02Point('poste_2');
    $before = app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device')));
    s02Point('soute');
    expect(app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device'))))->not->toBe($before);
});

/** Opération hors ligne telle que la tablette l'enverrait : décision déclarée hors ligne, avec l'âge de la copie utilisée. */
function s02Offline(User $agent, int $seq, string $type, array $payload, ?string $knownAt = null): array
{
    return ['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => $type, 'agent_id' => $agent->id, 'client_time' => now()->toIso8601String(), 'payload' => [...$payload, 'offline' => true, ...($knownAt === null ? [] : ['known_at' => $knownAt])]];
}

function s02Sync(array $operations)
{
    return test()->postJson('/poste/api/sync', ['operations' => $operations], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json']);
}

function s02Approve(int $hours, bool $approved): void
{
    DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->update(['value' => (string) $hours]);
    DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->update(['value' => $approved ? '1' : '0']);
}

it('refuse par défaut toute décision favorable hors ligne : délai non approuvé, nul ou absent ne signifie jamais « illimité »', function () {
    app(BadgeService::class)->associer($this->badges[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    $code = $this->badges[0]->code;
    $favorable = fn (int $seq) => [
        s02Offline($this->agent, $seq, 'controle', ['code' => $code, 'local_valid' => true], now()->subMinute()->toIso8601String()),
        s02Offline($this->agent, $seq + 1, 'valider', ['visitor_id' => DemoSeeder::id('visitor4')], now()->subMinute()->toIso8601String()),
        s02Offline($this->agent, $seq + 2, 'badge', ['visitor_id' => DemoSeeder::id('visitor4'), 'code' => $code], now()->subMinute()->toIso8601String()),
        s02Offline($this->agent, $seq + 3, 'entree', ['visitor_id' => DemoSeeder::id('visitor4'), 'duration_ms' => 1000], now()->subMinute()->toIso8601String()),
    ];
    // état de départ (migration) : délai de 12 h présent mais NON approuvé
    expect(PostCapabilities::offlineTrustHours())->toBe(0);
    $seq = 1;
    foreach ([[12, false], [0, true], [0, false]] as [$hours, $approved]) {
        s02Approve($hours, $approved);
        expect(PostCapabilities::offlineTrustHours())->toBe(0);
        $ops = $favorable($seq);
        $seq += 4;
        $codes = collect(s02Sync($ops)->assertOk()->json('results'))->pluck('code')->all();
        expect($codes)->toBe(array_fill(0, 4, 'politique_non_approuvee'));
    }
    // valeur absente : jamais « illimité »
    DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->delete();
    expect(PostCapabilities::offlineTrustHours())->toBe(0);
    DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->delete();
    expect(PostCapabilities::offlineTrustHours())->toBe(0);
    expect(DB::table('access_events')->whereIn('type', ['entree', 'validation'])->where('visitor_id', DemoSeeder::id('visitor4'))->count())->toBe(0);
});

it('n’accepte une décision favorable hors ligne que sous le délai approuvé et sur une copie assez récente', function () {
    app(BadgeService::class)->associer($this->badges[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    $code = $this->badges[0]->code;
    s02Approve(12, true);
    expect(PostCapabilities::offlineTrustHours())->toBe(12);
    $fresh = s02Offline($this->agent, 1, 'controle', ['code' => $code, 'local_valid' => true], now()->subHours(2)->toIso8601String());
    $stale = s02Offline($this->agent, 2, 'controle', ['code' => $code, 'local_valid' => true], now()->subHours(13)->toIso8601String());
    $unknown = s02Offline($this->agent, 3, 'controle', ['code' => $code, 'local_valid' => true]);
    $byId = collect(s02Sync([$fresh, $stale, $unknown])->assertOk()->json('results'))->keyBy('id');
    expect($byId[$fresh['id']]['status'])->toBe('applique')
        ->and($byId[$stale['id']])->toMatchArray(['status' => 'conflit', 'code' => 'politique_perimee'])
        ->and($byId[$unknown['id']])->toMatchArray(['status' => 'conflit', 'code' => 'politique_perimee']);
    expect(DB::table('sync_conflicts')->where('reason', 'politique_perimee')->count())->toBe(2);
    // un refus local n'accorde rien : il n'est jamais requalifié, même sur une copie ancienne et sans délai approuvé
    s02Approve(12, false);
    $refused = s02Offline($this->agent, 4, 'controle', ['code' => 'B-999999', 'local_valid' => false], now()->subHours(40)->toIso8601String());
    s02Sync([$refused])->assertOk()->assertJsonPath('results.0.status', 'applique');
    // une décision prise EN LIGNE (déclarée telle, avec l'âge de sa copie) est jugée par le serveur lui-même, sans délai
    $online = ['id' => (string) Str::uuid(), 'seq' => 5, 'type' => 'controle', 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => now()->subMinute()->toIso8601String()]];
    s02Sync([$online])->assertOk()->assertJsonPath('results.0.status', 'applique');
});

it('ne soumet ni la sortie ni le refus à la péremption : ils n’accordent aucun accès', function () {
    s02Approve(12, false); // le pire cas : aucun délai approuvé
    $ops = [
        s02Offline($this->agent, 1, 'sortie', ['code' => 'B-999999']),
        s02Offline($this->agent, 2, 'refuser', ['visitor_id' => DemoSeeder::id('visitor4'), 'motif' => 'essai']),
    ];
    $codes = collect(s02Sync($ops)->assertOk()->json('results'))->pluck('code')->filter()->all();
    expect($codes)->not->toContain('politique_non_approuvee')->and($codes)->not->toContain('politique_perimee');
    // la décision de refus a bien été prise
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor4'))->value('status'))->toBe('refuse');
});

/** Opération brute, avec heure déclarée de décision : pour éprouver ce que le serveur recoupe lui-même. */
function s02Raw(User $agent, int $seq, string $type, array $payload, ?string $clientTime): array
{
    $op = ['id' => (string) Str::uuid(), 'seq' => $seq, 'type' => $type, 'agent_id' => $agent->id, 'payload' => $payload];
    if ($clientTime !== null) {
        $op['client_time'] = $clientTime;
    }

    return $op;
}

it('ne laisse jamais le client prouver seul qu’il était en ligne : tablette coupée, délai non approuvé, opération déclarée « en ligne »', function () {
    app(BadgeService::class)->associer($this->badges[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    $code = $this->badges[0]->code;
    $visitor = DemoSeeder::id('visitor4');
    $threeHoursAgo = now()->subHours(3)->toIso8601String();
    // 1) la tablette a travaillé 3 h sans réseau puis envoie ses opérations en les déclarant « en ligne » : le serveur mesure lui-même le délai
    $forged = fn (int $seq) => [
        s02Raw($this->agent, $seq, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => $threeHoursAgo], $threeHoursAgo),
        s02Raw($this->agent, $seq + 1, 'valider', ['visitor_id' => $visitor, 'offline' => false, 'known_at' => $threeHoursAgo], $threeHoursAgo),
        s02Raw($this->agent, $seq + 2, 'badge', ['visitor_id' => $visitor, 'code' => $code, 'offline' => false, 'known_at' => $threeHoursAgo], $threeHoursAgo),
        s02Raw($this->agent, $seq + 3, 'entree', ['visitor_id' => $visitor, 'duration_ms' => 1000, 'offline' => false, 'known_at' => $threeHoursAgo], $threeHoursAgo),
    ];
    expect(PostCapabilities::offlineTrustHours())->toBe(0); // politique non approuvée
    $first = $forged(1);
    expect(collect(s02Sync($first)->assertOk()->json('results'))->pluck('code')->all())->toBe(array_fill(0, 4, 'declaration_en_ligne_contredite'));
    expect(DB::table('access_events')->where('visitor_id', $visitor)->count())->toBe(0);
    // l'audit garde la trace du motif exact, et un renvoi identique ne duplique ni conflit ni effet (idempotence)
    $conflicts = DB::table('sync_conflicts')->where('reason', 'declaration_en_ligne_contredite')->count();
    expect($conflicts)->toBe(4);
    $traces = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'sync.conflit')->get()->filter(fn ($line) => (json_decode($line->details, true)['raison'] ?? null) === 'declaration_en_ligne_contredite');
    expect($traces)->toHaveCount(4);
    $replay = s02Sync($first)->assertOk()->json('results');
    expect(collect($replay)->pluck('replayed')->filter()->count())->toBe(4)
        ->and(DB::table('sync_conflicts')->where('reason', 'declaration_en_ligne_contredite')->count())->toBe(4);
    // 2) délai approuvé mais copie trop ancienne à la décision, déclarée « en ligne » : refusée, toujours pour contradiction
    s02Approve(2, true);
    $old = s02Raw($this->agent, 5, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => now()->subHours(8)->toIso8601String()], $threeHoursAgo);
    s02Sync([$old])->assertOk()->assertJsonPath('results.0.code', 'declaration_en_ligne_contredite');
    // 3) décision réellement prise sur une copie récente avant la coupure : acceptée malgré la déclaration « en ligne » (le serveur la juge à la réception)
    s02Approve(12, true);
    $legit = s02Raw($this->agent, 6, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => now()->subHours(3)->subMinutes(30)->toIso8601String()], $threeHoursAgo);
    s02Sync([$legit])->assertOk()->assertJsonPath('results.0.status', 'applique');
});

it('traite comme hors ligne une opération sans heure déclarée, ou datée du futur, même déclarée « en ligne »', function () {
    app(BadgeService::class)->associer($this->badges[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    $code = $this->badges[0]->code;
    $known = now()->subMinute()->toIso8601String();
    $noTime = s02Raw($this->agent, 1, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => $known], null);
    $future = s02Raw($this->agent, 2, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => $known], now()->addHours(3)->toIso8601String());
    $byId = collect(s02Sync([$noTime, $future])->assertOk()->json('results'))->keyBy('id');
    expect($byId[$noTime['id']]['code'])->toBe('politique_non_approuvee')->and($byId[$future['id']]['code'])->toBe('declaration_en_ligne_contredite');
    expect(DB::table('access_events')->where('type', 'controle')->where('badge_id', $this->badges[0]->id)->count())->toBe(0);
});

it('documente la limite connue : un client qui falsifie AUSSI ses heures est jugé par le serveur sur l’état courant, sans preuve cryptographique', function () {
    app(BadgeService::class)->associer($this->badges[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    $code = $this->badges[0]->code;
    $now = now()->toIso8601String();
    // heures cohérentes et récentes : indistinguables d'une décision en ligne ; la règle ne prétend pas faire plus (voir la documentation)
    $op = s02Raw($this->agent, 1, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => $now], $now);
    s02Sync([$op])->assertOk()->assertJsonPath('results.0.status', 'applique');
    // mais le serveur rejuge toujours sur l'état courant : un badge annulé entre-temps est refusé quoi que le client déclare
    app(BadgeService::class)->annuler($this->badges[0], s02User('superviseur'), 'Perdu');
    $again = s02Raw($this->agent, 2, 'controle', ['code' => $code, 'local_valid' => true, 'offline' => false, 'known_at' => $now], $now);
    s02Sync([$again])->assertOk()->assertJsonPath('results.0.code', 'annule_entre_temps');
});

it('applique la même protection aux opérations de car qui décident : remise de badge, embarquement individuel et arrivée, et à elles seules', function () {
    DB::table('settings')->where('key', 'module_f06_active')->update(['value' => '1']);
    $group = DemoSeeder::id('group');
    $known = now()->subMinute()->toIso8601String();
    $badge = s02Offline($this->agent, 1, 'groupe_badge', ['group_id' => $group, 'visitor_id' => DemoSeeder::id('visitor270'), 'code' => $this->badges[0]->code], $known);
    $arrive = s02Offline($this->agent, 2, 'groupe_arrivee', ['code' => $this->badges[0]->code], $known);
    $board = s02Offline($this->agent, 6, 'groupe_embarquer', ['group_id' => $group, 'code' => $this->badges[0]->code], $known);
    $records = [
        s02Offline($this->agent, 3, 'groupe_controle', ['group_id' => $group, 'visitor_id' => DemoSeeder::id('visitor270'), 'identity_checked' => true], $known),
        s02Offline($this->agent, 4, 'groupe_depart', ['group_id' => $group], $known),
        s02Offline($this->agent, 5, 'groupe_arrivee_fin', ['group_id' => $group], $known),
        s02Offline($this->agent, 7, 'groupe_absent', ['group_id' => $group, 'visitor_id' => DemoSeeder::id('visitor270'), 'reason' => 'Malade'], $known),
    ];
    $byId = collect(s02Sync([$badge, $arrive, $board, ...$records])->assertOk()->json('results'))->keyBy('id');
    // décisions d'accès : refusées hors ligne tant qu'aucun délai n'est approuvé
    expect($byId[$badge['id']]['code'])->toBe('politique_non_approuvee')->and($byId[$arrive['id']]['code'])->toBe('politique_non_approuvee')->and($byId[$board['id']]['code'])->toBe('politique_non_approuvee');
    // enregistrements sans décision d'accès : jamais refusés par la politique hors ligne
    foreach ($records as $record) {
        expect($byId[$record['id']]['code'] ?? null)->not->toBeIn(['politique_non_approuvee', 'politique_perimee']);
    }
});

it('distingue l’hôtel des postes de l’Académie : préparation et badges par permission de rôle, arrivée par scan à un poste', function () {
    DB::table('settings')->where('key', 'module_f06_active')->update(['value' => '1']);
    $group = DemoSeeder::id('group');
    $codeOf = function (callable $call): ?string {
        try {
            $call();
        } catch (DeskRefused $e) {
            return $e->code_;
        }

        return null;
    };
    // tablette de l'hôtel : identifiée comme telle (jamais « poste 2 »), aucune capacité de poste
    s02Point('hotel');
    $hotel = Device::findOrFail(DemoSeeder::id('device'));
    expect(PostCapabilities::capabilities($hotel))->toBe([]);
    // la remise de badge de groupe (hôtel) relève de la permission de rôle groupes.remettre_badges : aucun refus de capacité de poste
    expect($codeOf(fn () => app(Groups::class)->deliver($hotel, $this->agent, $group, DemoSeeder::id('visitor270'), $this->badges[0]->code)))->not->toBe('poste_non_habilite');
    // l'arrivée d'un car est un scan physique à un poste de l'Académie : refusée à l'hôtel, comme à tout point sans capacité de scan
    foreach (['hotel', 'poste_1', 'poste_3', 'poste_4'] as $point) {
        s02Point($point);
        expect($codeOf(fn () => app(Groups::class)->arrive(Device::findOrFail(DemoSeeder::id('device')), $this->agent, $this->badges[0]->code)))->toBe('poste_non_habilite');
    }
    foreach (['poste_2', 'soute'] as $point) {
        s02Point($point);
        expect($codeOf(fn () => app(Groups::class)->arrive(Device::findOrFail(DemoSeeder::id('device')), $this->agent, $this->badges[0]->code)))->not->toBe('poste_non_habilite');
    }
    // une tablette de l'hôtel ne contrôle, ne fait sortir et n'accueille personne
    s02Point('hotel');
    $this->postJson('/poste/api/controle', ['code' => $this->badges[0]->code], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/sortie', ['code' => $this->badges[0]->code], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    // rejeu : la remise de badge de groupe faite à l'hôtel n'est pas refusée pour capacité ; l'arrivée du car l'est
    $known = now()->subMinute()->toIso8601String();
    $ops = [
        ['id' => (string) Str::uuid(), 'seq' => 1, 'type' => 'groupe_badge', 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['group_id' => $group, 'visitor_id' => DemoSeeder::id('visitor270'), 'code' => $this->badges[0]->code, 'offline' => false, 'known_at' => $known]],
        ['id' => (string) Str::uuid(), 'seq' => 2, 'type' => 'groupe_arrivee', 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => $this->badges[0]->code, 'offline' => false, 'known_at' => $known]],
    ];
    $codes = collect(s02Sync($ops)->assertOk()->json('results'))->pluck('code')->all();
    expect($codes[0] ?? null)->not->toBe('poste_non_habilite')->and($codes[1])->toBe('poste_non_habilite');
    // l'hôtel se déclare à la création d'un poste, sans fausse identité d'un poste de l'Académie
    $this->actingAs(s02User('administrateur'))->post('/administration/postes', ['event_id' => DemoSeeder::id('event'), 'name' => 'Tablette hôtel fictive', 'point_code' => 'hotel'])->assertRedirect();
    expect(DB::table('devices')->where('name', 'Tablette hôtel fictive')->value('point_code'))->toBe('hotel');
});

it('revérifie au rejeu le point courant du poste, même si la copie locale était plus ancienne', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    // la tablette a décidé quand le poste était un poste 2 ; l'administrateur l'a ensuite changé en poste 1
    $op = s02Op($this->agent, 1);
    $op['payload']['local_valid'] = true;
    $op['payload']['offline'] = false;
    $op['payload']['known_at'] = now()->subMinutes(10)->toIso8601String();
    s02Point('poste_1');
    $this->postJson('/poste/api/sync', ['operations' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.code', 'poste_non_habilite');
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe(0);
});

it('exige un délai strictement positif pour approuver le hors ligne, et retire l’approbation quand le délai change', function () {
    $base = ['inactivity' => 5, 'skew' => 300, 'pin' => 15];
    $this->actingAs(s02User('administrateur'));
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 0, 'offlineApproved' => true])->assertSessionHasErrors('offlineApproved');
    expect(PostCapabilities::offlineTrustHours())->toBe(0);
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 8, 'offlineApproved' => true])->assertRedirect();
    expect(PostCapabilities::offlineTrustHours())->toBe(8);
    // changer le délai sans redonner l'approbation la retire : on n'approuve pas un autre délai que celui vu
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 10])->assertRedirect();
    expect(DB::table('settings')->where('key', 'poste_hors_reseau_approuve')->value('value'))->toBe('0')->and(PostCapabilities::offlineTrustHours())->toBe(0);
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 10, 'offlineApproved' => true])->assertRedirect();
    expect(PostCapabilities::offlineTrustHours())->toBe(10);
    // même délai, formulaire sans la case : l'approbation existante est conservée
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 10])->assertRedirect();
    expect(PostCapabilities::offlineTrustHours())->toBe(10);
    // retirer l'approbation est possible à tout moment ; un délai nul ne peut jamais rester approuvé
    $this->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 10, 'offlineApproved' => false])->assertRedirect();
    expect(PostCapabilities::offlineTrustHours())->toBe(0);
    $line = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'poste.reglage_modifie')->orderByDesc('id')->first();
    expect(json_decode($line->details, true))->toHaveKey('hors_ligne_approuve');
    // un agent ne règle rien
    $this->actingAs(s02User('agent_accueil'))->put('/administration/postes/reglages', [...$base, 'offlineMaxHours' => 99, 'offlineApproved' => true])->assertForbidden();
    expect(DB::table('settings')->where('key', 'poste_hors_reseau_max_heures')->value('value'))->toBe('10');
});

it('remet à la tablette le délai hors ligne effectif et change de version quand l’approbation change', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    $this->getJson('/poste/api/liste', $headers)->assertOk()->assertJsonPath('settings.offline_trust_hours', 0);
    $before = app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device')));
    s02Approve(12, true);
    $this->getJson('/poste/api/liste', $headers)->assertOk()->assertJsonPath('settings.offline_trust_hours', 12);
    expect(app(ListSnapshot::class)->version(Device::findOrFail(DemoSeeder::id('device'))))->not->toBe($before);
    s02Approve(0, true);
    $this->getJson('/poste/api/liste', $headers)->assertOk()->assertJsonPath('settings.offline_trust_hours', 0);
});

// --- Clôture VIP : cas bloqués pour l'agent du poste (le parcours du superviseur est testé dans S09) ----------------------------

it('documente par un test les cas bloqués par la clôture réservée : sortie par le poste, rejeu hors ligne et clôture de l’activité', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    $badge = $this->vipBadges[0];
    s02Enter($badge, 0);
    // 1) l'agent ne peut pas clôturer : la personne reste présente, le badge reste associé
    $this->postJson('/poste/api/sortie', ['code' => $badge->code], s02Headers())->assertOk()->assertJsonPath('reason', 'cloture_reservee_superviseur');
    // 2) un rejeu hors ligne de cette sortie est mis en quarantaine : un conflit ouvert reste à résoudre
    $op = s02Offline($this->agent, 1, 'sortie', ['code' => $badge->code]);
    s02Sync([$op])->assertOk()->assertJsonPath('results.0.code', 'cloture_reservee_superviseur');
    expect(DB::table('sync_conflicts')->where('operation_id', $op['id'])->where('reason', 'cloture_reservee_superviseur')->count())->toBe(1);
    // 3) ni l'administrateur ni le superviseur n'enregistrent une sortie au poste (le superviseur clôture par son parcours dédié)
    foreach (['administrateur', 'superviseur'] as $role) {
        $this->actingAs(s02User($role))->postJson('/poste/api/sortie', ['code' => $badge->code], s02Headers())->assertForbidden();
    }
    // 4) conséquence : l'activité ne peut pas être clôturée (personne présente, attribution ouverte, conflit non résolu)
    $this->actingAs(s02User('administrateur'))->post('/administration/evenements/'.DemoSeeder::id('event').'/cloturer', ['confirmation' => true])->assertSessionHasErrors('operation');
    $message = (string) session('errors')->first('operation');
    expect($message)->toContain('encore présentes')->and($message)->toContain('restent à résoudre');
    expect(DB::table('events')->where('id', DemoSeeder::id('event'))->value('status'))->not->toBeIn(['clos', 'archive'])
        ->and(DB::table('visitors')->where('id', DemoSeeder::id('visitor0'))->value('status'))->toBe('present');
});

// --- C3 : l'auteur initial ne change jamais en silence -----------------------------------------------------------------------------

it('refuse qu’un autre agent renvoie sous son nom une opération déjà enregistrée par un autre, sans changer son auteur', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    $op = s02Op($this->agent, 1);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.status', 'applique');
    $other = s02User();
    // le second agent réécrit l'auteur à son propre nom pour passer le contrôle de session : l'identifiant d'opération est déjà pris par une autre action
    $rewritten = [...$op, 'agent_id' => $other->id];
    $this->actingAs($other)->postJson('/poste/api/sync', ['operations' => [$rewritten]], $headers)->assertOk()->assertJsonPath('results.0.code', 'operation_conflit');
    expect(DB::table('sync_operations')->where('id', $op['id'])->value('user_id'))->toBe($this->agent->id)
        ->and(DB::table('sync_operations')->where('id', $op['id'])->count())->toBe(1);
    // le renvoi légitime par l'auteur initial rejoue le résultat sans rien refaire
    $count = DB::table('access_events')->where('type', 'controle')->count();
    $this->actingAs($this->agent)->postJson('/poste/api/sync', ['operations' => [$op]], $headers)->assertOk()->assertJsonPath('results.0.replayed', true);
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe($count);
});

it('ne perd aucune opération quand un refus d’auteur survient : le lot est rejouable tel quel sous la bonne session', function () {
    $headers = ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'];
    $mine = [s02Op($this->agent, 1), s02Op($this->agent, 2)];
    $other = s02User();
    $this->actingAs($other)->postJson('/poste/api/sync', ['operations' => $mine], $headers)->assertForbidden()->assertJsonPath('code', 'auteur_incoherent');
    expect(DB::table('sync_operations')->whereIn('id', array_column($mine, 'id'))->count())->toBe(0);
    $this->actingAs($this->agent)->postJson('/poste/api/sync', ['operations' => $mine], $headers)->assertOk()->assertJsonPath('summary.applied', 2);
    expect(DB::table('sync_operations')->whereIn('id', array_column($mine, 'id'))->count())->toBe(2);
});

// --- Clôture VIP : indicateur explicite, jamais le libellé --------------------------------------------------------------------------

it('interdit à l’agent la sortie et la restitution d’une catégorie à clôture réservée, sans rien restituer', function () {
    DB::table('categories')->where('id', DemoSeeder::id('category0'))->update(['closure_supervisor_only' => true]);
    $badge = $this->vipBadges[0];
    s02Enter($badge, 0);
    $out = $this->postJson('/poste/api/sortie', ['code' => $badge->code], s02Headers())->assertOk()->json();
    expect($out)->toMatchArray(['ok' => false, 'refus' => true, 'reason' => 'cloture_reservee_superviseur']);
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor0'))->value('status'))->toBe('present')
        ->and(DB::table('badges')->where('id', $badge->id)->value('status'))->toBe('Associé')
        ->and(DB::table('access_events')->where('type', 'sortie')->count())->toBe(0)
        ->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNotNull('returned_at')->count())->toBe(0);
    $line = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'sortie.refusee')->first();
    expect(json_decode($line->details, true)['raison'])->toBe('cloture_reservee_superviseur');
    // ni un administrateur ni un superviseur ne passent par le poste : la clôture VIP a son propre parcours (SupervisorClosure, S09)
    foreach (['administrateur', 'superviseur'] as $role) {
        $this->actingAs(s02User($role))->postJson('/poste/api/sortie', ['code' => $badge->code], s02Headers())->assertForbidden();
    }
});

it('n’accorde ni ne retire aucun droit sur la seule base du libellé d’une catégorie', function () {
    // « VIP » est le libellé de la catégorie 0 : sans indicateur, la sortie suit la règle ordinaire
    expect(DB::table('categories')->where('id', DemoSeeder::id('category0'))->value('name'))->toBe('VIP');
    s02Enter($this->vipBadges[0], 0);
    $this->postJson('/poste/api/sortie', ['code' => $this->vipBadges[0]->code], s02Headers())->assertOk()->assertJsonPath('ok', true);
    // « Visiteur » est un libellé ordinaire : avec l'indicateur, la sortie est réservée
    DB::table('categories')->where('id', DemoSeeder::id('category1'))->update(['closure_supervisor_only' => true]);
    s02Enter($this->badges[0], 1);
    $this->postJson('/poste/api/sortie', ['code' => $this->badges[0]->code], s02Headers())->assertOk()->assertJsonPath('reason', 'cloture_reservee_superviseur');
});

it('laisse la sortie ordinaire inchangée pour une catégorie sans indicateur', function () {
    s02Enter($this->badges[0], 1);
    $out = $this->postJson('/poste/api/sortie', ['code' => $this->badges[0]->code], s02Headers())->assertOk()->json();
    expect($out['ok'])->toBeTrue();
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('status'))->toBe('sorti');
});

it('permet à l’administration d’activer et de retirer la clôture réservée maintenant que le parcours du superviseur existe', function () {
    $category = DemoSeeder::id('category1');
    $payload = ['event_id' => DemoSeeder::id('event'), 'name' => 'Catégorie fictive S02', 'color' => '#0F5AA6', 'priority_circuit' => false, 'closure_supervisor_only' => true, 'zone_ids' => []];
    $this->actingAs(s02User('administrateur'));
    $this->putJson("/administration/categories/{$category}", $payload)->assertRedirect();
    expect((bool) DB::table('categories')->where('id', $category)->value('closure_supervisor_only'))->toBeTrue();
    $this->putJson("/administration/categories/{$category}", [...$payload, 'closure_supervisor_only' => false])->assertRedirect();
    expect((bool) DB::table('categories')->where('id', $category)->value('closure_supervisor_only'))->toBeFalse();
    $this->postJson('/administration/categories', [...$payload, 'name' => 'Catégorie VIP fictive'])->assertRedirect();
    expect((bool) DB::table('categories')->where('name', 'Catégorie VIP fictive')->value('closure_supervisor_only'))->toBeTrue();
    // les autres rôles n'y ont aucun accès
    foreach (['agent_accueil', 'superviseur', 'hote'] as $role) {
        $this->actingAs(s02User($role))->putJson("/administration/categories/{$category}", $payload)->assertForbidden();
    }
});
