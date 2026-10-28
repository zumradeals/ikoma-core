<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\ListSnapshot;
use App\Services\Presence;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const SITE_TOKEN = '3333333333333333333333333333333333333333333333333333333333333333';

function siteUser(string $role = 'agent_accueil'): User
{
    $user = User::create(['name' => 'Compte fictif L7', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function siteHeaders(?string $operation = null): array
{
    return ['X-Device-Token' => SITE_TOKEN, 'X-Operation-Id' => $operation ?? (string) Str::uuid(), 'Accept' => 'application/json'];
}

function siteAudit(): Builder
{
    return DB::table('audit_logs')->where('id', '>', test()->auditStart);
}

/** Associe un badge disponible à un visiteur du seed et l'enregistre comme entré (comme le poste d'accueil le ferait). */
function siteEnter(int $visitorIndex, Badge $badge): string
{
    test()->travel(1)->seconds(); // les événements d'un même visiteur doivent avoir des heures distinctes
    $visitor = DemoSeeder::id('visitor'.$visitorIndex);
    app(BadgeService::class)->associer($badge, $visitor, test()->agent, DemoSeeder::id('device'));
    $event = (string) Str::uuid();
    DB::table('access_events')->insert(['id' => $event, 'event_id' => DemoSeeder::id('event'), 'visitor_id' => $visitor, 'badge_id' => $badge->id, 'actor_id' => test()->agent->id, 'type' => 'entree', 'checkpoint' => 'Poste fictif', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('visitors')->where('id', $visitor)->update(['status' => 'present', 'updated_at' => now()]);
    test()->travel(1)->seconds();

    return $event;
}

function siteControl(string $code, ?array $point = null)
{
    return test()->postJson('/poste/api/controle', ['code' => $code, 'point' => $point], siteHeaders());
}

function siteZone(int $i): array
{
    return ['type' => 'zone', 'id' => DemoSeeder::id('zone'.$i)];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->agent = siteUser();
    $this->actingAs($this->agent);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', SITE_TOKEN)]);
    $admin = siteUser('administrateur');
    $lots = app(BadgeLots::class);
    // Trois lots, un par catégorie des visiteurs 0 à 2 ; l'événement du seed compte déjà ses propres badges.
    foreach ([0, 1, 2] as $c) {
        $this->{'batch'.$c} = Badge::where('badge_batch_id', $lots->generate(DemoSeeder::id('event'), DemoSeeder::id('category'.$c), 15, $admin)->id)->orderBy('code')->get();
    }
});

it('réserve le contrôle et la sortie aux agents d’accueil enrôlés', function () {
    $code = $this->batch1[0]->code;
    $this->postJson('/poste/api/controle', ['code' => $code], ['X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'])->assertStatus(422)->assertJson(['code' => 'poste_requis']);
    foreach (['administrateur', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(siteUser($role));
        $this->postJson('/poste/api/controle', ['code' => $code], siteHeaders())->assertForbidden();
        $this->postJson('/poste/api/sortie', ['code' => $code], siteHeaders())->assertForbidden();
        $this->getJson('/poste/api/presents', siteHeaders())->assertForbidden();
        $this->get('/controle')->assertForbidden();
    }
    $this->actingAs($this->agent)->get('/controle')->assertOk();
    $this->postJson('/poste/api/controle', ['code' => $code, 'point' => ['type' => 'zone', 'id' => (string) Str::uuid()]], siteHeaders())->assertStatus(422)->assertJson(['code' => 'point_inconnu']);
    expect(DB::table('access_events')->where('type', 'controle')->count())->toBe(0);
});

it('rend un verdict avec sa raison pour chaque cas et enregistre un événement, même pour un refus', function () {
    $service = app(BadgeService::class);
    $event = DemoSeeder::id('event');
    // valide, dans une zone autorisée (visiteur 1 : catégorie Visiteur, zones Accueil, Salle principale, Cour)
    siteEnter(1, $this->batch1[0]);
    $ok = siteControl($this->batch1[0]->code, siteZone(1))->assertOk()->json();
    expect($ok)->toMatchArray(['valid' => true, 'reason' => null, 'zone_ok' => true, 'source' => 'serveur', 'badge_code' => $this->batch1[0]->code])
        ->and($ok['category']['name'])->toBe('Visiteur')->and($ok['zones'])->toBe(['Accueil', 'Cour', 'Salle principale'])->and($ok['person']['name'])->toBeString();
    // valide mais zone non autorisée
    $zone = siteControl($this->batch1[0]->code, siteZone(2))->assertOk()->json();
    expect($zone)->toMatchArray(['valid' => true, 'zone_ok' => false]);
    // QR (préfixe + jeton), sans point de contrôle : le poste est le point
    $token = DB::table('badges')->where('id', $this->batch1[0]->id)->value('qr_token');
    $viaQr = siteControl('IKOMA1:'.$token)->assertOk()->json();
    expect($viaQr['valid'])->toBeTrue()->and($viaQr['zone_ok'])->toBeNull();
    // non associé, inconnu, annulé, restitué, hors période
    expect(siteControl($this->batch1[5]->code)->json())->toMatchArray(['valid' => false, 'reason' => 'non_associe', 'person' => null]);
    expect(siteControl('B-INCONNU-999')->json())->toMatchArray(['valid' => false, 'reason' => 'inconnu']);
    siteEnter(2, $this->batch2[0]);
    $service->annuler($this->batch2[0], siteUser('superviseur'), 'Perdu');
    expect(siteControl($this->batch2[0]->code)->json())->toMatchArray(['valid' => false, 'reason' => 'annule']);
    siteEnter(3, $this->batch0[0]);
    $service->restituer($this->batch0[0], $this->agent);
    expect(siteControl($this->batch0[0]->code)->json())->toMatchArray(['valid' => false, 'reason' => 'restitue']);
    siteEnter(4, $this->batch1[1]);
    DB::table('visitors')->where('id', DemoSeeder::id('visitor4'))->update(['valid_until' => '2027-01-10 09:00:00+00']);
    expect(siteControl($this->batch1[1]->code)->json())->toMatchArray(['valid' => false, 'reason' => 'hors_periode']);
    // un événement par contrôle ; les refus sur badge inconnu ou non associé n'ont pas de visiteur
    $events = DB::table('access_events')->where('type', 'controle')->get();
    expect($events)->toHaveCount(8)->and($events->whereNull('visitor_id')->count())->toBe(5)
        ->and(collect($events)->every(fn ($e) => $e->event_id === $event && $e->actor_id === $this->agent->id && $e->checkpoint !== null))->toBeTrue();
    expect(json_decode($events->firstWhere('checkpoint', 'Espace VIP')->details, true))->toMatchArray(['verdict' => 'valide', 'zone_ok' => false, 'point_type' => 'zone']);
    // journal : un refus et la zone non autorisée, jamais deux fois, jamais de nom
    $refused = siteAudit()->where('action', 'controle.refuse')->get();
    expect($refused)->toHaveCount(6)->and(siteAudit()->where('action', 'badge.controle_refuse')->count())->toBe(0);
    expect($refused->map(fn ($l) => json_decode($l->details, true)['raison'])->sort()->values()->all())->toBe(['annule', 'hors_periode', 'inconnu', 'non_associe', 'restitue', 'zone_non_autorisee']);
    $names = DB::table('visitors')->where('event_id', $event)->pluck('full_name')->all();
    foreach (siteAudit()->get() as $line) {
        foreach ($names as $name) {
            expect($line->details)->not->toContain($name);
        }
    }
});

it('rejoue le même contrôle sans doublon et refuse un identifiant réutilisé pour un autre badge', function () {
    siteEnter(1, $this->batch1[0]);
    $id = (string) Str::uuid();
    $first = $this->postJson('/poste/api/controle', ['code' => $this->batch1[0]->code], siteHeaders($id))->assertOk()->json();
    $second = $this->postJson('/poste/api/controle', ['code' => $this->batch1[0]->code], siteHeaders($id))->assertOk()->assertHeader('X-Idempotent-Replay', 'true')->json();
    expect($second)->toEqual($first)->and(DB::table('access_events')->where('type', 'controle')->count())->toBe(1);
    $this->postJson('/poste/api/controle', ['code' => $this->batch1[1]->code], siteHeaders($id))->assertStatus(409);
});

it('enregistre une sortie, passe le visiteur à « sorti » et restitue le badge selon le réglage', function (string $reusable, string $final) {
    DB::table('settings')->where('key', 'badge_reutilisable')->update(['value' => $reusable]);
    siteEnter(1, $this->batch1[0]);
    siteEnter(2, $this->batch2[0]);
    expect(app(Presence::class)->count(DemoSeeder::id('event')))->toBe(2);
    $out = $this->postJson('/poste/api/sortie', ['code' => 'IKOMA1:'.DB::table('badges')->where('id', $this->batch1[0]->id)->value('qr_token'), 'point' => siteZone(0)], siteHeaders())->assertOk()->json();
    expect($out)->toMatchArray(['ok' => true, 'badge_status' => $final, 'presents' => 1, 'point' => 'Accueil']);
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('status'))->toBe('sorti')
        ->and(DB::table('badges')->where('id', $this->batch1[0]->id)->value('status'))->toBe($final)
        ->and(DB::table('access_events')->where('type', 'sortie')->count())->toBe(1)
        ->and(DB::table('badge_assignments')->where('badge_id', $this->batch1[0]->id)->whereNotNull('returned_at')->count())->toBe(1);
    $line = siteAudit()->where('action', 'sortie.enregistree')->first();
    expect(json_decode($line->details, true))->toMatchArray(['statut_badge' => $final, 'presents' => 1])->and($line->details)->not->toContain(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('full_name'));
    // le badge sorti n'est plus valable en contrôle
    expect(siteControl($this->batch1[0]->code)->json()['valid'])->toBeFalse();
})->with([['oui', 'Disponible'], ['non', 'Clos']]);

it('refuse une sortie sans entrée, une deuxième sortie, un badge non associé, annulé ou inconnu, sans rien écrire de plus', function () {
    $sortie = fn (string $code) => $this->postJson('/poste/api/sortie', ['code' => $code], siteHeaders())->assertOk()->json();
    // associé mais jamais entré
    app(BadgeService::class)->associer($this->batch1[0], DemoSeeder::id('visitor1'), $this->agent, DemoSeeder::id('device'));
    expect($sortie($this->batch1[0]->code))->toMatchArray(['ok' => false, 'reason' => 'sortie_sans_entree']);
    siteEnter(2, $this->batch2[0]);
    expect($sortie($this->batch2[0]->code)['ok'])->toBeTrue();
    expect($sortie($this->batch2[0]->code))->toMatchArray(['ok' => false, 'reason' => 'deja_sorti'])->and($sortie($this->batch2[0]->code)['message'])->toContain('déjà');
    expect($sortie($this->batch1[7]->code))->toMatchArray(['reason' => 'non_associe'])->and($sortie('B-INCONNU-1'))->toMatchArray(['reason' => 'inconnu']);
    siteEnter(3, $this->batch0[0]);
    app(BadgeService::class)->annuler($this->batch0[0], siteUser('superviseur'), 'Perdu');
    expect($sortie($this->batch0[0]->code))->toMatchArray(['reason' => 'annule']);
    expect(DB::table('access_events')->where('type', 'sortie')->count())->toBe(1)
        ->and(siteAudit()->where('action', 'sortie.refusee')->count())->toBe(6)
        ->and(siteAudit()->where('action', 'sortie.enregistree')->count())->toBe(1);
});

it('refuse une sortie concurrente d’un même badge : une seule est enregistrée', function () {
    siteEnter(1, $this->batch1[0]);
    $a = $this->postJson('/poste/api/sortie', ['code' => $this->batch1[0]->code], siteHeaders())->json();
    $b = $this->postJson('/poste/api/sortie', ['code' => $this->batch1[0]->code], siteHeaders())->json();
    expect([$a['ok'], $b['ok']])->toBe([true, false])->and(DB::table('access_events')->where('type', 'sortie')->count())->toBe(1);
});

it('tient un compteur exact sur 200 mouvements avec corrections, sans double comptage', function () {
    $presence = app(Presence::class);
    $event = DemoSeeder::id('event');
    $visitors = DB::table('visitors')->where('event_id', $event)->orderBy('id')->limit(40)->pluck('id')->all();
    $state = array_fill_keys($visitors, []); // pile des événements non corrigés par visiteur
    $present = fn (array $stack) => $stack !== [] && end($stack)['type'] === 'entree';
    mt_srand(7);
    $moves = 0;
    $second = 0;
    $insert = function (string $visitor, string $type) use (&$state, $event, &$second): void {
        $id = (string) Str::uuid();
        DB::table('access_events')->insert(['id' => $id, 'event_id' => $event, 'visitor_id' => $visitor, 'badge_id' => null, 'actor_id' => test()->agent->id, 'type' => $type, 'checkpoint' => 'Poste fictif', 'occurred_at' => now()->addSeconds(++$second), 'created_at' => now(), 'updated_at' => now()]);
        $state[$visitor][] = ['id' => $id, 'type' => $type];
    };
    while ($moves < 200) {
        $visitor = $visitors[mt_rand(0, count($visitors) - 1)];
        $roll = mt_rand(1, 10);
        if ($roll <= 2 && $state[$visitor] !== []) {
            $last = array_pop($state[$visitor]);
            $presence->corriger($last['id'], $this->agent, 'Erreur de scan fictive');
        } elseif ($roll === 3) {
            $insert($visitor, $present($state[$visitor]) ? 'entree' : 'sortie'); // doublon d'un même sens : ne compte qu'une fois
        } else {
            $insert($visitor, $present($state[$visitor]) ? 'sortie' : 'entree');
        }
        $moves++;
        if ($moves % 25 === 0) {
            expect($presence->count($event))->toBe(count(array_filter($state, $present)), "après $moves mouvements");
        }
    }
    expect($presence->count($event))->toBe(count(array_filter($state, $present)));
    // les événements corrigés restent en base, la correction est un événement distinct
    expect(DB::table('access_events')->where('type', 'correction')->whereNotNull('corrige_id')->count())->toBeGreaterThan(10);
});

it('recalcule le statut du visiteur après une correction et refuse de corriger deux fois ou autre chose qu’une entrée ou une sortie', function () {
    $entry = siteEnter(1, $this->batch1[0]);
    $this->postJson('/poste/api/sortie', ['code' => $this->batch1[0]->code], siteHeaders())->assertOk();
    $exit = DB::table('access_events')->where('type', 'sortie')->value('id');
    $presence = app(Presence::class);
    expect($presence->count(DemoSeeder::id('event')))->toBe(0);
    $presence->corriger($exit, $this->agent, 'Sortie saisie par erreur');
    expect($presence->count(DemoSeeder::id('event')))->toBe(1)->and(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('status'))->toBe('present');
    expect(fn () => $presence->corriger($exit, $this->agent, 'Encore'))->toThrow(DeskRefused::class);
    expect(fn () => $presence->corriger($entry, $this->agent, ' '))->toThrow(DeskRefused::class);
    $control = DB::table('access_events')->where('type', 'controle')->value('id') ?? null;
    siteControl($this->batch1[0]->code);
    expect(fn () => $presence->corriger((string) DB::table('access_events')->where('type', 'controle')->value('id'), $this->agent, 'Non'))->toThrow(DeskRefused::class);
    expect(DB::table('access_events')->where('corrige_id', $exit)->count())->toBe(1);
});

it('expose le compteur au superviseur et à l’agent, jamais aux autres rôles', function () {
    siteEnter(1, $this->batch1[0]);
    siteEnter(2, $this->batch2[0]);
    $event = DemoSeeder::id('event');
    $this->getJson('/poste/api/presents', siteHeaders())->assertOk()->assertJson(['count' => 2]);
    $this->actingAs(siteUser('superviseur'))->getJson('/api/presences?event_id='.$event)->assertOk()->assertJson(['event_id' => $event, 'count' => 2]);
    foreach (['administrateur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(siteUser($role))->getJson('/api/presences?event_id='.$event)->assertForbidden();
    }
    $this->actingAs(siteUser('superviseur'))->getJson('/api/presences')->assertStatus(422);
});

it('copie sur la tablette tous les badges de l’événement en hachage, ses badges réservés, les points de contrôle, sans que les contrôles changent la version', function () {
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $snapshot = app(ListSnapshot::class);
    $before = $snapshot->version($poste);
    $data = $snapshot->build($poste);
    $all = DB::table('badges')->where('event_id', DemoSeeder::id('event'))->count();
    expect($data['badges'])->toHaveCount($all)->and(collect($data['badges'])->where('mine', true)->count())->toBe(DB::table('badges')->where('reserved_device_id', $poste->id)->count());
    expect(json_encode($data))->not->toContain(DB::table('badges')->value('qr_token'))->and($data['postes'])->not->toBeEmpty();
    siteEnter(1, $this->batch1[0]);
    $afterEntry = $snapshot->version($poste);
    expect($afterEntry)->not->toBe($before);
    siteControl($this->batch1[0]->code);
    siteControl('B-INCONNU-1');
    expect($snapshot->version($poste))->toBe($afterEntry);
    // une annulation de badge change la version : la tablette recopie la liste dès qu'elle se reconnecte
    app(BadgeService::class)->annuler($this->batch1[0], siteUser('superviseur'), 'Perdu');
    expect($snapshot->version($poste))->not->toBe($afterEntry);
});

function siteSync(array $ops, string $token = SITE_TOKEN)
{
    return test()->postJson('/poste/api/sync', ['sent_at' => now()->toIso8601String(), 'operations' => $ops], ['X-Device-Token' => $token, 'Accept' => 'application/json']);
}

function siteOp(string $type, array $payload, int $seq, ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid(), 'seq' => $seq, 'type' => $type, 'agent_id' => test()->agent->id, 'client_time' => now()->subMinutes(5)->toIso8601String(), 'payload' => $payload];
}

it('rejoue les contrôles et sorties faits hors ligne, une seule fois, et met en quarantaine ce que le serveur refuse', function () {
    siteEnter(1, $this->batch1[0]); // entré avant la coupure
    app(BadgeService::class)->associer($this->batch2[0], DemoSeeder::id('visitor2'), $this->agent, DemoSeeder::id('device')); // associé, jamais entré
    siteEnter(3, $this->batch0[0]);
    $id = (string) Str::uuid();
    $ops = [
        siteOp('controle', ['code' => $this->batch1[0]->code, 'point' => siteZone(1), 'local_valid' => true], 1, $id),
        siteOp('sortie', ['code' => $this->batch1[0]->code, 'point' => siteZone(0)], 2),
        siteOp('sortie', ['code' => $this->batch1[0]->code], 3), // double sortie
        siteOp('sortie', ['code' => $this->batch2[0]->code], 4), // sortie sans entrée connue
        siteOp('controle', ['code' => $this->batch0[0]->code, 'local_valid' => true], 5), // badge annulé entre-temps
        siteOp('controle', ['code' => $this->batch1[5]->code, 'local_valid' => false], 6), // refusé hors ligne, refusé aussi par le serveur : rien à signaler
    ];
    app(BadgeService::class)->annuler($this->batch0[0], siteUser('superviseur'), 'Perdu');
    $result = siteSync($ops)->assertOk()->json();
    expect(collect($result['results'])->pluck('status')->all())->toBe(['applique', 'applique', 'conflit', 'conflit', 'conflit', 'applique'])
        ->and(collect($result['results'])->pluck('code')->filter()->values()->all())->toBe(['deja_sorti', 'sortie_sans_entree', 'annule_entre_temps']);
    expect($result['summary'])->toMatchArray(['received' => 6, 'applied' => 3, 'conflicts' => 3]);
    expect(DB::table('sync_conflicts')->where('kind', 'conflit')->orderBy('reason')->pluck('reason')->all())->toBe(['annule_entre_temps', 'deja_sorti', 'sortie_sans_entree']);
    // l'événement porte l'heure déclarée par la tablette ; la sortie est appliquée une seule fois ; un renvoi ne change rien
    expect(DB::table('access_events')->where('type', 'sortie')->count())->toBe(1);
    $declared = json_decode((string) DB::table('access_events')->where('type', 'controle')->orderBy('created_at')->value('details'), true);
    expect($declared)->toHaveKey('heure_declaree')->and($declared['differe'])->toBeTrue()->and($declared['verdict_tablette'])->toBe('valide');
    $events = DB::table('access_events')->count();
    $replay = siteSync($ops)->assertOk()->json();
    expect(collect($replay['results'])->pluck('replayed')->filter()->count())->toBe(6)->and(DB::table('access_events')->count())->toBe($events)->and(DB::table('sync_conflicts')->where('kind', 'conflit')->count())->toBe(3);
    expect(DB::table('visitors')->where('id', DemoSeeder::id('visitor1'))->value('status'))->toBe('sorti');
});

it('met en quarantaine une sortie ou un contrôle d’un agent sans droit de contrôle', function () {
    $this->actingAs($this->agent);
    $op = siteOp('sortie', ['code' => $this->batch1[0]->code], 1);
    DB::table('users')->where('id', $this->agent->id)->update(['active' => false]);
    $result = siteSync([$op])->assertOk()->json();
    expect($result['results'][0])->toMatchArray(['status' => 'conflit', 'code' => 'agent_desactive']);
});
