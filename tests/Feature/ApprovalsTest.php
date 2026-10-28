<?php

use App\Models\User;
use App\Services\Approvals;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function approver(string $role = 'administrateur'): User
{
    $user = User::create(['name' => 'Compte fictif validation', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // Les administrateurs laissés par d'autres suites ne comptent pas : seuls ceux de ce test sont actifs.
    DB::table('users')->update(['active' => false]);
    $this->adminA = approver();
    $this->adminB = approver();
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $this->password = Str::password(24).'aA1!';
    $this->newAccount = fn (string $role) => ['name' => 'Nouveau fictif', 'email' => Str::uuid().'@exemple.test', 'password' => $this->password, 'role' => $role];
});

it('crée directement un compte privilégié dans le profil standard, et un compte de terrain même en profil renforcé', function () {
    $body = ($this->newAccount)('superviseur');
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'standard']);
    $this->actingAs($this->adminA)->post('/administration/comptes', $body)->assertRedirect();
    expect(User::where('email', $body['email'])->first()?->hasRole('superviseur'))->toBeTrue()->and(DB::table('approvals')->count())->toBe(0);
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $field = ($this->newAccount)('agent_accueil');
    $this->post('/administration/comptes', $field)->assertRedirect();
    expect(User::where('email', $field['email'])->first()?->hasRole('agent_accueil'))->toBeTrue()->and(DB::table('approvals')->count())->toBe(0);
});

it('met la création d’un compte privilégié en attente d’un second administrateur, sans mot de passe en clair', function () {
    $body = ($this->newAccount)('superviseur');
    $this->actingAs($this->adminA)->post('/administration/comptes', $body)->assertRedirect()->assertSessionHas('status');
    expect(User::where('email', $body['email'])->exists())->toBeFalse();
    $row = DB::table('approvals')->first();
    expect($row->action)->toBe('compte.creer')->and($row->requested_by)->toBe($this->adminA->id)->and($row->decision)->toBeNull()->and($row->summary)->toContain('superviseur');
    expect($row->payload)->not->toContain($this->password)->and(Hash::check($this->password, json_decode($row->payload, true)['password_hash']))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'approbation.demandee')->where('actor_id', $this->adminA->id)->exists())->toBeTrue();
});

it('exécute la demande quand un autre administrateur la valide, et refuse l’auteur lui-même', function () {
    $body = ($this->newAccount)('superviseur');
    $this->actingAs($this->adminA)->post('/administration/comptes', $body);
    $id = DB::table('approvals')->value('id');
    $this->post("/administration/approbations/$id/approuver")->assertSessionHasErrors('approbation'); // l'auteur ne peut pas valider
    expect(User::where('email', $body['email'])->exists())->toBeFalse();

    $this->actingAs($this->adminB)->post("/administration/approbations/$id/approuver")->assertRedirect()->assertSessionHasNoErrors();
    $created = User::where('email', $body['email'])->firstOrFail();
    expect($created->hasRole('superviseur'))->toBeTrue()->and(Hash::check($this->password, $created->password))->toBeTrue();
    $row = DB::table('approvals')->first();
    expect($row->decision)->toBe('approuve')->and($row->decided_by)->toBe($this->adminB->id)->and($row->executed_at)->not->toBeNull();
    $log = DB::table('audit_logs')->where('action', 'compte.cree')->orderByDesc('id')->first();
    expect($log->actor_id)->toBe($this->adminB->id)->and(json_decode($log->details, true))->toMatchArray(['role' => 'superviseur', 'demande_par' => $this->adminA->id, 'double_validation' => true]);
    // une seconde validation de la même demande est refusée
    $this->post("/administration/approbations/$id/approuver")->assertSessionHasErrors('approbation');
});

it('permet de refuser une demande, et refuse une demande expirée', function () {
    $this->actingAs($this->adminA)->post('/administration/comptes', ($this->newAccount)('superviseur'));
    $id = DB::table('approvals')->value('id');
    $this->actingAs($this->adminB)->post("/administration/approbations/$id/refuser")->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('approvals')->first()->decision)->toBe('refuse')->and(DB::table('approvals')->first()->executed_at)->toBeNull()->and(User::where('name', 'Nouveau fictif')->exists())->toBeFalse();
    $this->actingAs($this->adminA)->post('/administration/comptes', ($this->newAccount)('administrateur'));
    $late = DB::table('approvals')->whereNull('decision')->value('id');
    DB::table('approvals')->where('id', $late)->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($this->adminB)->post("/administration/approbations/$late/approuver")->assertSessionHasErrors('approbation');
    expect(Approvals::TTL_HOURS)->toBe(24);
});

it('exige la validation d’un second administrateur pour un changement de rôle privilégié ou la réactivation d’un compte privilégié', function () {
    $agent = approver('agent_accueil');
    $boss = approver('superviseur');
    $this->actingAs($this->adminA);
    $this->put("/administration/comptes/{$agent->id}", ['active' => true, 'role' => 'superviseur'])->assertRedirect()->assertSessionHas('status');
    expect($agent->fresh()->hasRole('agent_accueil'))->toBeTrue(); // pas encore changé
    $this->put("/administration/comptes/{$boss->id}", ['active' => true, 'role' => 'hote'])->assertRedirect()->assertSessionHas('status');
    expect($boss->fresh()->hasRole('superviseur'))->toBeTrue();
    // un changement entre rôles de terrain reste direct
    $this->put("/administration/comptes/{$agent->id}", ['active' => true, 'role' => 'hote'])->assertRedirect();
    expect($agent->fresh()->hasRole('hote'))->toBeTrue()->and(DB::table('approvals')->count())->toBe(2);
    // la désactivation d'un compte privilégié reste directe (mesure d'urgence) ; sa réactivation demande une validation
    $this->put("/administration/comptes/{$boss->id}", ['active' => false, 'role' => 'superviseur'])->assertRedirect();
    expect($boss->fresh()->active)->toBeFalse();
    $this->put("/administration/comptes/{$boss->id}", ['active' => true, 'role' => 'superviseur'])->assertRedirect()->assertSessionHas('status');
    expect($boss->fresh()->active)->toBeFalse()->and(DB::table('approvals')->whereNull('decision')->count())->toBe(3);
    // le second administrateur valide la première demande
    $id = DB::table('approvals')->orderBy('created_at')->value('id');
    $this->actingAs($this->adminB)->post("/administration/approbations/$id/approuver")->assertSessionHasNoErrors();
    expect($agent->fresh()->hasRole('superviseur'))->toBeTrue();
});

it('exige la validation d’un second administrateur pour revenir au profil standard, mais pas pour passer au profil renforcé', function () {
    $this->actingAs($this->adminA)->put('/administration/profil-securite', ['profile' => 'standard'])->assertRedirect()->assertSessionHas('status');
    expect(DB::table('settings')->where('key', 'profil_securite')->value('value'))->toBe('renforce');
    $id = DB::table('approvals')->where('action', 'securite.profil_standard')->value('id');
    $this->actingAs($this->adminB)->post("/administration/approbations/$id/approuver")->assertSessionHasNoErrors();
    expect(DB::table('settings')->where('key', 'profil_securite')->value('value'))->toBe('standard');
    $this->put('/administration/profil-securite', ['profile' => 'renforce'])->assertRedirect();
    expect(DB::table('settings')->where('key', 'profil_securite')->value('value'))->toBe('renforce');
});

it('ne peut pas s’appliquer avec un seul administrateur actif : la création directe reste possible et le tableau le signale', function () {
    DB::table('users')->where('id', $this->adminB->id)->update(['active' => false]);
    expect(app(Approvals::class)->required())->toBeFalse()->and(app(Approvals::class)->soleAdministrator())->toBeTrue();
    $titles = fn () => collect($this->get('/administration')->viewData('page')['props']['watch'])->pluck('title')->all();
    $body = ($this->newAccount)('administrateur');
    $this->actingAs($this->adminA);
    expect($titles())->toContain('Un seul administrateur actif');
    $this->post('/administration/comptes', $body)->assertRedirect();
    expect(User::where('email', $body['email'])->exists())->toBeTrue()->and(DB::table('approvals')->count())->toBe(0);
    expect($titles())->not->toContain('Un seul administrateur actif'); // dès le deuxième administrateur, la double validation s'applique
});

it('réserve les validations à l’administrateur et les signale sur son tableau', function () {
    $this->actingAs($this->adminA)->post('/administration/comptes', ($this->newAccount)('superviseur'));
    foreach (['superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $this->actingAs(approver($role))->get('/administration/approbations')->assertForbidden();
    }
    $this->actingAs($this->adminB)->get('/administration/approbations')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Approbations')->has('pending', 1)->where('pending.0.mine', false)->where('soleAdministrator', false));
    $this->actingAs($this->adminA)->get('/administration/approbations')->assertInertia(fn (Assert $p) => $p->where('pending.0.mine', true));
    $this->get('/administration')->assertInertia(fn (Assert $p) => expect(collect($p->toArray()['props']['watch'])->pluck('title')->all())->toContain('1 demande de validation en attente'));
});

it('interdit en base qu’une demande soit validée par son auteur', function () {
    $id = (string) Str::uuid();
    DB::table('approvals')->insert(['id' => $id, 'action' => 'compte.creer', 'payload' => '{}', 'summary' => 'x', 'requested_by' => $this->adminA->id, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('approvals')->where('id', $id)->update(['decided_by' => $this->adminA->id, 'decided_at' => now(), 'decision' => 'approuve'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('approvals')->where('id', $id)->update(['executed_at' => now()])))->toThrow(QueryException::class);
});
