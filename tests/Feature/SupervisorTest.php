<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge;
use App\Models\User;
use App\Services\Alerts;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Pilotage;
use App\Services\Presence;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

const SUP_TOKEN = '4444444444444444444444444444444444444444444444444444444444444444';

function supUser(string $role = 'superviseur'): User
{
    $user = User::create(['name' => 'Compte fictif L8', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function supAudit(): Builder
{
    return DB::table('audit_logs')->where('id', '>', test()->auditStart);
}

function supVisitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

/** Associe un badge à un visiteur du seed et l'enregistre comme entré. */
function supEnter(int $i, Badge $badge, ?int $second = null): string
{
    app(BadgeService::class)->associer($badge, supVisitor($i), test()->agent, DemoSeeder::id('device'));
    $id = (string) Str::uuid();
    $at = now()->addSeconds($second ?? 0);
    DB::table('access_events')->insert(['id' => $id, 'event_id' => DemoSeeder::id('event'), 'visitor_id' => supVisitor($i), 'badge_id' => $badge->id, 'actor_id' => test()->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
    DB::table('visitors')->where('id', supVisitor($i))->update(['status' => 'present', 'updated_at' => now()]);

    return $id;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->agent = supUser('agent_accueil');
    $this->supervisor = supUser();
    $this->admin = supUser('administrateur');
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', SUP_TOKEN)]);
    $lots = app(BadgeLots::class);
    foreach ([0, 1, 2] as $c) {
        $this->{'batch'.$c} = Badge::where('badge_batch_id', $lots->generate(DemoSeeder::id('event'), DemoSeeder::id('category'.$c), 15, $this->admin)->id)->orderBy('code')->get();
    }
    $this->event = DemoSeeder::id('event');
    $this->actingAs($this->supervisor);
});

function supAs(User $user)
{
    return test()->actingAs($user);
}

it('refuse chaque écran et chaque action du pilotage à tout autre rôle, et aux visiteurs non connectés', function () {
    $uuid = (string) Str::uuid();
    $routes = [
        ['get', '/pilotage'], ['get', '/pilotage/alertes'], ['post', "/pilotage/alertes/$uuid/prendre-en-compte"], ['get', '/pilotage/presents'], ['get', '/pilotage/visiteurs/'.supVisitor(1)],
        ['post', '/pilotage/badges/annuler'], ['get', '/pilotage/litiges'], ['post', "/pilotage/litiges/$uuid/decision"], ['get', '/pilotage/groupes'], ['get', '/pilotage/evacuation'], ['get', '/pilotage/api/evacuation'], ['get', '/api/presences?event_id='.$this->event],
    ];
    foreach (['administrateur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        supAs(supUser($role));
        foreach ($routes as [$method, $url]) {
            test()->json($method, $url, [])->assertForbidden();
        }
    }
    auth()->logout();
    foreach ($routes as [$method, $url]) {
        expect(test()->json($method, $url, [])->status())->toBeIn([401, 302]);
    }
    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->whereIn('action', ['alerte.prise_en_compte', 'litige.decide', 'badge.annule', 'evacuation.consultee'])->count())->toBe(0);
    // le superviseur n'a ni droit d'association de badge, ni gestion de comptes, ni accès au poste
    supAs($this->supervisor);
    $this->postJson('/poste/api/badge', ['visitor_id' => supVisitor(1), 'code' => $this->batch1[0]->code], ['X-Device-Token' => SUP_TOKEN, 'X-Operation-Id' => (string) Str::uuid()])->assertForbidden();
    $this->get('/poste')->assertForbidden();
    $this->get('/administration/comptes')->assertForbidden();
});

it('donne au superviseur des compteurs exacts, mis à jour par rechargement partiel', function () {
    supEnter(1, $this->batch1[0]);
    supEnter(2, $this->batch2[0], 1);
    supEnter(3, $this->batch0[0], 2);
    DB::table('visitors')->where('id', supVisitor(4))->update(['status' => 'refuse']);
    $total = DB::table('visitors')->where('event_id', $this->event)->count();
    $this->get('/pilotage')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Index')
        ->where('counters.attendus', $total - 1)->where('counters.arrives', 3)->where('counters.presents', 3)->where('counters.refuses', 1)->where('event.id', $this->event));
    // rechargement partiel : seuls les compteurs et les alertes sont calculés
    $partial = $this->get('/pilotage', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Pilotage/Index', 'X-Inertia-Partial-Data' => 'counters,alerts', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''])->assertOk()->json();
    expect(array_keys($partial['props']))->toContain('counters')->not->toContain('events');
});

it('tient les compteurs exacts sur 200 mouvements, entrées, sorties et corrections comprises', function () {
    $presence = app(Presence::class);
    $visitors = DB::table('visitors')->where('event_id', $this->event)->orderBy('id')->limit(30)->pluck('id')->all();
    $stack = array_fill_keys($visitors, []);
    $second = 0;
    mt_srand(11);
    $arrived = [];
    for ($moves = 0; $moves < 200; $moves++) {
        $v = $visitors[mt_rand(0, 29)];
        if (mt_rand(1, 10) <= 2 && $stack[$v] !== []) {
            $last = array_pop($stack[$v]);
            $presence->corriger($last['id'], $this->agent, 'Erreur fictive');

            continue;
        }
        $type = ($stack[$v] !== [] && end($stack[$v])['type'] === 'entree') ? 'sortie' : 'entree';
        $id = (string) Str::uuid();
        DB::table('access_events')->insert(['id' => $id, 'event_id' => $this->event, 'visitor_id' => $v, 'badge_id' => null, 'actor_id' => $this->agent->id, 'type' => $type, 'checkpoint' => 'Poste fictif', 'occurred_at' => now()->addSeconds(++$second), 'created_at' => now(), 'updated_at' => now()]);
        $stack[$v][] = ['id' => $id, 'type' => $type];
    }
    $expectedPresent = count(array_filter($stack, fn ($s) => $s !== [] && end($s)['type'] === 'entree'));
    $expectedArrived = count(array_filter($stack, fn ($s) => in_array('entree', array_column($s, 'type'), true)));
    $counters = app(Pilotage::class)->counters($this->event);
    expect($counters['presents'])->toBe($expectedPresent)->and($counters['arrives'])->toBe($expectedArrived);
    expect(app(Pilotage::class)->presentCount($this->event))->toBe($expectedPresent)->and(count(app(Pilotage::class)->presentRows($this->event)))->toBe($expectedPresent);
});

it('crée les alertes depuis chaque source, sans aucun nom, les trie par gravité puis par date et les dédoublonne', function () {
    $headers = ['X-Device-Token' => SUP_TOKEN, 'Accept' => 'application/json'];
    supAs($this->agent);
    // refus d'accès
    $this->postJson('/poste/api/refuser', ['visitor_id' => supVisitor(5), 'motif' => 'Pièce illisible'], [...$headers, 'X-Operation-Id' => (string) Str::uuid()])->assertOk();
    // non listé en attente de l'hôte
    $this->postJson('/poste/api/non-listes', ['name' => 'Imprévu Fictif', 'organisation' => 'Organisation fictive', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1')], [...$headers, 'X-Operation-Id' => (string) Str::uuid()])->assertOk();
    // badge annulé d'une personne présente
    supEnter(1, $this->batch1[0]);
    app(BadgeService::class)->annuler($this->batch1[0], $this->supervisor, 'Perdu');
    // conflit de synchronisation
    $this->postJson('/poste/api/sync', ['sent_at' => now()->toIso8601String(), 'operations' => [['id' => (string) Str::uuid(), 'seq' => 1, 'type' => 'sortie', 'agent_id' => $this->agent->id, 'client_time' => now()->toIso8601String(), 'payload' => ['code' => $this->batch2[0]->code]]]], $headers)->assertOk();
    // visiteur validé hors période sur avis du superviseur
    DB::table('visitors')->where('id', supVisitor(6))->update(['valid_until' => '2027-01-10 09:00:00+00']);
    DB::table('identity_checks')->insert(['id' => (string) Str::uuid(), 'visitor_id' => supVisitor(6), 'actor_id' => $this->agent->id, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('photos')->insert(['id' => (string) Str::uuid(), 'visitor_id' => supVisitor(6), 'file_reference' => 'photos/x.enc', 'taken_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/poste/api/valider', ['visitor_id' => supVisitor(6), 'avis' => 'superviseur'], [...$headers, 'X-Operation-Id' => (string) Str::uuid()])->assertOk();
    $types = DB::table('alerts')->orderBy('created_at')->pluck('type')->all();
    expect($types)->toContain('refus')->toContain('non_liste_attente')->toContain('badge_annule')->toContain('conflit_sync')->toContain('hors_periode');
    $alerts = app(Pilotage::class)->alerts($this->event, 'a_traiter');
    expect(collect($alerts)->pluck('severity')->all())->toBe(collect($alerts)->pluck('severity')->sortBy(fn ($s) => Alerts::SEVERITIES[$s])->values()->all());
    // aucun nom, ni dans les alertes, ni dans le journal
    $names = DB::table('visitors')->where('event_id', $this->event)->pluck('full_name')->push('Imprévu Fictif')->all();
    $blob = DB::table('alerts')->get()->map(fn ($a) => json_encode($a))->implode(' ').DB::table('audit_logs')->where('id', '>', $this->auditStart)->get()->map(fn ($l) => $l->details)->implode(' ');
    foreach ($names as $name) {
        expect($blob)->not->toContain($name);
    }
    // les alertes ne se doublent pas (opération rejouée, nouveau déclenchement)
    $count = DB::table('alerts')->count();
    app(Alerts::class)->raise($this->event, 'refus', 'haute', 'visiteur', supVisitor(5), 'Doublon', 'refus:'.DB::table('refusals')->value('id'));
    expect(DB::table('alerts')->count())->toBe($count);
    // chaque alerte renvoie vers l'élément concerné
    expect(collect($alerts)->firstWhere('type', 'badge_annule')['link'])->toBe('/pilotage/visiteurs/'.supVisitor(1))->and(collect($alerts)->firstWhere('type', 'refus')['link'])->toBe('/pilotage/litiges');
});

it('prend une alerte en compte une seule fois, la journalise, et la retire des alertes à traiter', function () {
    app(Alerts::class)->raise($this->event, 'hors_periode', 'normale', 'visiteur', supVisitor(2), 'Visiteur validé hors de sa période.', 'k1');
    app(Alerts::class)->raise($this->event, 'refus', 'critique', 'visiteur', supVisitor(3), 'Accès refusé.', 'k2');
    $first = DB::table('alerts')->where('dedupe_key', 'k2')->value('id');
    $this->get('/pilotage/alertes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Alertes')->has('alerts', 2)->where('alerts.0.severity', 'critique')->where('state', 'a_traiter'));
    $this->post("/pilotage/alertes/$first/prendre-en-compte")->assertRedirect();
    $this->post("/pilotage/alertes/$first/prendre-en-compte")->assertRedirect(); // double appui : sans effet
    expect(supAudit()->where('action', 'alerte.prise_en_compte')->count())->toBe(1);
    $row = DB::table('alerts')->where('id', $first)->first();
    expect($row->acknowledged_by)->toBe($this->supervisor->id)->and($row->acknowledged_at)->not->toBeNull();
    $this->get('/pilotage/alertes')->assertInertia(fn (AssertableInertia $p) => $p->has('alerts', 1));
    $this->get('/pilotage/alertes?etat=prises')->assertInertia(fn (AssertableInertia $p) => $p->has('alerts', 1)->where('state', 'prises'));
    expect(json_decode(supAudit()->where('action', 'alerte.prise_en_compte')->value('details'), true))->toMatchArray(['alert_id' => $first, 'type' => 'refus', 'severity' => 'critique'])->not->toHaveKey('message');
});

it('annule un badge seulement avec un motif et une confirmation, renvoie vers l’accueil et laisse la personne « à rebadger »', function () {
    supEnter(1, $this->batch1[0]);
    $payload = ['visitor_id' => supVisitor(1), 'motif' => '', 'confirmation' => true];
    $this->postJson('/pilotage/badges/annuler', $payload)->assertStatus(422)->assertJson(['code' => 'motif_obligatoire']);
    $this->postJson('/pilotage/badges/annuler', [...$payload, 'motif' => 'Perdu', 'confirmation' => false])->assertStatus(422)->assertJson(['code' => 'confirmation_requise']);
    $this->postJson('/pilotage/badges/annuler', ['motif' => 'Perdu', 'confirmation' => true])->assertStatus(422)->assertJson(['code' => 'badge_requis']);
    expect(DB::table('badges')->where('id', $this->batch1[0]->id)->value('status'))->toBe('Associé');
    $this->postJson('/pilotage/badges/annuler', [...$payload, 'motif' => 'Perdu par le visiteur'])->assertOk();
    expect(DB::table('badges')->where('id', $this->batch1[0]->id)->value('status'))->toBe('Annulé');
    expect(supAudit()->where('action', 'badge.annule')->count())->toBe(1);
    $alert = DB::table('alerts')->where('type', 'badge_annule')->first();
    expect($alert->subject_id)->toBe(supVisitor(1))->and($alert->severity)->toBe('haute')->and($alert->message)->toContain('rebadger');
    $this->get('/pilotage/visiteurs/'.supVisitor(1))->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Visiteur')->where('badge', null)->where('toRebadge.code', $this->batch1[0]->code)->where('permissions.annuler', true));
    // la fiche du poste d'accueil l'indique aussi ; l'agent associe un autre badge et l'alerte se règle d'elle-même
    supAs($this->agent);
    $headers = ['X-Device-Token' => SUP_TOKEN, 'Accept' => 'application/json'];
    expect($this->getJson('/poste/api/visiteurs/'.supVisitor(1), $headers)->assertOk()->json('a_rebadger'))->toBeTrue();
    app(BadgeService::class)->associer($this->batch1[1], supVisitor(1), $this->agent, DemoSeeder::id('device'));
    expect(DB::table('alerts')->where('id', $alert->id)->value('resolved_at'))->not->toBeNull();
    expect($this->getJson('/poste/api/visiteurs/'.supVisitor(1), $headers)->json('a_rebadger'))->toBeFalse();
    // par code, sur un badge disponible
    supAs($this->supervisor);
    $this->postJson('/pilotage/badges/annuler', ['code' => $this->batch2[3]->code, 'event_id' => $this->event, 'motif' => 'Défectueux', 'confirmation' => true])->assertOk();
    expect(DB::table('badges')->where('id', $this->batch2[3]->id)->value('status'))->toBe('Annulé');
    $this->postJson('/pilotage/badges/annuler', ['code' => 'B-INCONNU', 'motif' => 'x', 'confirmation' => true])->assertStatus(422)->assertJson(['code' => 'badge_inconnu']);
});

it('enregistre la décision du superviseur sur un refus : maintenir, autoriser avec conditions, à revoir', function () {
    $headers = ['X-Device-Token' => SUP_TOKEN, 'Accept' => 'application/json', 'X-Operation-Id' => (string) Str::uuid()];
    supAs($this->agent);
    $this->postJson('/poste/api/refuser', ['visitor_id' => supVisitor(5), 'motif' => 'Pièce illisible'], $headers)->assertOk();
    $this->postJson('/poste/api/refuser', ['visitor_id' => supVisitor(6), 'motif' => 'Comportement'], [...$headers, 'X-Operation-Id' => (string) Str::uuid()])->assertOk();
    $a = DB::table('refusals')->where('visitor_id', supVisitor(5))->value('id');
    $b = DB::table('refusals')->where('visitor_id', supVisitor(6))->value('id');
    supAs($this->supervisor);
    $this->get('/pilotage/litiges')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Litiges')->has('rows', 2)->where('rows.0.reason', fn ($r) => in_array($r, ['Pièce illisible', 'Comportement'], true))->where('canDecide', true));
    $this->post("/pilotage/litiges/$a/decision", ['decision' => 'autorise', 'note' => ''])->assertSessionHasErrors('pilotage');
    $this->post("/pilotage/litiges/$a/decision", ['decision' => 'inconnue'])->assertSessionHasErrors('decision');
    expect(DB::table('visitors')->where('id', supVisitor(5))->value('status'))->toBe('refuse');
    $this->post("/pilotage/litiges/$a/decision", ['decision' => 'autorise', 'note' => 'Pièce vérifiée par le superviseur'])->assertRedirect();
    $visitor = DB::table('visitors')->where('id', supVisitor(5))->first();
    expect($visitor->status)->toBe('attendu')->and($visitor->access_note)->toBe('Pièce vérifiée par le superviseur');
    $refusal = DB::table('refusals')->where('id', $a)->first();
    expect($refusal->decision)->toBe('autorise')->and($refusal->supervisor_id)->toBe($this->supervisor->id)->and($refusal->decided_at)->not->toBeNull()->and($refusal->supervisor_decision)->toBe('Pièce vérifiée par le superviseur');
    // l'agent voit la mention et peut de nouveau traiter la personne
    supAs($this->agent);
    $fiche = $this->getJson('/poste/api/visiteurs/'.supVisitor(5), ['X-Device-Token' => SUP_TOKEN, 'Accept' => 'application/json'])->assertOk()->json();
    expect($fiche['status'])->toBe('attendu')->and($fiche['access_note'])->toBe('Pièce vérifiée par le superviseur');
    $this->postJson('/poste/api/valider', ['visitor_id' => supVisitor(5), 'avis' => null], [...$headers, 'X-Operation-Id' => (string) Str::uuid()])->assertStatus(422)->assertJson(['code' => 'identite_requise']); // plus « refusé » : la suite normale reprend
    supAs($this->supervisor);
    $this->post("/pilotage/litiges/$b/decision", ['decision' => 'maintenu'])->assertRedirect();
    expect(DB::table('visitors')->where('id', supVisitor(6))->value('status'))->toBe('refuse')->and(DB::table('alerts')->where('subject_id', supVisitor(6))->where('type', 'refus')->value('resolved_at'))->not->toBeNull();
    $this->post("/pilotage/litiges/$b/decision", ['decision' => 'a_revoir', 'note' => 'À revoir demain'])->assertRedirect();
    expect(DB::table('refusals')->where('id', $b)->value('decision'))->toBe('a_revoir');
    expect(supAudit()->where('action', 'litige.decide')->count())->toBe(3);
    foreach (supAudit()->where('action', 'litige.decide')->pluck('details') as $details) {
        expect($details)->not->toContain('Pièce')->and($details)->not->toContain(DB::table('visitors')->where('id', supVisitor(5))->value('full_name'));
    }
});

it('liste les présents avec recherche, filtres et pagination, et affiche le nom seulement à la consultation', function () {
    supEnter(1, $this->batch1[0]);
    supEnter(2, $this->batch2[0], 1);
    supEnter(3, $this->batch0[0], 2);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event, 'visitor_id' => supVisitor(1), 'badge_id' => $this->batch1[0]->id, 'actor_id' => $this->agent->id, 'type' => 'controle', 'checkpoint' => 'Salle principale', 'occurred_at' => now()->addSeconds(10), 'details' => json_encode(['point_type' => 'zone']), 'created_at' => now(), 'updated_at' => now()]);
    $name = DB::table('visitors')->where('id', supVisitor(1))->value('full_name');
    $this->get('/pilotage/presents')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Presents')->where('total', 3)->has('rows', 3)->has('categories')->has('zones'));
    $this->get('/pilotage/presents?q='.urlencode(Str::lower(Str::ascii($name))))->assertInertia(fn (AssertableInertia $p) => $p->where('total', fn ($t) => $t >= 1)->where('rows.0.full_name', $name)->where('rows.0.zone', 'Salle principale'));
    $this->get('/pilotage/presents?category='.DemoSeeder::id('category0'))->assertInertia(fn (AssertableInertia $p) => $p->where('total', 1)->where('rows.0.category.name', 'VIP'));
    $this->get('/pilotage/presents?zone=Accueil')->assertInertia(fn (AssertableInertia $p) => $p->where('total', 2));
    $this->get('/pilotage/presents?page=0')->assertSessionHasErrors('page');
});

it('répond en moins de 500 ms avec 1 000 présents, par pages de 50', function () {
    $now = now();
    $rows = [];
    $events = [];
    $ids = [];
    for ($i = 0; $i < 1000; $i++) {
        $id = (string) Str::uuid();
        $ids[] = $id;
        $rows[] = ['id' => $id, 'event_id' => $this->event, 'full_name' => 'Présent Fictif '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'organisation' => 'Organisation fictive', 'category_id' => DemoSeeder::id('category1'), 'valid_from' => '2027-01-10 08:00:00+00', 'valid_until' => '2027-01-10 20:00:00+00', 'source' => 'listé', 'status' => 'present', 'created_at' => $now, 'updated_at' => $now];
        $events[] = ['id' => (string) Str::uuid(), 'event_id' => $this->event, 'visitor_id' => $id, 'actor_id' => $this->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => $now->copy()->addSeconds($i), 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('visitors')->insert($chunk);
    }
    foreach (array_chunk($events, 250) as $chunk) {
        DB::table('access_events')->insert($chunk);
    }
    $this->get('/pilotage/presents')->assertOk(); // chauffe
    // Meilleur de trois mesures : un serveur d'intégration partagé peut avoir un coup de ralentissement isolé, pas trois de suite.
    $elapsed = INF;
    $timings = [];
    for ($try = 0; $try < 3; $try++) {
        $started = microtime(true);
        $this->get('/pilotage/presents?page=7')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('total', 1000)->has('rows', 50)->where('page', 7)->where('perPage', 50));
        $this->get('/pilotage')->assertOk();
        $timings[] = round((microtime(true) - $started) * 1000);
        $elapsed = min($elapsed, (microtime(true) - $started) * 1000);
    }
    // En cas de dépassement, le message donne les trois mesures (ms) et le plan d'exécution de la requête des présents.
    $plan = $elapsed < 500 ? '' : implode("\n", array_map(fn ($r) => (string) current((array) $r), DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF) SELECT count(*) FROM (SELECT DISTINCT ON (e.visitor_id) e.visitor_id, e.type FROM access_events e WHERE e.event_id = ? AND e.type IN (\'entree\', \'sortie\') AND e.visitor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM access_events x WHERE x.corrige_id = e.id) ORDER BY e.visitor_id, e.occurred_at DESC, e.created_at DESC, e.id DESC) l', [$this->event])));
    expect($elapsed < 500)->toBeTrue('mesures '.json_encode($timings)." ms\n".$plan);
    expect(app(Presence::class)->count($this->event))->toBe(1000);
});

it('affiche le suivi des groupes en lecture seule, même vide', function () {
    $this->get('/pilotage/groupes')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Groupes')->has('groups')->where('groups.0', fn ($g) => collect(['id', 'name', 'bus_number', 'total', 'badges_remis', 'embarques', 'arrives', 'absents'])->every(fn ($k) => $g->has($k) || array_key_exists($k, (array) $g))));
    $this->post('/pilotage/groupes', [])->assertStatus(405);
    $empty = (string) Str::uuid();
    DB::table('events')->insert(['id' => $empty, 'name' => 'Événement vide fictif', 'starts_at' => '2026-01-01 08:00:00+00', 'ends_at' => '2026-01-01 18:00:00+00', 'created_at' => now(), 'updated_at' => now()]);
    $this->get('/pilotage/groupes?event='.$empty)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('groups', [])->where('event.id', $empty));
});

it('fournit la liste d’évacuation par zone avec les totaux, et journalise chaque consultation sans nom', function () {
    supEnter(1, $this->batch1[0]);
    supEnter(2, $this->batch2[0], 1);
    supEnter(3, $this->batch0[0], 2);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->event, 'visitor_id' => supVisitor(2), 'actor_id' => $this->agent->id, 'type' => 'controle', 'checkpoint' => 'Cour', 'occurred_at' => now()->addSeconds(5), 'details' => json_encode(['point_type' => 'zone']), 'created_at' => now(), 'updated_at' => now()]);
    $data = $this->getJson('/pilotage/api/evacuation')->assertOk()->json();
    expect($data['total'])->toBe(3)->and(array_sum(array_column($data['zones'], 'count')))->toBe(3)->and(array_column($data['zones'], 'name'))->toBe(['Accueil', 'Cour'])
        ->and($data['zones'][0]['people'][0])->toHaveKeys(['id', 'full_name', 'category', 'group', 'zone', 'entered_at'])->and($data['as_of'])->toBeString();
    $this->get('/pilotage/evacuation')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Evacuation'));
    expect(supAudit()->where('action', 'evacuation.consultee')->count())->toBe(1);
    $details = supAudit()->where('action', 'evacuation.consultee')->value('details');
    expect(json_decode($details, true))->toMatchArray(['event_id' => $this->event, 'presents' => 3, 'zones' => 2]);
    foreach (DB::table('visitors')->where('event_id', $this->event)->pluck('full_name') as $name) {
        expect($details)->not->toContain($name);
    }
    // la coquille hors ligne est publique et ne porte aucune donnée
    auth()->logout();
    $this->get('/pilotage/evacuation/shell')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Pilotage/Evacuation')->where('auth', null)->missing('event'));
});

it('choisit l’événement en cours puis le plus récent, et accepte un événement demandé', function () {
    $pilotage = app(Pilotage::class);
    expect($pilotage->event(null)->id)->toBe($this->event);
    $old = (string) Str::uuid();
    DB::table('events')->insert(['id' => $old, 'name' => 'Ancien événement fictif', 'starts_at' => '2026-01-01 08:00:00+00', 'ends_at' => '2026-01-01 18:00:00+00', 'created_at' => now(), 'updated_at' => now()]);
    expect($pilotage->event(null)->id)->toBe($this->event)->and($pilotage->event($old)->id)->toBe($old)->and($pilotage->event((string) Str::uuid())->id)->toBe($this->event);
});
