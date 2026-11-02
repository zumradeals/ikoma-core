<?php

use App\Models\User;
use App\Services\BadgeLots;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function consoAdmin(): User
{
    $user = User::create(['name' => 'Compte fictif consolidation', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole('administrateur');

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = consoAdmin();
    $this->actingAs($this->admin);
});

function conso_guest(string $name): array
{
    return ['event_id' => DemoSeeder::id('event'), 'full_name' => $name, 'organisation' => 'Organisation fictive', 'position' => 'Invité fictif', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1'), 'group_id' => null, 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 18:00', 'remove_photo' => false, 'photo_print_allowed' => false];
}

it('crée un invité temporaire sans jamais créer de titulaire permanent, même si le formulaire en réclame un', function () {
    $holders = DB::table('permanent_holders')->count();
    $this->post('/administration/invites', [...conso_guest('Invité Fictif Conso'), 'holder_id' => (string) Str::uuid(), 'institutional_category' => 'AILCT-01', 'is_permanent' => true])->assertSessionHasNoErrors()->assertRedirect();
    $visitor = DB::table('visitors')->where('full_name', 'Invité Fictif Conso')->first();
    expect($visitor)->not->toBeNull()->and($visitor->holder_id)->toBeNull()->and(DB::table('permanent_holders')->count())->toBe($holders)
        ->and(DB::table('visitors')->whereNotNull('holder_id')->where('event_id', DemoSeeder::id('event'))->count())->toBe(0);
});

it('garde deux parcours distincts : le formulaire d’invité refuse un titulaire du registre, le registre ne crée pas d’invité', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Titulaire Fictif Conso', 'institutional_category' => 'AILCT-01'], $this->admin);
    $guests = DB::table('visitors')->count();
    expect(DB::table('visitors')->where('holder_id', $holder)->count())->toBe(0);
    $this->post('/administration/titulaires', ['full_name' => 'Autre Titulaire Fictif', 'institutional_category' => 'AILCT-02'])->assertRedirect();
    expect(DB::table('visitors')->count())->toBe($guests);
});

it('imprime à la fois un lot de badges visiteurs et la carte d’un titulaire sans que l’un modifie l’autre', function () {
    app(InstitutionalCategories::class)->initialize($this->admin);
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 3, $this->admin);
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Titulaire Impression Fictif', 'institutional_category' => 'AILCT-03'], $this->admin);
    app(HolderCards::class)->issue($holder, $this->admin);
    $visitorsBefore = DB::table('badges')->where('event_id', DemoSeeder::id('event'))->orderBy('id')->get()->toJson();
    $options = ['event' => DemoSeeder::id('event'), 'batch' => $batch->id, 'design' => 'visiteur', 'title' => 'Site fictif', 'primary' => '#0066B3', 'accent' => '#F75C03', 'orientation' => 'paysage', 'format' => 'pvc', 'cols' => 1, 'rows' => 1, 'side' => 'recto'];
    $visitors = $this->get('/administration/badges/modeles/imprimer?'.http_build_query($options))->assertOk();
    expect(substr_count($visitors->getContent(), '<article class="access-card '))->toBe(3);
    $card = $this->post('/administration/titulaires/'.$holder.'/carte/imprimer')->assertOk();
    expect(substr_count($card->getContent(), 'class="sheet"'))->toBe(2)->and($card->getContent())->toContain('data-template="AILCT-03"')->and($card->getContent())->not->toContain('access-card');
    expect(DB::table('badges')->where('event_id', DemoSeeder::id('event'))->orderBy('id')->get()->toJson())->toBe($visitorsBefore);
});

it('ouvre les pages de chaque parcours uniquement avec les permissions existantes', function () {
    $routes = ['/administration/badges' => 'badges.gerer', '/administration/badges/modeles' => 'badges.gerer', '/administration/titulaires' => 'listes.importer', '/administration/titulaires/modeles' => 'badges.gerer', '/administration/habilitations' => 'listes.importer', '/administration/invites' => 'listes.importer', '/administration/imports' => 'listes.importer'];
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $user = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
        $user->assignRole($role);
        foreach ($routes as $path => $permission) {
            expect($user->can($permission))->toBeFalse();
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }
    foreach (array_keys($routes) as $path) {
        $this->actingAs($this->admin)->get($path)->assertOk();
    }
});
