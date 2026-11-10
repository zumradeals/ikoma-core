<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeIssuance;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\CardLifecycle;
use App\Services\CardModels;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\Participations;
use App\Services\PermanentHolders;
use App\Services\Persons;
use App\Services\SupportPrinting;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Badges et cartes — contrat d'impression unique (SupportPrinting) : un seul point d'entrée pour imprimer et réimprimer cartes et badges temporaires, un seul rendu (modèles
 * institutionnels), émission puis réimpression motivée, aucun effet sur le support (ni numéro, ni jeton, ni affectation, ni activation, ni habilitation). Données fictives.
 */

uses(DatabaseTransactions::class);

function spUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif impression', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** @return array{id: string, category: string} */
function spEvent(): array
{
    $id = (string) Str::uuid();
    $category = (string) Str::uuid();
    $at = ['created_at' => now(), 'updated_at' => now()];
    DB::table('events')->insert(['id' => $id, 'name' => 'Activité fictive '.Str::random(5), 'starts_at' => '2027-01-01 08:00:00+00', 'ends_at' => '2027-03-01 18:00:00+00', 'kind' => 'stage', ...$at]);
    DB::table('categories')->insert(['id' => $category, 'event_id' => $id, 'name' => 'Stagiaire', 'color' => '#1E9E5A', 'priority_circuit' => false, ...$at]);

    return ['id' => $id, 'category' => $category];
}

/** @return array{badge: string, visitor: string, code: string} support temporaire émis par le contrat canonique */
function spTemporary(?array $event = null): array
{
    $event ??= test()->event;
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzimpression '.Str::lower(Str::random(6))]);
    $participation = app(Participations::class)->enrol(test()->admin, $person, ['event_id' => $event['id'], 'category_id' => $event['category']]);
    $r = app(BadgeIssuance::class)->issueTemporary($participation, (string) Str::uuid(), test()->admin);

    return ['badge' => $r['badge_id'], 'visitor' => $participation, 'code' => $r['code']];
}

/** @return array{badge: string, holder: string, card: string} */
function spCard(): array
{
    app(PermanentHolders::class)->createPermanentContext(test()->admin);
    $person = app(Persons::class)->create(test()->admin, ['full_name' => 'Zzcarte '.Str::lower(Str::random(6))]);
    $holder = app(PermanentHolders::class)->create(['position' => 'Formateur fictif', 'institutional_category' => 'AILCT-01'], test()->admin, false, $person);
    DB::table('permanent_holders')->where('id', $holder)->update(['photo_file' => 'portraits/fictif-'.Str::random(6).'.jpg']);
    $r = app(BadgeIssuance::class)->issueCard($holder, (string) Str::uuid(), test()->admin);

    return ['badge' => $r['badge_id'], 'holder' => $holder, 'card' => (string) DB::table('holder_cards')->where('badge_id', $r['badge_id'])->value('id')];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = spUser('administrateur');
    $this->agent = spUser('agent_accueil');
    $this->event = spEvent();
    $this->actingAs($this->admin);
    $this->printing = app(SupportPrinting::class);
    app(InstitutionalCategories::class)->initialize($this->admin);
});

it('imprime un badge temporaire : première impression = émission, tirage tracé, historique du support, sans effet sur le support', function () {
    $t = spTemporary();
    $before = Badge::findOrFail($t['badge'])->only(['code', 'qr_token', 'status', 'printed_for_visitor_id']);
    $issuances = DB::table('badge_issuances')->count();

    $r = $this->printing->print($this->admin, [$t['badge'], $t['badge']], null);

    expect($r['items'])->toBe([['badge_id' => $t['badge'], 'code' => $t['code'], 'kind' => 'emission']])->and($r['views'])->toHaveCount(1)
        ->and($r['views'][0]['name'])->toContain('Zzimpression')->and($r['views'][0]['badge_code'])->toBe($t['code'])->and($r['views'][0]['qr'])->toContain('<svg');
    $run = DB::table('badge_print_runs')->where('id', $r['run'])->first();
    expect($run->kind)->toBe('support')->and($run->nature)->toBe('officiel')->and($run->batch_id)->toBeNull()->and((int) $run->badge_count)->toBe(1)
        ->and(DB::table('badge_print_events')->where('print_run_id', $r['run'])->where('event_type', 'generated')->count())->toBe(1)
        ->and(DB::table('badge_print_items')->where('badge_id', $t['badge'])->where('kind', 'emission')->count())->toBe(1);
    // aucun effet sur le support : numéro, jeton, état, destination, affectation, émission
    expect(Badge::findOrFail($t['badge'])->only(['code', 'qr_token', 'status', 'printed_for_visitor_id']))->toBe($before)
        ->and(DB::table('badge_assignments')->where('badge_id', $t['badge'])->count())->toBe(0)->and(DB::table('badge_issuances')->count())->toBe($issuances)
        ->and(DB::table('audit_logs')->where('action', 'badge.imprime')->orderByDesc('id')->value('details'))->not->toContain(Badge::findOrFail($t['badge'])->qr_token);
});

it('exige un motif pour toute réimpression et ne crée alors ni numéro, ni jeton, ni affectation, ni émission', function () {
    $t = spTemporary();
    $this->printing->print($this->admin, [$t['badge']], null);
    $badges = DB::table('badges')->count();
    $issuances = DB::table('badge_issuances')->count();

    expect(fn () => $this->printing->print($this->admin, [$t['badge']], null))->toThrow(ValidationException::class, 'motif de la réimpression');
    expect(fn () => $this->printing->print($this->admin, [$t['badge']], 'x<y'))->toThrow(ValidationException::class);
    $plan = $this->printing->plan($this->admin, [$t['badge']]);
    expect($plan[0]['kind'])->toBe('reimpression')->and($plan[0]['printable'])->toBeTrue();

    $r = $this->printing->print($this->admin, [$t['badge']], 'Badge abîmé');
    expect($r['items'][0]['kind'])->toBe('reimpression')->and(DB::table('badge_print_items')->where('badge_id', $t['badge'])->where('kind', 'reimpression')->value('reason'))->toBe('Badge abîmé')
        ->and(DB::table('badge_print_items')->where('badge_id', $t['badge'])->count())->toBe(2)->and(DB::table('badges')->count())->toBe($badges)->and(DB::table('badge_issuances')->count())->toBe($issuances)
        ->and(DB::table('badge_assignments')->where('badge_id', $t['badge'])->count())->toBe(0);
    // l'historique d'un support ne se modifie pas, et l'émission d'impression est unique (contrainte de la base)
    expect(fn () => DB::table('badge_print_items')->where('badge_id', $t['badge'])->update(['reason' => 'Autre']))->toThrow(QueryException::class);
});

it('imprime en groupe temporaires et cartes dans un seul tirage tout ou rien, avec un rendu unique', function () {
    $a = spTemporary();
    $b = spTemporary();
    $card = spCard();
    $r = $this->printing->print($this->admin, [$a['badge'], $b['badge'], $card['badge']], null);
    expect($r['items'])->toHaveCount(3)->and(array_unique(array_column($r['items'], 'kind')))->toBe(['emission'])->and($r['views'])->toHaveCount(3)
        ->and(DB::table('badge_print_runs')->where('id', $r['run'])->value('kind'))->toBe('support')
        ->and(DB::table('badge_print_items')->where('print_run_id', $r['run'])->count())->toBe(3)
        ->and(DB::table('holder_card_prints')->where('card_id', $card['card'])->where('kind', 'emission')->count())->toBe(1);
    // tout ou rien : un support non imprimable annule l'ensemble
    app(BadgeService::class)->annuler($a['badge'], spUser('superviseur'), 'Perdu');
    $c = spTemporary();
    $items = DB::table('badge_print_items')->count();
    expect(fn () => $this->printing->print($this->admin, [$c['badge'], $a['badge']], null))->toThrow(ValidationException::class, 'annulé');
    expect(DB::table('badge_print_items')->count())->toBe($items)->and(DB::table('badge_print_items')->where('badge_id', $c['badge'])->count())->toBe(0);
});

it('imprime une carte permanente par le même contrat sans l’activer, et alimente son historique propre', function () {
    $card = spCard();
    $entitlements = DB::table('holder_entitlements')->count();
    $r = $this->printing->print($this->admin, [$card['badge']], null);
    expect($r['items'][0]['kind'])->toBe('emission')->and(DB::table('badge_print_runs')->where('id', $r['run'])->value('kind'))->toBe('permanent')
        ->and(DB::table('holder_card_prints')->where('card_id', $card['card'])->count())->toBe(1)->and(DB::table('holder_cards')->where('id', $card['card'])->value('activated_at'))->toBeNull()
        ->and(DB::table('holder_entitlements')->count())->toBe($entitlements);
    expect(fn () => $this->printing->print($this->admin, [$card['badge']], null))->toThrow(ValidationException::class);
    $again = $this->printing->print($this->admin, [$card['badge']], 'Carte abîmée');
    expect($again['items'][0]['kind'])->toBe('reimpression')->and(DB::table('holder_card_prints')->where('card_id', $card['card'])->where('kind', 'reimpression')->count())->toBe(1);
});

it('ne réimprime jamais un support annulé, une carte révoquée ou un support non émis par le contrat, et ne réactive rien', function () {
    $t = spTemporary();
    app(BadgeService::class)->annuler($t['badge'], spUser('superviseur'), 'Perdu');
    expect(fn () => $this->printing->print($this->admin, [$t['badge']], 'Réimpression'))->toThrow(ValidationException::class, 'annulé');
    expect(Badge::findOrFail($t['badge'])->status->value)->toBe('Annulé');

    $card = spCard();
    app(CardLifecycle::class)->revoke($card['holder'], 'perdue', null, $this->admin);
    expect(fn () => $this->printing->print($this->admin, [$card['badge']], 'Réimpression'))->toThrow(ValidationException::class, 'révoquée');
    expect(DB::table('holder_cards')->where('id', $card['card'])->value('status'))->toBe('perdue');

    $batch = app(BadgeLots::class)->generate($this->event['id'], $this->event['category'], 1, $this->admin);
    $stock = Badge::where('badge_batch_id', $batch->id)->firstOrFail();
    expect(fn () => $this->printing->print($this->admin, [$stock->id], null))->toThrow(ValidationException::class, 'Préparer un badge');
    expect(DB::table('badge_print_items')->whereIn('badge_id', [$t['badge'], $card['badge'], $stock->id])->count())->toBe(0);
});

it('garde imprimable une carte émise avant le registre des émissions', function () {
    $card = spCard();
    DB::table('badge_issuances')->where('badge_id', $card['badge'])->delete();
    $r = $this->printing->print($this->admin, [$card['badge']], null);
    expect($r['items'][0]['kind'])->toBe('emission');
});

it('compose le badge temporaire avec le modèle institutionnel lié à sa catégorie, respecte le refus d’impression du portrait et ne lit rien sans droit', function () {
    $t = spTemporary();
    // sans lien institutionnel : modèle neutre, catégorie de l'activité affichée
    $neutral = app(HolderCards::class)->temporaryData($t['badge']);
    expect($neutral['code'])->toBeNull()->and($neutral['label'])->toBe('Stagiaire')->and($neutral['portrait'])->toBeNull();
    // catégorie de participation liée à une catégorie institutionnelle : son modèle (CardModels) s'applique
    DB::table('categories')->where('id', $this->event['category'])->update(['institutional_code' => 'AILCT-07']);
    $linked = app(HolderCards::class)->temporaryData($t['badge']);
    expect($linked['code'])->toBe('AILCT-07')->and($linked['label'])->toBe(DB::table('institutional_categories')->where('code', 'AILCT-07')->value('label'))
        ->and($linked['modelVersion'])->toBe((int) app(CardModels::class)->forCategory('AILCT-07')['version']);
    // un portrait dont l'impression est refusée n'est jamais lu
    DB::table('visitors')->where('id', $t['visitor'])->update(['profile_photo_file' => 'profile-portraits/'.Str::uuid().'.enc', 'profile_photo_print_allowed' => false]);
    $this->actingAs($this->admin);
    expect(app(HolderCards::class)->temporaryData($t['badge'])['portrait'])->toBeNull();
    // sans droit : refus côté serveur
    expect(fn () => $this->printing->print($this->agent, [$t['badge']], null))->toThrow(HttpException::class);
    expect(fn () => $this->printing->plan($this->agent, [$t['badge']]))->toThrow(HttpException::class);
});

it('sert récapitulatif, aperçu et impression par les routes, aux seuls comptes autorisés, et l’aperçu ne trace aucun tirage', function () {
    $t = spTemporary();
    $this->actingAs($this->admin);
    $runs = DB::table('badge_print_runs')->count();
    $this->postJson('/administration/badges/impression/recapitulatif', ['badges' => [$t['badge']]])->assertOk()->assertJsonPath('supports.0.kind', 'emission')->assertJsonPath('supports.0.printable', true);
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [$t['badge']]])->assertOk()->assertJsonPath('supports.0.badge_code', $t['code']);
    expect(DB::table('badge_print_runs')->count())->toBe($runs);
    $this->post('/administration/badges/impression', ['badges' => [$t['badge']]])->assertOk()->assertSee($t['code'], false);
    expect(DB::table('badge_print_runs')->count())->toBe($runs + 1);
    $this->post('/administration/badges/impression', ['badges' => [$t['badge']]])->assertSessionHasErrors('reason');
    $this->actingAs($this->agent)->post('/administration/badges/impression', ['badges' => [$t['badge']]])->assertForbidden();
    $this->postJson('/administration/badges/impression/donnees', ['badges' => [$t['badge']]])->assertForbidden();
});

it('imprime une carte par la route d’impression unique, avec son historique et son tirage permanent', function () {
    $card = spCard();
    $this->actingAs($this->admin);
    $items = DB::table('badge_print_items')->count();
    $permanentRuns = DB::table('badge_print_runs')->where('kind', 'permanent')->count();
    $this->post('/administration/badges/impression', ['badges' => [$card['badge']]])->assertOk();
    expect(DB::table('badge_print_items')->count())->toBe($items + 1)->and(DB::table('badge_print_items')->where('badge_id', $card['badge'])->value('kind'))->toBe('emission')
        ->and(DB::table('badge_print_runs')->where('kind', 'permanent')->count())->toBe($permanentRuns + 1);
});
