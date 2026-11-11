<?php

use App\Models\User;
use App\Services\DemoLab;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

function demoGuardAdmin(): User
{
    $user = User::create(['name' => 'Compte fictif démonstration', 'email' => Str::uuid().'@exemple.test', 'password' => 'Motdepasse-De-Test-12345!']);
    $user->assignRole('administrateur');
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = demoGuardAdmin();
});

it('ouvre le laboratoire à l’administrateur, y compris sur le serveur d’essai (APP_ENV=production), et garde les confirmations', function () {
    foreach (['production', 'demo', 'local', 'testing'] as $env) {
        $this->app['env'] = $env;
        expect(DemoLab::available())->toBeTrue();
        DemoLab::authorize($this->admin);
    }
    $this->app['env'] = 'production';
    $this->actingAs($this->admin)->withSession(['_token' => 'jeton']);
    $this->get('/administration/identite')->assertInertia(fn (Assert $p) => $p->where('demoLabAvailable', true));
    $this->get('/administration/demonstration')->assertOk();
    // Sans la phrase de confirmation, rien n'est effacé.
    $before = [DB::table('events')->count(), DB::table('users')->count()];
    $this->post('/administration/demonstration/remettre-a-zero', ['_token' => 'jeton', 'confirmation' => 'non', 'password' => 'Motdepasse-De-Test-12345!', 'acknowledge' => true, 'clear_users' => true])->assertSessionHasErrors('confirmation');
    expect([DB::table('events')->count(), DB::table('users')->count()])->toBe($before);
});

it('refuse le laboratoire à tout compte qui n’est pas administrateur actif avec le droit de gérer les comptes', function () {
    $this->app['env'] = 'production';
    $agent = User::create(['name' => 'Agent fictif', 'email' => Str::uuid().'@exemple.test', 'password' => 'Motdepasse-De-Test-12345!']);
    $agent->assignRole('agent_accueil');
    $lab = app(DemoLab::class);
    expect(fn () => $lab->generate('evenement', 20, $agent))->toThrow(HttpException::class)
        ->and(fn () => $lab->clearBusiness($agent, true))->toThrow(HttpException::class)
        ->and(fn () => DemoLab::authorize($agent))->toThrow(HttpException::class);
    $this->actingAs($agent)->get('/administration/demonstration')->assertForbidden();
});

it('génère des personnes permanentes fictives avec leurs cartes, puis la remise à zéro les efface avec leur historique', function () {
    $this->actingAs($this->admin)->withSession(['_token' => 'jeton']);
    $this->post('/administration/demonstration/generer', ['_token' => 'jeton', 'scenario' => 'permanents', 'quantity' => 20])->assertRedirect();
    expect(DB::table('permanent_holders')->count())->toBe(20)->and(DB::table('holder_cards')->count())->toBe(20)
        ->and(DB::table('holder_cards')->whereNotNull('activated_at')->count())->toBe(15)
        ->and(DB::table('permanent_holders')->whereNull('first_name')->count())->toBe(0)
        ->and(DB::table('permanent_holders')->distinct('institutional_category')->count('institutional_category'))->toBe(8);
    // Une impression tracée (registre en ajout seul) fait aussi partie de ce qui s'efface.
    $holder = (string) DB::table('permanent_holders')->value('id');
    app(HolderCards::class)->recordPrint($holder, null, $this->admin);
    DB::table('badge_print_runs')->insert(['id' => (string) Str::uuid(), 'kind' => 'permanent', 'event_id' => null, 'operator_id' => $this->admin->id, 'nature' => 'officiel', 'format' => 'cr80', 'face' => 'recto', 'badge_count' => 1, 'options' => '{}', 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->post('/administration/demonstration/remettre-a-zero', ['_token' => 'jeton', 'confirmation' => 'REMETTRE A ZERO IKOMA', 'password' => 'Motdepasse-De-Test-12345!', 'acknowledge' => true, 'clear_users' => false])->assertRedirect();
    foreach (['permanent_holders', 'holder_cards', 'holder_card_prints', 'badge_print_runs', 'holder_entitlements', 'events', 'badges'] as $table) {
        expect(DB::table($table)->count(), $table)->toBe(0);
    }
    expect(DB::table('users')->where('id', $this->admin->id)->exists())->toBeTrue();
    // Les protections « ajout seul » sont rétablies après la remise à zéro.
    app(InstitutionalCategories::class)->initialize($this->admin);
    $again = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive '.Str::random(6), 'institutional_category' => 'AILCT-01'], $this->admin);
    app(HolderCards::class)->issue($again, $this->admin);
    expect(fn () => DB::table('holder_cards')->where('holder_id', $again)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('permanent_holders')->where('id', $again)->delete())->toThrow(QueryException::class);
});

it('ne lit aucun réglage de démonstration depuis un fichier technique et réserve le laboratoire à l’administrateur actif', function () {
    $other = User::create(['name' => 'Compte fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $other->assignRole('superviseur');
    $this->actingAs($other)->get('/administration/demonstration')->assertForbidden();
    auth()->logout();
    $this->get('/administration/demonstration')->assertRedirect('/login');
    // La double authentification n'est plus exigée (désactivée pour tous les comptes) : l'administrateur actif suffit.
    $noSecondFactor = demoGuardAdmin();
    $noSecondFactor->update(['two_factor_confirmed_at' => null]);
    $this->actingAs($noSecondFactor)->get('/administration/demonstration')->assertOk();
});
