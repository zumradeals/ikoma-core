<?php

use App\Models\User;
use App\Services\BadgeIssuance;
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
 * Badges et cartes — parcours unique « Préparer un badge » : l'écran et ses points d'accès ne portent aucune règle ; ils appellent BadgeIssuance. Données fictives uniquement.
 */

uses(DatabaseTransactions::class);

function bpUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif préparation', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{person: string, id: string} */
function bpParticipant(string $name = 'Zzpreparer'): array
{
    $e = test()->event;
    $person = app(Persons::class)->create(test()->admin, ['full_name' => $name.' '.Str::lower(Str::random(6))]);

    return ['person' => $person, 'id' => app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $e['id'], 'category_id' => $e['category']])];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = bpUser('administrateur');
    $this->agent = bpUser('agent_accueil');
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité fictive préparation', 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);
    $this->event = ['id' => $id, 'category' => $category];
    app(InstitutionalCategories::class)->initialize($this->admin);
});

it('sert la page et ses points d’accès aux seuls comptes qui gèrent les badges et les listes', function () {
    $this->actingAs($this->admin)->get('/administration/badges')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/BadgesCartes')->has('events')->has('registre.data')->where('canPrepare', true)->where('max', BadgeIssuance::MAX_PER_CALL));
    $this->actingAs($this->agent)->get('/administration/badges')->assertForbidden();
    $this->getJson('/administration/badges/preparer/personnes?support=badge_temporaire&event='.$this->event['id'])->assertForbidden();
    $this->postJson('/administration/badges/preparer/recapitulatif', ['support' => 'badge_temporaire', 'ids' => [(string) Str::uuid()]])->assertForbidden();
    $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => [(string) Str::uuid()], 'operation' => (string) Str::uuid(), 'confirm' => true])->assertForbidden();
});

it('liste les personnes d’une activité avec leur situation : à générer, support en cours, non éligible', function () {
    $a = bpParticipant('Zzalpha');
    $b = bpParticipant('Zzbeta');
    $c = bpParticipant('Zzgamma');
    app(BadgeIssuance::class)->issueTemporary($b['id'], (string) Str::uuid(), $this->admin);
    app(Participations::class)->close($this->admin, $c['id'], 'Fin du stage');
    $r = $this->actingAs($this->admin)->getJson('/administration/badges/preparer/personnes?support=badge_temporaire&event='.$this->event['id'])->assertOk()->json('people');
    $byId = collect($r)->keyBy('id');
    expect($byId[$a['id']]['state'])->toBe('a_generer')->and($byId[$b['id']]['state'])->toBe('existant')->and($byId[$b['id']]['code'])->toMatch('/^B-\d+$/')
        ->and($byId[$c['id']]['state'])->toBe('non_eligible')->and($byId[$c['id']]['reason'])->toContain('clôturée');
    // recherche : la saisie n'est jamais interprétée comme un motif
    $this->getJson('/administration/badges/preparer/personnes?support=badge_temporaire&event='.$this->event['id'].'&q=zzalpha')->assertOk()->assertJsonCount(1, 'people');
    $this->getJson('/administration/badges/preparer/personnes?support=badge_temporaire&event='.$this->event['id'].'&q=%25')->assertOk()->assertJsonCount(0, 'people');
    $this->getJson('/administration/badges/preparer/personnes?support=badge_temporaire')->assertStatus(422);
    $this->getJson('/administration/badges/preparer/personnes?support=autre')->assertStatus(422);
});

it('liste les titulaires permanents avec leur situation de carte', function () {
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $person = app(Persons::class)->create($this->admin, ['full_name' => 'Zztitulaire '.Str::lower(Str::random(6))]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], $this->admin, false, $person);
    $r = $this->actingAs($this->admin)->getJson('/administration/badges/preparer/personnes?support=carte_permanente&q=zztitulaire')->assertOk()->json('people');
    expect($r)->toHaveCount(1)->and($r[0]['id'])->toBe($holder)->and($r[0]['state'])->toBe('non_eligible')->and($r[0]['reason'])->toContain('photo');
    DB::table('permanent_holders')->where('id', $holder)->update(['photo_file' => 'portraits/fictif-'.Str::random(6).'.jpg']);
    expect($this->getJson('/administration/badges/preparer/personnes?support=carte_permanente&q=zztitulaire')->json('people.0.state'))->toBe('a_generer');
});

it('établit le récapitulatif et génère en groupe sans doublon, avec un résultat honnête et rejouable', function () {
    $a = bpParticipant('Zzun');
    $b = bpParticipant('Zzdeux');
    $c = bpParticipant('Zztrois');
    app(Participations::class)->close($this->admin, $c['id'], 'Fin du stage');
    $ids = [$a['id'], $b['id'], $c['id'], $a['id']];
    $this->actingAs($this->admin);
    $plan = $this->postJson('/administration/badges/preparer/recapitulatif', ['support' => 'badge_temporaire', 'ids' => $ids])->assertOk()->json('plan');
    expect($plan['a_generer'])->toBe([$a['id'], $b['id']])->and($plan['non_eligibles'])->toHaveCount(1)->and($plan['doublons_ignores'])->toBe(1);
    $entitlements = DB::table('participation_entitlements')->count();

    // la confirmation explicite est obligatoire
    $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => $ids, 'operation' => $op = (string) Str::uuid()])->assertStatus(422);
    expect(DB::table('badges')->whereIn('printed_for_visitor_id', [$a['id'], $b['id']])->count())->toBe(0);

    $r = $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => $ids, 'operation' => $op, 'confirm' => true])->assertOk();
    expect($r->json('complete'))->toBeFalse()->and($r->json('created'))->toHaveCount(2)->and($r->json('failed'))->toHaveCount(1)->and($r->json('printable'))->toHaveCount(2)
        ->and(DB::table('badges')->whereIn('printed_for_visitor_id', [$a['id'], $b['id']])->count())->toBe(2)->and(DB::table('participation_entitlements')->count())->toBe($entitlements);
    // même demande rejouée : aucun nouveau support, les existants sont rendus
    $again = $this->postJson('/administration/badges/preparer/generer', ['support' => 'badge_temporaire', 'ids' => $ids, 'operation' => $op, 'confirm' => true])->assertOk();
    expect($again->json('created'))->toBe([])->and($again->json('printable'))->toHaveCount(2)->and(DB::table('badges')->whereIn('printed_for_visitor_id', [$a['id'], $b['id']])->count())->toBe(2);
    // trop de personnes : refus
    $this->postJson('/administration/badges/preparer/recapitulatif', ['support' => 'badge_temporaire', 'ids' => array_fill(0, BadgeIssuance::MAX_PER_CALL + 1, (string) Str::uuid())])->assertStatus(422);
});

it('transmet à la page la présélection venue d’une activité, en ne retenant que des identifiants valides d’une activité ordinaire', function () {
    $a = bpParticipant('Zzpreset');
    $this->actingAs($this->admin)->get('/administration/badges?activite='.$this->event['id'].'&ids='.$a['id'].',pas-un-id,'.$a['id'])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preset.event', $this->event['id'])->where('preset.ids', [$a['id'], $a['id']]));
    $this->get('/administration/badges?activite='.(string) Str::uuid())->assertOk()->assertInertia(fn (Assert $page) => $page->where('preset', null));
    $this->get('/administration/badges')->assertOk()->assertInertia(fn (Assert $page) => $page->where('preset', null));
});
