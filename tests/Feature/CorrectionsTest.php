<?php

use App\Models\User;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use App\Services\Presence;
use App\Services\SiteMovements;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

function coUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif corrections', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->visitor = DB::table('visitors as v')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('access_events as e')->whereColumn('e.visitor_id', 'v.id'))->first();
    $this->entry = (string) Str::uuid();
    DB::table('access_events')->insert(['id' => $this->entry, 'event_id' => $this->visitor->event_id, 'visitor_id' => $this->visitor->id, 'badge_id' => null, 'actor_id' => null, 'type' => 'entree', 'checkpoint' => 'Accueil fictif',
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
});

it('permet au superviseur de corriger une entrée d’activité avec un motif, la présence est recalculée et l’original reste', function () {
    expect(app(Presence::class)->visitorPresent($this->visitor->id))->toBeTrue();
    $this->actingAs(coUser('superviseur'));
    $this->post('/pilotage/mouvements/'.$this->entry.'/correction', ['reason' => 'Entrée saisie par erreur'])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(Presence::class)->visitorPresent($this->visitor->id))->toBeFalse();
    expect(DB::table('access_events')->where('id', $this->entry)->exists())->toBeTrue();
    expect(DB::table('access_events')->where('corrige_id', $this->entry)->where('type', 'correction')->count())->toBe(1);
    expect(DB::table('audit_logs')->where('action', 'presence.corrigee')->count())->toBe(1);

    $this->post('/pilotage/mouvements/'.$this->entry.'/correction', ['reason' => 'Encore'])->assertRedirect()->assertSessionHasErrors('correction');
    expect(session('errors')->first('correction'))->toContain('déjà');
});

it('permet au superviseur de corriger un mouvement du site, avec les mêmes garanties', function () {
    $admin = coUser('administrateur');
    app(InstitutionalCategories::class)->initialize($admin);
    $holder = app(PermanentHolders::class)->create(['full_name' => 'Personne Fictive Correction', 'institutional_category' => 'AILCT-01'], $admin);
    $movement = app(SiteMovements::class)->record($holder, 'entree', 'Entrée du site fictive', $admin);
    expect(app(SiteMovements::class)->count())->toBe(1);

    $this->actingAs(coUser('superviseur'));
    $this->get('/pilotage/mouvements')->assertOk()->assertInertia(fn ($p) => $p->component('Pilotage/Mouvements')->where('canCorrect', true)->has('siteMovements', 1)->where('siteMovements.0.id', $movement));
    $this->post('/pilotage/mouvements/'.$movement.'/correction', ['reason' => 'Entrée du site saisie par erreur'])->assertRedirect()->assertSessionHasNoErrors();
    expect(app(SiteMovements::class)->count())->toBe(0);
    expect(DB::table('audit_logs')->where('action', 'presence_site.corrigee')->count())->toBe(1);
    $this->post('/pilotage/mouvements/'.$movement.'/correction', ['reason' => 'Encore'])->assertSessionHasErrors('correction');
});

it('exige un motif, refuse un mouvement inconnu et un contrôle (pas une entrée ni une sortie)', function () {
    $this->actingAs(coUser('superviseur'));
    $this->post('/pilotage/mouvements/'.$this->entry.'/correction', ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post('/pilotage/mouvements/'.Str::uuid().'/correction', ['reason' => 'Motif'])->assertNotFound();
    $control = (string) Str::uuid();
    DB::table('access_events')->insert(['id' => $control, 'event_id' => $this->visitor->event_id, 'visitor_id' => $this->visitor->id, 'badge_id' => null, 'actor_id' => null, 'type' => 'controle', 'checkpoint' => 'Accueil fictif',
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->post('/pilotage/mouvements/'.$control.'/correction', ['reason' => 'Motif'])->assertSessionHasErrors('correction');
});

it('réserve la correction au superviseur : ni l’administrateur ni l’agent ne peuvent corriger', function () {
    foreach (['administrateur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(coUser($role));
        $this->post('/pilotage/mouvements/'.$this->entry.'/correction', ['reason' => 'Motif'])->assertForbidden();
    }
    expect(DB::table('access_events')->where('corrige_id', $this->entry)->exists())->toBeFalse();
    $this->actingAs(coUser('administrateur'));
    $this->get('/pilotage/mouvements')->assertOk()->assertInertia(fn ($p) => $p->where('canCorrect', false));
});
