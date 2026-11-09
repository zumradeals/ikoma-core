<?php

use App\Models\User;
use App\Services\BadgeIssuance;
use App\Services\CardLifecycle;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Badges et cartes — espace de travail à trois colonnes : registre commun (lecture seule), préparation, aperçu. L'écran ne porte aucune règle : il compose des tables existantes
 * et appelle les contrats d'émission (BadgeIssuance) et d'impression (SupportPrinting). Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function bwUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif espace de travail', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{badge: string, code: string, name: string, visitor: string} */
function bwTemporary(string $name = 'Zztemporaire'): array
{
    $e = test()->event;
    $full = $name.' '.Str::lower(Str::random(6));
    $person = app(Persons::class)->create(test()->admin, ['full_name' => $full]);
    $participation = app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $e['id'], 'category_id' => $e['category']]);
    $r = app(BadgeIssuance::class)->issueTemporary($participation, (string) Str::uuid(), test()->admin);

    return ['badge' => $r['badge_id'], 'code' => $r['code'], 'name' => $full, 'visitor' => $participation];
}

/** @return array{badge: string, code: string, name: string, holder: string} */
function bwCard(string $name = 'Zzcarte'): array
{
    app(PermanentHolders::class)->createPermanentContext(test()->admin);
    $full = $name.' '.Str::lower(Str::random(6));
    $person = app(Persons::class)->create(test()->admin, ['full_name' => $full]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], test()->admin, false, $person);
    DB::table('permanent_holders')->where('id', $holder)->update(['photo_file' => 'portraits/fictif-'.Str::random(6).'.jpg']);
    $r = app(BadgeIssuance::class)->issueCard($holder, (string) Str::uuid(), test()->admin);

    return ['badge' => $r['badge_id'], 'code' => $r['code'], 'name' => $full, 'holder' => $holder];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = bwUser('administrateur');
    $this->agent = bwUser('agent_accueil');
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité fictive espace', 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    $this->event = ['id' => $id, 'category' => $category];
    app(InstitutionalCategories::class)->initialize($this->admin);
});

it('ouvre directement l’espace de travail (registre, préparation, aperçu) et non plus l’atelier de lots', function () {
    $t = bwTemporary();
    $this->actingAs($this->admin)->get('/administration/badges')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/BadgesCartes')
        ->where('canPrepare', true)->has('events')->where('max', BadgeIssuance::MAX_PER_CALL)->where('preset', null)->has('states.actif')->where('filters.q', '')
        ->has('registre.data')->where('registre.meta.total', fn ($n) => $n >= 1)->where('registre.data', fn ($rows) => collect($rows)->contains(fn ($r) => $r['code'] === $t['code']))
        ->has('badgesCartes.totals.supports_actifs')->has('badgesCartes.totals.cartes_permanentes')->has('badgesCartes.totals.badges_visiteurs')->has('badgesCartes.totals.tirages_30j')
        ->missing('batches')->missing('batchCounts'));
});

it('refuse l’espace, le registre et l’aperçu aux comptes sans permission, côté serveur', function () {
    $this->actingAs($this->agent)->get('/administration/badges')->assertForbidden();
    $this->getJson('/administration/badges/supports')->assertForbidden();
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [(string) Str::uuid()]])->assertForbidden();
    $this->get('/administration/badges/lots')->assertForbidden();
});

it('sans le droit d’importer des listes : registre consultable, préparation et aperçu réel refusés', function () {
    $t = bwTemporary();
    $limited = bwUser('agent_accueil');
    $limited->givePermissionTo('badges.gerer');
    $this->actingAs($limited)->get('/administration/badges')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/BadgesCartes')->where('canPrepare', false)->where('events', [])->where('preset', null)->has('registre.data'));
    $this->getJson('/administration/badges/supports')->assertOk();
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [$t['badge']]])->assertForbidden();
    $this->get('/administration/badges/preparer')->assertForbidden();
});

it('conserve les anciennes adresses : filtres de lots redirigés vers la page historique, parcours « préparer » dans le même espace', function () {
    $this->actingAs($this->admin);
    $location = (string) $this->get('/administration/badges?status=Disponible&page=2')->assertRedirect()->headers->get('Location');
    expect($location)->toContain('/administration/badges/lots?')->toContain('status=Disponible')->toContain('page=2');
    $this->get('/administration/badges?batch='.DemoSeeder::id('batch'))->assertRedirect();
    $this->get('/administration/badges/lots')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Badges')->has('batches')->has('badges.data'));
    $this->get('/administration/badges/lots?status=Disponible')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Badges'));
    // Lien d'une fiche d'activité : même espace, présélection conservée.
    $this->get('/administration/badges/preparer?activite='.$this->event['id'].'&ids='.Str::uuid())->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/BadgesCartes')->where('preset.event', $this->event['id'])->has('preset.ids', 1));
    $this->get('/administration/atelier/parcours')->assertRedirect('/administration/badges');
    $this->post('/administration/badges/lots', [])->assertSessionHasErrors();
});

it('liste cartes permanentes et badges temporaires dans un seul registre, avec type, titulaire et état dérivés', function () {
    $t = bwTemporary();
    $c = bwCard();
    $rows = $this->actingAs($this->admin)->getJson('/administration/badges/supports?q=zz')->assertOk()->json('data');
    // Le numéro d'un support est unique dans son activité, pas dans tout le site : on identifie par l'identifiant du support.
    $byId = collect($rows)->keyBy('id');
    expect($byId[$t['badge']])->toMatchArray(['type' => 'badge_temporaire', 'holder_name' => $t['name'], 'state' => 'emis', 'state_label' => 'Émis, non remis', 'category' => 'Stagiaire', 'printed' => false])
        ->and($byId[$c['badge']])->toMatchArray(['type' => 'carte_permanente', 'holder_name' => $c['name'], 'state' => 'emis', 'state_label' => 'Émis, non remis', 'printed' => false]);
    // La remise de la carte (activation explicite) la rend active ; le badge temporaire le devient à l'accueil (association), jamais à l'émission.
    app(HolderCards::class)->activate($c['holder'], $this->admin);
    expect(collect($this->getJson('/administration/badges/supports?q=zz')->json('data'))->keyBy('id')[$c['badge']])->toMatchArray(['state' => 'actif', 'state_label' => 'Actif']);
});

it('filtre par type, par état et par recherche, sans jamais interpréter la saisie ni exposer de jeton', function () {
    $t = bwTemporary('Zzalpha');
    $c = bwCard('Zzbeta');
    $this->actingAs($this->admin);
    $cartes = collect($this->getJson('/administration/badges/supports?type=carte_permanente')->assertOk()->json('data'));
    expect($cartes->every(fn ($r) => $r['type'] === 'carte_permanente'))->toBeTrue()->and($cartes->pluck('id'))->toContain($c['badge'])->not->toContain($t['badge']);
    $temp = collect($this->getJson('/administration/badges/supports?type=badge_temporaire&etat=emis&q=zzalpha')->assertOk()->json('data'));
    expect($temp->pluck('code')->all())->toBe([$t['code']]);
    expect(collect($this->getJson('/administration/badges/supports?q='.strtolower($c['code']))->json('data'))->pluck('id'))->toContain($c['badge']);
    expect($this->getJson('/administration/badges/supports?q=%25')->json('meta.total'))->toBe(0);
    expect($this->getJson('/administration/badges/supports?etat=restitue&q=zzalpha')->json('meta.total'))->toBe(0);
    $this->getJson('/administration/badges/supports?type=autre')->assertStatus(422);
    $this->getJson('/administration/badges/supports?etat=inconnu')->assertStatus(422);
    $this->getJson('/administration/badges/supports?page=0')->assertStatus(422);
    $token = (string) DB::table('badges')->where('id', $t['badge'])->value('qr_token');
    $raw = $this->getJson('/administration/badges/supports?q=zzalpha')->getContent();
    expect($raw)->not->toContain($token)->not->toContain('qr_token');
    $this->get('/administration/badges')->assertInertia(fn (Assert $p) => $p->where('registre.data', fn ($rows) => collect($rows)->every(fn ($r) => ! array_key_exists('qr_token', $r))));
});

it('pagine le registre (8 par page) et borne la page demandée', function () {
    for ($i = 0; $i < 9; $i++) {
        bwTemporary('Zzpage');
    }
    $this->actingAs($this->admin);
    $first = $this->getJson('/administration/badges/supports?q=zzpage')->assertOk();
    expect($first->json('meta'))->toMatchArray(['current_page' => 1, 'last_page' => 2, 'total' => 9, 'per_page' => 8])->and($first->json('data'))->toHaveCount(8);
    expect($this->getJson('/administration/badges/supports?q=zzpage&page=2')->json('data'))->toHaveCount(1);
});

it('reflète les cycles de vie : carte révoquée, badge restitué, badge annulé, support imprimé', function () {
    $c = bwCard('Zzrevoque');
    $t = bwTemporary('Zzrestitue');
    $n = bwTemporary('Zzannule');
    app(CardLifecycle::class)->revoke($c['holder'], 'perdue', null, $this->admin);
    // Cycle de vie légitime : Disponible → Associé → Restitué (le déclencheur refuse les autres transitions).
    DB::table('badges')->where('id', $t['badge'])->update(['status' => 'Associé']);
    DB::table('badges')->where('id', $t['badge'])->update(['status' => 'Restitué']);
    DB::table('badges')->where('id', $n['badge'])->update(['status' => 'Annulé']);
    $this->actingAs($this->admin);
    $byId = collect($this->getJson('/administration/badges/supports?q=zz')->assertOk()->json('data'))->keyBy('id');
    expect($byId[$c['badge']]['state'])->toBe('perdu')->and($byId[$c['badge']]['state_label'])->toBe('Perdue')
        ->and($byId[$t['badge']]['state'])->toBe('restitue')->and($byId[$n['badge']]['state'])->toBe('annule');
    expect($this->getJson('/administration/badges/supports?etat=restitue&q=zzrestitue')->json('meta.total'))->toBe(1);
});

it('compte les supports actifs sans doublon : seules les cartes remises et les badges associés comptent, ni l’émis, ni l’imprimé, ni le stock', function () {
    $this->actingAs($this->admin);
    $totals = function (): array {
        $found = [];
        $this->get('/administration/badges')->assertOk()->assertInertia(function (Assert $p) use (&$found): void {
            $found = $p->toArray()['props']['badgesCartes']['totals'];
        });

        return $found;
    };
    $before = $totals();
    $t = bwTemporary();
    $c = bwCard();
    $emitted = $totals();
    expect($emitted['supports_actifs'])->toBe($before['supports_actifs'])->and($emitted['cartes_permanentes'])->toBe($before['cartes_permanentes'])->and($emitted['badges_visiteurs'])->toBe($before['badges_visiteurs']);
    app(HolderCards::class)->activate($c['holder'], $this->admin);
    DB::table('badges')->where('id', $t['badge'])->update(['status' => 'Associé']);
    $after = $totals();
    expect($after['supports_actifs'])->toBe($before['supports_actifs'] + 2)->and($after['cartes_permanentes'])->toBe($before['cartes_permanentes'] + 1)->and($after['badges_visiteurs'])->toBe($before['badges_visiteurs'] + 1)
        ->and($after['supports_actifs'])->toBe($after['cartes_permanentes'] + $after['badges_visiteurs']);
});

it('sert l’aperçu RÉEL d’un support émis (numéro et QR du support), sans créer ni tirage, ni support, ni émission', function () {
    $t = bwTemporary();
    $runs = DB::table('badge_print_runs')->count();
    $items = DB::table('badge_print_items')->count();
    $badges = DB::table('badges')->count();
    $issuances = DB::table('badge_issuances')->count();
    $r = $this->actingAs($this->admin)->postJson('/administration/badges/impression/donnees', ['badges' => [$t['badge']]])->assertOk()->assertHeader('Cache-Control')->json('supports');
    expect($r)->toHaveCount(1)->and($r[0]['badge_code'])->toBe($t['code'])->and($r[0]['name'])->toBe($t['name'])->and($r[0]['qr'])->toContain('<svg')
        ->and(DB::table('badge_print_runs')->count())->toBe($runs)->and(DB::table('badge_print_items')->count())->toBe($items)->and(DB::table('badges')->count())->toBe($badges)->and(DB::table('badge_issuances')->count())->toBe($issuances);
    $token = (string) DB::table('badges')->where('id', $t['badge'])->value('qr_token');
    expect(json_encode($r))->not->toContain($token);
});

it('refuse l’aperçu réel d’un support non émis ou non imprimable, et valide l’entrée', function () {
    $this->actingAs($this->admin);
    $this->postJson('/administration/badges/impression/donnees', ['badges' => []])->assertStatus(422);
    $this->postJson('/administration/badges/impression/donnees', ['badges' => ['pas-un-uuid']])->assertStatus(422);
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [(string) Str::uuid()]])->assertStatus(422);
    $n = bwTemporary();
    DB::table('badges')->where('id', $n['badge'])->update(['status' => 'Annulé']);
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [$n['badge']]])->assertStatus(422)->assertJsonValidationErrors('badges');
});

it('ajoute à chaque personne proposée le modèle institutionnel indicatif de sa catégorie, sans rien créer', function () {
    $c = bwCard('Zzmodele');
    $badges = DB::table('badges')->count();
    $people = $this->actingAs($this->admin)->getJson('/administration/badges/preparer/personnes?support=carte_permanente&q=zzmodele')->assertOk()->json('people');
    expect($people)->toHaveCount(1)->and($people[0]['model'])->toHaveKeys(['code', 'label', 'color', 'mention', 'version', 'validated'])->and($people[0]['model']['code'])->toBe('AILCT-01')
        ->and($people[0]['state'])->toBe('existant')->and(DB::table('badges')->count())->toBe($badges);
    $t = bwTemporary('Zzsansmodele');
    $temp = $this->getJson('/administration/badges/preparer/personnes?support=badge_temporaire&event='.$this->event['id'].'&q=zzsansmodele')->assertOk()->json('people');
    expect($temp[0]['model'])->toBeNull()->and($temp[0]['code'])->toBe($t['code']);
});

it('garde le parcours groupé sur le même contrat : une opération rejouée ne crée aucun doublon', function () {
    $e = $this->event;
    $ids = [];
    foreach (['Zzg1', 'Zzg2', 'Zzg3'] as $n) {
        $person = app(Persons::class)->create($this->admin, ['full_name' => $n.' '.Str::lower(Str::random(6))]);
        $ids[] = app(Participations::class)->enrol($this->admin, $person, ['event_id' => $e['id'], 'category_id' => $e['category']]);
    }
    $this->actingAs($this->admin);
    $op = (string) Str::uuid();
    $first = $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => $ids, 'operation' => $op, 'confirm' => true])->assertOk()->json();
    $badges = DB::table('badges')->count();
    $again = $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => $ids, 'operation' => $op, 'confirm' => true])->assertOk()->json();
    expect($first['created'])->toHaveCount(3)->and($first['printable'])->toHaveCount(3)->and($again['created'])->toBe([])->and(DB::table('badges')->count())->toBe($badges)
        ->and(DB::table('badge_issuances')->whereIn('visitor_id', $ids)->count())->toBe(3);
    // Les trois supports émis apparaissent au registre, non imprimés : générer n'est ni imprimer, ni remettre, ni activer.
    $rows = collect($this->getJson('/administration/badges/supports?q=zzg')->json('data'));
    expect($rows)->toHaveCount(3)->and($rows->every(fn ($r) => $r['printed'] === false && $r['state'] === 'emis'))->toBeTrue();
});

it('masque par défaut le stock de lots sans titulaire, visible sur demande et par la recherche', function () {
    $t = bwTemporary('Zzstock');
    $this->actingAs($this->admin);
    $default = collect($this->getJson('/administration/badges/supports')->assertOk()->json('data'));
    expect($default->contains(fn ($r) => $r['state'] === 'disponible'))->toBeFalse()->and($default->pluck('id'))->toContain($t['badge']);
    $stock = collect($this->getJson('/administration/badges/supports?etat=disponible')->assertOk()->json('data'));
    expect($stock->isNotEmpty())->toBeTrue()->and($stock->every(fn ($r) => $r['state'] === 'disponible'))->toBeTrue();
    $code = (string) $stock->first()['code'];
    expect(collect($this->getJson('/administration/badges/supports?q='.strtolower($code))->json('data'))->pluck('state'))->toContain('disponible');
});

it('ouvre l’historique des tirages par défaut et l’atelier par lots seulement à la demande ou par les anciens liens', function () {
    $this->actingAs($this->admin);
    $this->get('/administration/atelier')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Atelier')->where('vue', 'historique'));
    $this->get('/administration/atelier?vue=atelier')->assertOk()->assertInertia(fn (Assert $p) => $p->where('vue', 'atelier'));
    $this->get('/administration/atelier?format=etiquettes&batch='.DemoSeeder::id('batch'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('vue', 'atelier'));
    $this->get('/administration/atelier?mode=cartes')->assertOk()->assertInertia(fn (Assert $p) => $p->where('vue', 'atelier'));
    $this->get('/administration/atelier?vue=autre')->assertStatus(302);
});

it('ouvre les modèles institutionnels par défaut ; l’ancien modèle par activité reste demandable', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->actingAs($this->admin);
    $this->get('/administration/modeles')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Modeles')->where('section', 'cartes')->has('cards.categories', 8));
    $this->get('/administration/modeles?section=badges')->assertOk()->assertInertia(fn (Assert $p) => $p->where('section', 'badges')->has('preferences'));
});
