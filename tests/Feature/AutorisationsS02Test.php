<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\PostCapabilities;
use App\Services\Poste\SiteControl;
use App\Services\Poste\SyncProcessor;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
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

it('donne à l’administrateur la seule consultation de supervision, sans aucune action du superviseur', function () {
    $admin = s02User('administrateur');
    $event = DemoSeeder::id('event');
    $uuid = (string) Str::uuid();
    expect($admin->can('pilotage.consulter'))->toBeTrue()->and($admin->can('presences.consulter'))->toBeTrue();
    foreach (['alertes.consulter', 'badges.annuler', 'litiges.decider', 'groupes.suivre', 'cars.suivre', 'badges.controler', 'badges.associer', 'visiteurs.valider'] as $permission) {
        expect($admin->can($permission))->toBeFalse();
    }
    $this->actingAs($admin);
    foreach (['/pilotage', '/pilotage/presents', '/pilotage/evacuation', '/api/presences?event_id='.$event] as $url) {
        expect($this->get($url)->status())->not->toBeIn([401, 403]);
    }
    foreach ([['get', '/pilotage/alertes'], ['post', "/pilotage/alertes/$uuid/prendre-en-compte"], ['post', '/pilotage/badges/annuler'], ['get', '/pilotage/litiges'], ['post', "/pilotage/litiges/$uuid/decision"], ['get', '/pilotage/groupes']] as [$method, $url]) {
        $this->json($method, $url, [])->assertForbidden();
    }
    // le superviseur et l'agent gardent leur périmètre : l'agent ne consulte toujours pas la supervision.
    $this->actingAs(s02User('agent_accueil'));
    foreach (['/pilotage', '/pilotage/presents', '/pilotage/evacuation'] as $url) {
        $this->get($url)->assertForbidden();
    }
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
})->with(['poste_1', 'poste_3', 'poste_4']);

it('limite la soute au contrôle individuel et le poste 2 à l’ensemble des opérations d’agent', function () {
    s02Point('soute');
    $this->postJson('/poste/api/controle', ['code' => 'B-999999'], s02Headers())->assertOk();
    $this->postJson('/poste/api/sortie', ['code' => 'B-999999'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    $this->postJson('/poste/api/refuser', ['visitor_id' => DemoSeeder::id('visitor1'), 'motif' => 'essai'], s02Headers())->assertForbidden()->assertJsonPath('code', 'poste_non_habilite');
    s02Point('poste_2');
    $this->postJson('/poste/api/controle', ['code' => 'B-999999'], s02Headers())->assertOk();
    $this->postJson('/poste/api/sortie', ['code' => 'B-999999'], s02Headers())->assertOk();
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    foreach (['accueil', 'scan', 'sortie'] as $capability) {
        expect(PostCapabilities::allows($poste, $capability))->toBeTrue();
    }
    // poste non typé : comportement hérité inchangé
    s02Point(null);
    $this->postJson('/poste/api/controle', ['code' => 'B-999999'], s02Headers())->assertOk();
});

it('applique les mêmes limites de poste au rejeu hors ligne : mise en quarantaine, aucun mouvement enregistré', function () {
    s02Point('poste_1');
    $op = s02Op($this->agent, 1);
    $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('summary.conflicts', 1)->assertJsonPath('results.0.code', 'poste_non_habilite');
    expect(DB::table('sync_conflicts')->where('operation_id', $op['id'])->where('reason', 'poste_non_habilite')->count())->toBe(1)
        ->and(DB::table('access_events')->where('type', 'controle')->count())->toBe(0);
});

it('rejette un point de contrôle inconnu à la création d’un poste et l’enregistre sinon', function () {
    $admin = s02User('administrateur');
    $this->actingAs($admin);
    $event = DemoSeeder::id('event');
    $this->post('/administration/postes', ['event_id' => $event, 'name' => 'Tablette fictive S02', 'station' => 'Poste fictif', 'point_code' => 'poste_9'])->assertSessionHasErrors('point_code');
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

it('refuse un auteur inconnu, vide ou falsifié, sans rien écrire', function (?string $declared) {
    $op = s02Op($this->agent, 1, $declared ?? '');
    if ($declared === null) {
        unset($op['agent_id']);
    }
    $this->postJson('/poste/api/sync', ['operations' => [$op]], ['X-Device-Token' => S02_TOKEN, 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'auteur_incoherent');
    expect(DB::table('sync_operations')->where('id', $op['id'])->exists())->toBeFalse();
})->with([[null], [''], ['pas-un-uuid'], ['00000000-0000-4000-8000-000000000000']]);

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
    // ni un administrateur ni un superviseur ne passent par le poste : la clôture VIP n'a pas encore de chemin dédié (lot de clôture ultérieur)
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

it('réserve le réglage de la clôture réservée à la gestion des catégories', function () {
    $category = DemoSeeder::id('category1');
    $payload = ['event_id' => DemoSeeder::id('event'), 'name' => 'Catégorie fictive S02', 'color' => '#0F5AA6', 'priority_circuit' => false, 'closure_supervisor_only' => true, 'zone_ids' => []];
    foreach (['agent_accueil', 'superviseur', 'hote'] as $role) {
        $this->actingAs(s02User($role))->putJson("/administration/categories/{$category}", $payload)->assertForbidden();
    }
    expect((bool) DB::table('categories')->where('id', $category)->value('closure_supervisor_only'))->toBeFalse();
    $this->actingAs(s02User('administrateur'))->putJson("/administration/categories/{$category}", $payload)->assertRedirect();
    expect((bool) DB::table('categories')->where('id', $category)->value('closure_supervisor_only'))->toBeTrue();
});
