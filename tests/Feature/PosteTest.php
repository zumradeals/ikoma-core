<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function deskUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif L5', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function deskJpeg(int $w = 320, int $h = 240): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefilledrectangle($image, 0, 0, $w, $h, imagecolorallocate($image, 30, 90, 160));
    ob_start();
    imagejpeg($image, null, 80);

    return (string) ob_get_clean();
}

/** En-têtes d'une écriture du poste : poste choisi et identifiant d'opération neuf (ou imposé pour tester le rejeu). */
function deskHeaders(?string $operation = null): array
{
    return ['X-Poste-Id' => DemoSeeder::id('device'), 'X-Operation-Id' => $operation ?? (string) Str::uuid(), 'Accept' => 'application/json'];
}

/** Visiteur n°$i du seed (0 VIP, 1 Visiteur, 2 Prestataire, i % 3). */
function deskVisitor(int $i): string
{
    return DemoSeeder::id('visitor'.$i);
}

function deskIdentity(string $visitor, ?string $operation = null)
{
    return test()->post('/poste/api/identite', ['visitor_id' => $visitor, 'identity_checked' => true, 'photo' => UploadedFile::fake()->createWithContent('photo.jpg', deskJpeg())], deskHeaders($operation));
}

function deskLot(int $expected = 6, int $category = 1): Badge
{
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category'.$category), $expected, deskUser('administrateur'));

    return Badge::where('badge_batch_id', $batch->id)->orderBy('code')->firstOrFail();
}

/** Parcours jusqu'à la validation d'un visiteur du seed. */
function deskValidated(int $i, ?string $avis = null): string
{
    $visitor = deskVisitor($i);
    deskIdentity($visitor)->assertOk();
    test()->postJson('/poste/api/valider', ['visitor_id' => $visitor, 'avis' => $avis], deskHeaders())->assertOk();

    return $visitor;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
    $this->agent = deskUser('agent_accueil');
    $this->actingAs($this->agent);
});

it('réserve le poste au rôle agent_accueil et refuse tous les autres rôles sur chaque route', function () {
    $routes = [['get', '/poste'], ['get', '/poste/api/postes'], ['get', '/poste/api/contexte'], ['get', '/poste/api/recherche?q=yao'], ['get', '/poste/api/visiteurs/'.deskVisitor(1)],
        ['post', '/poste/api/identite'], ['post', '/poste/api/valider'], ['post', '/poste/api/refuser'], ['post', '/poste/api/badge'], ['post', '/poste/api/entree'], ['post', '/poste/api/non-listes']];
    foreach (['administrateur', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(deskUser($role));
        foreach ($routes as [$method, $url]) {
            $this->json($method, $url, [], deskHeaders())->assertForbidden();
        }
    }
    auth()->logout();
    $this->app['auth']->forgetGuards();
    foreach ($routes as [$method, $url]) {
        expect($this->json($method, $url, [], deskHeaders())->status())->toBeIn([401, 302]);
    }
    $this->actingAs($this->agent)->get('/poste')->assertOk();
    expect(DB::table('sync_operations')->count())->toBe(0);
});

it('exige un poste valide et un identifiant d’opération pour écrire', function () {
    $this->getJson('/poste/api/contexte')->assertStatus(422)->assertJson(['code' => 'poste_requis']);
    $this->getJson('/poste/api/contexte', ['X-Poste-Id' => (string) Str::uuid()])->assertStatus(422);
    $this->postJson('/poste/api/refuser', ['visitor_id' => deskVisitor(1), 'motif' => 'x'], ['X-Poste-Id' => DemoSeeder::id('device')])->assertStatus(422)->assertJson(['code' => 'operation_requise']);
    $this->postJson('/poste/api/refuser', ['visitor_id' => deskVisitor(1), 'motif' => 'x'], [...deskHeaders(), 'X-Operation-Id' => 'pas-un-uuid'])->assertStatus(422)->assertJson(['code' => 'operation_requise']);
    $context = $this->getJson('/poste/api/contexte', deskHeaders())->assertOk()->json();
    expect($context['poste']['id'])->toBe(DemoSeeder::id('device'))->and($context['hosts'])->toHaveCount(3)->and($context['categories'])->toHaveCount(4);
});

it('cherche sur n’importe quel mot du nom, avec accents et fautes, VIP d’abord à qualité égale', function () {
    $event = DemoSeeder::id('event');
    $now = now();
    DB::table('visitors')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'full_name' => 'Marie Koné', 'organisation' => 'Organisation fictive 3', 'category_id' => DemoSeeder::id('category1'), 'valid_from' => $now, 'valid_until' => $now->copy()->addDay(), 'source' => 'listé', 'status' => 'attendu', 'created_at' => $now, 'updated_at' => $now]);
    $search = fn (string $q) => collect($this->getJson('/poste/api/recherche?q='.urlencode($q), deskHeaders())->assertOk()->json('results'));
    expect($search('Koné')->pluck('full_name')->all())->toContain('Marie Koné')->toContain('Koné Mariam');
    expect($search('kone')->pluck('full_name')->all())->toContain('Marie Koné')->toContain('Koné Mariam');
    expect($search('mariam kone')->pluck('full_name')->take(2)->all())->toBe(['Koné Mariam', 'Koné Mariam']);
    expect($search('Marie')->pluck('full_name')->all())->toContain('Marie Koné');
    expect($search('Mariam Konne')->pluck('full_name')->take(2)->all())->toBe(['Koné Mariam', 'Koné Mariam']);
    expect($search('Yaw Brou')->first()['full_name'])->toBe('Yao Brou');
    expect($search('ko')->first()['full_name'])->toBe('Koné Mariam');
    $yao = $search('Yao Brou');
    expect($yao->count())->toBe(3)->and($yao->first()['category']['priority_circuit'])->toBeTrue()->and($yao->first()['homonyms'])->toBe(3)->and($yao->pluck('organisation')->unique()->count())->toBeGreaterThan(1);
    expect(array_keys($yao->first()))->toBe(['id', 'full_name', 'organisation', 'host', 'homonyms', 'category']);
    expect($search('Visiteur fictif'))->toHaveCount(10);
    expect($search('x'))->toHaveCount(0)->and($search("' OR 1=1 --"))->toHaveCount(0)->and($search('zzzzzz'))->toHaveCount(0);
    // un autre événement n'est jamais visible
    $other = (string) Str::uuid();
    $category = (string) Str::uuid();
    DB::table('events')->insert(['id' => $other, 'name' => 'Autre événement fictif', 'starts_at' => $now, 'ends_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $other, 'name' => 'Visiteur', 'color' => '#0F5AA6', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('visitors')->insert(['id' => (string) Str::uuid(), 'event_id' => $other, 'full_name' => 'Personne fictive Ailleurs', 'category_id' => $category, 'valid_from' => $now, 'valid_until' => $now->copy()->addDay(), 'source' => 'listé', 'created_at' => $now, 'updated_at' => $now]);
    expect($search('Ailleurs'))->toHaveCount(0);
    $this->getJson('/poste/api/visiteurs/'.DB::table('visitors')->where('event_id', $other)->value('id'), deskHeaders())->assertNotFound();
});

it('présente la fiche avec zones, période et état du parcours', function () {
    $fiche = $this->getJson('/poste/api/visiteurs/'.deskVisitor(1), deskHeaders())->assertOk()->json();
    expect($fiche)->toMatchArray(['full_name' => DB::table('visitors')->where('id', deskVisitor(1))->value('full_name'), 'hors_periode' => false, 'identity_done' => false, 'photo_done' => false, 'validated' => false, 'badge_code' => null, 'status' => 'attendu', 'accueil_limite' => false])
        ->and($fiche['zones'])->toBe(['Accueil', 'Cour', 'Salle principale'])->and($fiche['category']['name'])->toBe('Visiteur');
    $this->travelTo('2027-01-11 09:00:00');
    $this->withSession(['last_interaction' => now()->timestamp]); // la session ne doit pas expirer pendant le saut dans le temps
    expect($this->getJson('/poste/api/visiteurs/'.deskVisitor(1), deskHeaders())->json('hors_periode'))->toBeTrue();
});

it('interdit la validation sans pièce d’identité cochée et sans photo, côté serveur', function () {
    $visitor = deskVisitor(1);
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor], deskHeaders())->assertStatus(422)->assertJson(['code' => 'identite_requise']);
    DB::table('identity_checks')->insert(['id' => (string) Str::uuid(), 'visitor_id' => $visitor, 'actor_id' => $this->agent->id, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor], deskHeaders())->assertStatus(422)->assertJson(['code' => 'identite_requise']);
    $this->post('/poste/api/identite', ['visitor_id' => deskVisitor(4), 'identity_checked' => false, 'photo' => UploadedFile::fake()->createWithContent('p.jpg', deskJpeg())], deskHeaders())->assertStatus(422)->assertJson(['code' => 'identite_non_cochee']);
    $this->post('/poste/api/identite', ['visitor_id' => deskVisitor(4), 'identity_checked' => true], deskHeaders())->assertStatus(422);
    expect(DB::table('access_events')->where('type', 'validation')->count())->toBe(0)->and(DB::table('photos')->count())->toBe(0);
    deskIdentity($visitor)->assertOk()->assertJson(['identity_done' => true, 'photo_done' => true]);
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor], deskHeaders())->assertOk()->assertJson(['validated' => true, 'hors_periode' => false]);
    $event = DB::table('access_events')->where('visitor_id', $visitor)->first();
    expect($event->type)->toBe('validation')->and($event->actor_id)->toBe($this->agent->id)->and($event->checkpoint)->toBe('Poste fictif A')->and(json_decode($event->details, true))->toEqual(['hors_periode' => false, 'avis' => null]);
    expect(DB::table('identity_checks')->where('visitor_id', $visitor)->count())->toBe(2); // la ligne insérée à la main plus celle de l'opération
});

it('refuse un visiteur avec un motif obligatoire, sans associer de badge', function () {
    $visitor = deskVisitor(1);
    $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => '   '], deskHeaders())->assertStatus(422);
    $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor], deskHeaders())->assertStatus(422);
    $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => 'Pièce illisible'], deskHeaders())->assertOk()->assertJson(['refused' => true]);
    $refusal = DB::table('refusals')->where('visitor_id', $visitor)->first();
    expect($refusal->reason)->toBe('Pièce illisible')->and($refusal->actor_id)->toBe($this->agent->id)->and(DB::table('visitors')->where('id', $visitor)->value('status'))->toBe('refuse');
    deskIdentity($visitor)->assertStatus(422)->assertJson(['code' => 'visiteur_refuse']);
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor], deskHeaders())->assertStatus(422)->assertJson(['code' => 'visiteur_refuse']);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => 'B-001'], deskHeaders())->assertStatus(422)->assertJson(['code' => 'visiteur_refuse']);
    expect(DB::table('badge_assignments')->where('visitor_id', $visitor)->count())->toBe(0);
    $log = json_decode(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visiteur.refuse')->value('details'), true);
    expect(array_keys($log))->toBe(['visitor_id', 'refusal_id', 'device_id']);
});

it('exige l’avis de l’hôte ou du superviseur pour un visiteur hors période et le conserve', function () {
    $this->travelTo('2027-01-11 09:00:00');
    $visitor = deskVisitor(1);
    deskIdentity($visitor)->assertOk();
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor], deskHeaders())->assertStatus(422)->assertJson(['code' => 'hors_periode'])->assertJsonPath('message', "Visiteur hors période : avis de l'hôte ou du superviseur requis");
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor, 'avis' => 'voisin'], deskHeaders())->assertStatus(422);
    expect(DB::table('access_events')->where('type', 'validation')->count())->toBe(0);
    $this->postJson('/poste/api/valider', ['visitor_id' => $visitor, 'avis' => 'superviseur'], deskHeaders())->assertOk()->assertJson(['hors_periode' => true]);
    expect(json_decode(DB::table('access_events')->where('visitor_id', $visitor)->value('details'), true))->toEqual(['hors_periode' => true, 'avis' => 'superviseur']);
    // le badge peut ensuite être associé grâce à l'avis enregistré, sans que le client puisse le réinventer
    $lot = deskLot();
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $lot->code], deskHeaders())->assertOk()->assertJson(['badge_code' => $lot->code]);
    $log = json_decode(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'badge.associe')->value('details'), true);
    expect($log['avis'])->toBe('superviseur');
});

it('associe le badge par code ou par QR et explique chaque refus avec l’action suivante', function () {
    $lot = deskLot(8);
    $badges = Badge::where('badge_batch_id', $lot->badge_batch_id)->orderBy('code')->get();
    $visitor = deskValidated(1);
    $this->postJson('/poste/api/badge', ['visitor_id' => deskVisitor(4), 'code' => $badges[0]->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'validation_requise']);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => 'B-9999'], deskHeaders())->assertStatus(422)->assertJson(['code' => 'badge_inconnu']);
    // catégorie incompatible (badge Prestataire, visiteur Visiteur)
    $other = deskLot(2, 2);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $other->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'categorie', 'message' => 'Badge de catégorie Prestataire, visiteur de catégorie Visiteur', 'action' => 'Prenez un badge de catégorie Visiteur.']);
    // réservé à un autre poste
    $tablet = (string) Str::uuid();
    DB::table('devices')->insert(['id' => $tablet, 'event_id' => DemoSeeder::id('event'), 'name' => 'Tablette fictive B', 'station' => 'Poste fictif B', 'created_at' => now(), 'updated_at' => now()]);
    $number = (int) substr($badges[1]->code, 2);
    app(BadgeLots::class)->reserve($lot->badge_batch_id, $number, $number, Device::findOrFail($tablet), deskUser('administrateur'));
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $badges[1]->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'autre_poste', 'action' => 'Prenez un badge réservé à ce poste ou non réservé.']);
    // annulé
    app(BadgeService::class)->annuler($badges[2], deskUser('superviseur'), 'Abîmé');
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $badges[2]->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'annule', 'action' => 'Prenez un autre badge.']);
    expect(DB::table('badge_assignments')->where('visitor_id', $visitor)->count())->toBe(0);
    // association par le contenu du QR, en minuscules ou majuscules
    $token = DB::table('badges')->where('id', $badges[3]->id)->value('qr_token');
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => 'IKOMA1:'.$token], deskHeaders())->assertOk()->assertJson(['badge_code' => $badges[3]->code]);
    // badge déjà associé : le refus nomme la personne et propose un autre badge
    $second = deskValidated(4);
    $name = DB::table('visitors')->where('id', deskVisitor(1))->value('full_name');
    $refused = $this->postJson('/poste/api/badge', ['visitor_id' => $second, 'code' => strtolower($badges[3]->code)], deskHeaders())->assertStatus(422)->assertJson(['code' => 'deja_associe', 'action' => 'Prenez un autre badge.']);
    expect($refused->json('message'))->toContain($name);
    // le visiteur déjà badgé ne reçoit pas un deuxième badge
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $badges[4]->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'visiteur_deja_badge']);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $badges[3]->code], deskHeaders())->assertOk();
});

it('enregistre l’entrée avec le chrono, passe le visiteur à présent et rappelle les zones', function () {
    $lot = deskLot();
    $visitor = deskValidated(1);
    $this->postJson('/poste/api/entree', ['visitor_id' => $visitor, 'duration_ms' => 41000], deskHeaders())->assertStatus(422)->assertJson(['code' => 'badge_requis']);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $lot->code], deskHeaders())->assertOk();
    $this->postJson('/poste/api/entree', ['visitor_id' => $visitor, 'duration_ms' => 41000], deskHeaders())->assertOk()->assertJson(['entered' => true, 'zones' => ['Accueil', 'Cour', 'Salle principale']]);
    $entry = DB::table('access_events')->where('visitor_id', $visitor)->where('type', 'entree')->first();
    expect($entry->badge_id)->toBe($lot->id)->and(json_decode($entry->details, true))->toBe(['duree_ms' => 41000])->and(DB::table('visitors')->where('id', $visitor)->value('status'))->toBe('present');
    $this->postJson('/poste/api/entree', ['visitor_id' => $visitor, 'duration_ms' => 50000], deskHeaders())->assertOk();
    expect(DB::table('access_events')->where('visitor_id', $visitor)->where('type', 'entree')->count())->toBe(1);
    deskIdentity($visitor)->assertStatus(422)->assertJson(['code' => 'deja_present']);
});

it('bloque un visiteur non listé tant que l’hôte n’a pas accepté, puis le laisse passer', function () {
    $zone = (string) Str::uuid();
    DB::table('zones')->insert(['id' => $zone, 'event_id' => DemoSeeder::id('event'), 'name' => 'Hall d’accueil', 'est_accueil' => true, 'created_at' => now(), 'updated_at' => now()]);
    $created = $this->postJson('/poste/api/non-listes', ['name' => 'Personne fictive Imprévue', 'organisation' => 'Société fictive', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1')], deskHeaders())->assertOk()->json('visitor_id');
    $row = DB::table('visitors')->where('id', $created)->first();
    expect($row)->toMatchObject(['source' => 'non listé', 'status' => 'attendu', 'host_validation' => 'en_attente', 'organisation' => 'Société fictive']);
    $fiche = $this->getJson('/poste/api/visiteurs/'.$created, deskHeaders())->json();
    expect($fiche['accueil_limite'])->toBeTrue()->and($fiche['zones'])->toBe(['Hall d’accueil'])->and($fiche['host_validation'])->toBe('en_attente');
    deskIdentity($created)->assertOk();
    $this->postJson('/poste/api/valider', ['visitor_id' => $created], deskHeaders())->assertStatus(422)->assertJson(['code' => 'hote_en_attente']);
    $this->postJson('/poste/api/entree', ['visitor_id' => $created], deskHeaders())->assertStatus(422);
    $lot = deskLot();
    $this->postJson('/poste/api/badge', ['visitor_id' => $created, 'code' => $lot->code], deskHeaders())->assertStatus(422)->assertJson(['code' => 'validation_requise']);
    $desk = app(Desk::class);
    $desk->decideHost($created, true, null);
    expect(DB::table('visitors')->where('id', $created)->value('host_validation'))->toBe('accepte');
    expect(fn () => $desk->decideHost($created, false))->toThrow(DeskRefused::class);
    $this->postJson('/poste/api/valider', ['visitor_id' => $created], deskHeaders())->assertOk();
    $this->postJson('/poste/api/badge', ['visitor_id' => $created, 'code' => $lot->code], deskHeaders())->assertOk();
    $this->postJson('/poste/api/entree', ['visitor_id' => $created], deskHeaders())->assertOk()->assertJson(['zones' => ['Hall d’accueil']]);
    // refus de l'hôte : l'entrée reste impossible
    $second = $this->postJson('/poste/api/non-listes', ['name' => 'Autre Personne fictive', 'host_id' => DemoSeeder::id('host1'), 'category_id' => DemoSeeder::id('category1')], deskHeaders())->json('visitor_id');
    deskIdentity($second)->assertOk();
    $desk->decideHost($second, false);
    $this->postJson('/poste/api/valider', ['visitor_id' => $second], deskHeaders())->assertStatus(422)->assertJson(['code' => 'hote_en_attente']);
    // fiche incomplète ou hôte d'un autre événement
    $this->postJson('/poste/api/non-listes', ['name' => 'Sans hôte', 'host_id' => (string) Str::uuid(), 'category_id' => DemoSeeder::id('category1')], deskHeaders())->assertStatus(422)->assertJson(['code' => 'fiche_invalide']);
    expect(DB::table('visitors')->where('source', 'non listé')->count())->toBe(2);
});

it('traite une opération rejouée sans aucun effet double et refuse un identifiant détourné', function () {
    $visitor = deskVisitor(1);
    $operation = (string) Str::uuid();
    $first = $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => 'Motif A'], deskHeaders($operation))->assertOk();
    $replay = $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => 'Motif A'], deskHeaders($operation))->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
    expect($replay->json())->toEqual($first->json())->and(DB::table('refusals')->count())->toBe(1)->and(DB::table('sync_operations')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visiteur.refuse')->count())->toBe(1);
    $this->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => 'Motif B'], deskHeaders($operation))->assertStatus(409)->assertJson(['code' => 'operation_conflit']);
    $this->actingAs(deskUser('agent_accueil'))->postJson('/poste/api/refuser', ['visitor_id' => $visitor, 'motif' => 'Motif A'], deskHeaders($operation))->assertStatus(409);
    $row = DB::table('sync_operations')->first();
    expect($row)->toMatchObject(['id' => $operation, 'type' => 'refuser', 'device_id' => DemoSeeder::id('device'), 'user_id' => $this->agent->id, 'status_code' => 200])->and($row->payload_hash)->toHaveLength(64)->and($row->processed_at)->not->toBeNull();

    // un refus métier est lui aussi rejoué à l'identique, et ses écritures sont annulées
    $this->actingAs($this->agent);
    $bad = (string) Str::uuid();
    $a = $this->postJson('/poste/api/valider', ['visitor_id' => deskVisitor(4)], deskHeaders($bad))->assertStatus(422);
    $b = $this->postJson('/poste/api/valider', ['visitor_id' => deskVisitor(4)], deskHeaders($bad))->assertStatus(422)->assertHeader('X-Idempotent-Replay', 'true');
    expect($b->json())->toEqual($a->json());
    // la photo rejouée n'est enregistrée qu'une fois
    $op = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('p.jpg', deskJpeg());
    for ($i = 0; $i < 3; $i++) {
        $this->post('/poste/api/identite', ['visitor_id' => deskVisitor(7), 'identity_checked' => true, 'photo' => $file], deskHeaders($op))->assertOk();
    }
    expect(DB::table('photos')->where('visitor_id', deskVisitor(7))->count())->toBe(1)->and(DB::table('identity_checks')->where('visitor_id', deskVisitor(7))->count())->toBe(1);
});

it('chiffre la photo au repos, ne l’expose par aucune adresse et refuse les fichiers invalides', function () {
    Storage::fake('local');
    $jpeg = deskJpeg(640, 480);
    $visitor = deskVisitor(1);
    $this->post('/poste/api/identite', ['visitor_id' => $visitor, 'identity_checked' => true, 'photo' => UploadedFile::fake()->createWithContent('p.jpg', $jpeg)], deskHeaders())->assertOk();
    $photo = DB::table('photos')->where('visitor_id', $visitor)->first();
    $stored = Storage::disk('local')->get($photo->file_reference);
    expect($photo->file_reference)->toStartWith('photos/')->and(str_starts_with($stored, "\xFF\xD8"))->toBeFalse()->and($stored)->not->toContain('JFIF')->and(Crypt::decryptString($stored))->toBe($jpeg);
    foreach (['/storage/'.$photo->file_reference, '/'.$photo->file_reference, '/storage/app/private/'.$photo->file_reference, '/poste/api/photos/'.$photo->id] as $url) {
        $this->get($url)->assertNotFound();
    }
    expect(config('filesystems.disks.local.serve'))->toBeFalse()->and(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'visiteur.identite_verifiee')->value('details'))->not->toContain($photo->file_reference);
    // type, dimensions et poids
    foreach ([['p.png', (function () {
        $i = imagecreatetruecolor(50, 50);
        ob_start();
        imagepng($i);

        return ob_get_clean();
    })()], ['grand.jpg', deskJpeg(1200, 900)], ['texte.jpg', 'pas une image']] as [$name, $bytes]) {
        $this->post('/poste/api/identite', ['visitor_id' => deskVisitor(4), 'identity_checked' => true, 'photo' => UploadedFile::fake()->createWithContent($name, $bytes)], deskHeaders())->assertStatus(422)->assertJson(['code' => 'photo_invalide']);
    }
    expect(DB::table('photos')->where('visitor_id', deskVisitor(4))->count())->toBe(0);
});

it('n’écrit aucun nom de personne ni motif dans le journal', function () {
    $lot = deskLot();
    $visitor = deskValidated(1);
    $this->postJson('/poste/api/badge', ['visitor_id' => $visitor, 'code' => $lot->code], deskHeaders())->assertOk();
    $this->postJson('/poste/api/entree', ['visitor_id' => $visitor, 'duration_ms' => 1000], deskHeaders())->assertOk();
    $refused = deskVisitor(4);
    $this->postJson('/poste/api/refuser', ['visitor_id' => $refused, 'motif' => 'Motif confidentiel de test'], deskHeaders())->assertOk();
    $created = $this->postJson('/poste/api/non-listes', ['name' => 'Nom Imprévu Fictif', 'organisation' => 'Société Imprévue', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1')], deskHeaders())->json('visitor_id');
    app(Desk::class)->decideHost($created, true);
    $logs = DB::table('audit_logs')->where('id', '>', $this->auditStart)->get();
    $actions = $logs->pluck('action')->unique()->all();
    foreach (['visiteur.identite_verifiee', 'visiteur.valide', 'visiteur.refuse', 'visiteur.entree', 'visiteur.non_liste_cree', 'visiteur.hote_decision', 'badge.associe'] as $action) {
        expect($actions)->toContain($action);
    }
    $names = [...DB::table('visitors')->pluck('full_name')->all(), ...DB::table('visitors')->whereNotNull('organisation')->pluck('organisation')->all(), 'Motif confidentiel', 'Compte fictif', $this->agent->email];
    foreach ($logs as $log) {
        foreach ($names as $name) {
            expect($log->details)->not->toContain($name);
        }
        foreach (json_decode($log->details, true) ?? [] as $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue();
        }
    }
});
