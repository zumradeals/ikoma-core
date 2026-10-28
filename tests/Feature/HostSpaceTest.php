<?php

use App\Models\Device;
use App\Models\User;
use App\Services\HostNotifications;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\ListSnapshot;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function hostUser(string $role = 'hote'): User
{
    $u = User::create(['name' => 'Compte fictif L10', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $u->assignRole($role);

    return $u;
}
function hostInvite(string $name, string $host, ?string $source = null): string
{
    $id = (string) Str::uuid();
    DB::table('visitors')->insert(['id' => $id, 'event_id' => DemoSeeder::id('event'), 'host_id' => $host, 'category_id' => DemoSeeder::id('category1'), 'full_name' => $name, 'organisation' => 'Atelier fictif', 'valid_from' => now()->subHour(), 'valid_until' => now()->addHour(), 'source' => $source ?? 'listé', 'status' => 'attendu', 'host_validation' => $source === 'non listé' ? 'en_attente' : null, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}
function hostInput(string $name = 'Invité fictif L10'): array
{
    return ['host_id' => DemoSeeder::id('host0'), 'full_name' => $name, 'organisation' => 'Atelier fictif', 'category_id' => DemoSeeder::id('category1'), 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 20:00'];
}
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->a = hostUser();
    $this->b = hostUser();
    $this->agent = hostUser('agent_accueil');
    DB::table('hosts')->where('id', DemoSeeder::id('host0'))->update(['user_id' => $this->a->id]);
    DB::table('hosts')->where('id', DemoSeeder::id('host1'))->update(['user_id' => $this->b->id]);
    $this->mine = hostInvite('Invité fictif personnel', DemoSeeder::id('host0'));
    $this->other = hostInvite('Invité fictif autre hôte', DemoSeeder::id('host1'));
    $this->actingAs($this->a);
});
it('isole lecture modification retrait et décisions même avec un identifiant connu', function () {
    $this->get('/hote/invites/'.$this->other)->assertForbidden();
    $this->put('/hote/invites/'.$this->other, hostInput())->assertForbidden();
    $this->delete('/hote/invites/'.$this->other)->assertForbidden();
    $unlisted = hostInvite('Non listé fictif voisin', DemoSeeder::id('host1'), 'non listé');
    $id = app(HostNotifications::class)->send($unlisted, 'non_liste', $this->agent, Device::findOrFail(DemoSeeder::id('device')));
    $this->post('/hote/notifications/'.$id.'/decision', ['accepted' => true])->assertForbidden();
    $this->post('/hote/notifications/'.$id.'/lue')->assertForbidden();
    $this->get('/hote/invites/'.$this->mine)->assertOk()->assertInertia(fn (Assert $a) => $a->component('Hote/Guest')->where('visitor.id', $this->mine));
    $this->get('/hote/notifications')->assertOk()->assertInertia(fn (Assert $a) => $a->where('items.total', 0));
    expect(DB::table('visitors')->where('id', $this->other)->value('full_name'))->toBe('Invité fictif autre hôte');
});
it('refuse tous les écrans hôte aux autres rôles et explique un compte sans fiche', function () {
    foreach (['administrateur', 'superviseur', 'agent_accueil', 'encadreur'] as $role) {
        $this->actingAs(hostUser($role));
        $this->get('/hote/invites')->assertForbidden();
        $this->get('/hote/notifications')->assertForbidden();
        $this->post('/hote/invites', hostInput())->assertForbidden();
    }
    $this->actingAs(hostUser())->get('/hote/invites')->assertOk()->assertInertia(fn (Assert $a) => $a->has('hosts', 0)->where('guests.total', 0)->has('categories', 0));
    $this->get('/hote/notifications')->assertOk()->assertInertia(fn (Assert $a) => $a->where('linked', false)->where('items.total', 0));
});
it('autorise la catégorie Visiteur seulement et nettoie la saisie comme L3', function () {
    $this->post('/hote/invites', hostInput('=Invité fictif de formule'))->assertRedirect();
    expect(DB::table('visitors')->where('full_name', "'=Invité fictif de formule")->exists())->toBeTrue();
    $this->post('/hote/invites', [...hostInput(), 'organisation' => "  Société   fictive\t  ", 'full_name' => '  Invité   fictif   nettoyé  '])->assertRedirect();
    expect(DB::table('visitors')->where('full_name', 'Invité fictif nettoyé')->value('organisation'))->toBe('Société fictive');
    $this->post('/hote/invites', [...hostInput(), 'category_id' => DemoSeeder::id('category0')])->assertSessionHasErrors('category_id');
    $this->post('/hote/invites', [...hostInput(), 'host_id' => DemoSeeder::id('host1')])->assertForbidden();
    foreach ([['full_name' => ''], ['full_name' => str_repeat('x', 161)], ['valid_until' => 'invalide'], ['valid_until' => '2027-01-01']] as $bad) {
        $this->post('/hote/invites', [...hostInput(), ...$bad])->assertSessionHasErrors();
    }
});
it('bloque le doublon normalisé et signale l’homonyme sans révéler les fiches des autres', function () {
    $input = hostInput('Invité fictif À accent');
    $this->post('/hote/invites', $input)->assertRedirect();
    $this->post('/hote/invites', [...$input, 'full_name' => 'INVITE FICTIF A ACCENT', 'organisation' => 'atelier fictif'])->assertSessionHasErrors('full_name');
    $this->post('/hote/invites', [...$input, 'organisation' => 'Autre atelier fictif'])->assertRedirect()->assertSessionHas('host_feedback', fn ($v) => str_contains($v, 'Homonyme'));
    expect(DB::table('visitors')->where('full_name', $input['full_name'])->count())->toBe(2);
});
it('incrémente la version même pour plusieurs changements pendant la même seconde', function () {
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $s = app(ListSnapshot::class);
    $v1 = $s->version($poste);
    $this->put('/hote/invites/'.$this->mine, hostInput('Invité fictif modifié A'))->assertRedirect();
    $v2 = $s->version($poste);
    expect($v2)->not->toBe($v1);
    $this->put('/hote/invites/'.$this->mine, hostInput('Invité fictif modifié B'))->assertRedirect();
    $v3 = $s->version($poste);
    expect($v3)->not->toBe($v2);
    $this->delete('/hote/invites/'.$this->mine)->assertRedirect();
    expect($s->version($poste))->not->toBe($v3);
    expect(DB::table('visitors')->where('id', $this->mine)->value('withdrawn_at'))->not->toBeNull();
    expect(collect($s->build($poste)['visitors'])->pluck('id')->all())->not->toContain($this->mine);
    expect(fn () => app(Desk::class)->validate($poste, $this->agent, $this->mine, null))->toThrow(DeskRefused::class);
});
it('interdit modification et retrait après une entrée même corrigée ou après sortie', function () {
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => DemoSeeder::id('event'), 'visitor_id' => $this->mine, 'type' => 'entree', 'occurred_at' => now()]);
    $this->put('/hote/invites/'.$this->mine, hostInput())->assertStatus(409);
    $this->delete('/hote/invites/'.$this->mine)->assertStatus(409);
    expect(DB::table('visitors')->where('id', $this->mine)->value('full_name'))->toBe('Invité fictif personnel');
});
it('notifie une arrivée une seule fois et marque uniquement ses notifications comme lues', function () {
    $n = app(HostNotifications::class);
    $id = $n->send($this->mine, 'arrivee', $this->agent);
    expect($n->send($this->mine, 'arrivee', $this->agent))->toBe($id);
    expect(DB::table('notifications')->where('visitor_id', $this->mine)->count())->toBe(1);
    $this->get('/hote/notifications')->assertInertia(fn (Assert $a) => $a->where('unread', 1)->where('items.data.0.full_name', 'Invité fictif personnel'));
    $this->post('/hote/notifications/'.$id.'/lue')->assertRedirect();
    $this->get('/hote/notifications')->assertInertia(fn (Assert $a) => $a->where('unread', 0));
});
it('accepte ou refuse une seule fois les non listés avec alerte de refus et journal sans noms', function () {
    $n = app(HostNotifications::class);
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    foreach ([true, false] as $accepted) {
        $id = app(Desk::class)->createUnlisted($poste, $this->agent, 'Non listé fictif '.($accepted ? 'accepté' : 'refusé'), null, DemoSeeder::id('host0'), DemoSeeder::id('category1'))['visitor_id'];
        $notice = DB::table('notifications')->where('visitor_id', $id)->value('id');
        $this->post('/hote/notifications/'.$notice.'/decision', ['accepted' => $accepted, 'reason' => 'Motif fictif'])->assertRedirect();
        expect(DB::table('visitors')->where('id', $id)->value('host_validation'))->toBe($accepted ? 'accepte' : 'refuse');
        $this->post('/hote/notifications/'.$notice.'/decision', ['accepted' => ! $accepted])->assertStatus(409);
        if (! $accepted) {
            expect(DB::table('alerts')->where('subject_id', $id)->where('type', 'refus')->exists())->toBeTrue();
        }
    }
    $details = DB::table('audit_logs')->where('action', 'like', 'hote.%')->orWhere('action', 'notification.creee')->pluck('details')->implode(' ');
    expect($details)->not->toContain('Non listé', 'Motif fictif', 'Compte fictif');
    $this->artisan('audit:verify')->assertSuccessful();
});
it('remonte les demandes après le délai réglable sans créer de doublon', function () {
    $id = hostInvite('Attente fictive', DemoSeeder::id('host0'), 'non listé');
    app(HostNotifications::class)->send($id, 'non_liste', $this->agent);
    expect(app(HostNotifications::class)->escalate())->toBe(0);
    $this->travel(121)->seconds();
    expect(app(HostNotifications::class)->escalate())->toBe(1);
    expect(app(HostNotifications::class)->escalate())->toBe(0);
    expect(DB::table('alerts')->where('subject_id', $id)->where('severity', 'haute')->count())->toBe(1);
});
it('enregistre l’avis hors période et empêche le réemploi après un changement de dates', function () {
    DB::table('visitors')->where('id', $this->mine)->update(['valid_from' => now()->subDays(3), 'valid_until' => now()->subDay()]);
    $n = app(HostNotifications::class);
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $n->requestAdvice($poste, $this->agent, $this->mine);
    $id = DB::table('notifications')->where('visitor_id', $this->mine)->value('id');
    $this->post('/hote/notifications/'.$id.'/decision', ['accepted' => true])->assertRedirect();
    expect($n->advice($this->mine)['state'])->toBe('autorise');
    DB::table('visitors')->where('id', $this->mine)->update(['valid_until' => now()->subHours(5)]);
    expect($n->advice($this->mine)['state'])->toBeNull();
});
it('limite le rattachement des fiches et le réglage du délai à l’administrateur', function () {
    $this->put('/administration/comptes/delai-hote', ['minutes' => 5])->assertForbidden();
    $this->put('/administration/comptes/'.$this->a->id.'/hotes', ['host_ids' => []])->assertForbidden();
    $this->actingAs(hostUser('administrateur'))->put('/administration/comptes/delai-hote', ['minutes' => 5])->assertRedirect();
    expect(DB::table('settings')->where('key', 'hote_delai_reponse_minutes')->value('value'))->toBe('5');
    $this->put('/administration/comptes/'.$this->a->id.'/hotes', ['host_ids' => [DemoSeeder::id('host1')]])->assertSessionHasErrors('host_ids');
    $this->put('/administration/comptes/'.$this->a->id.'/hotes', ['host_ids' => [DemoSeeder::id('host0'), DemoSeeder::id('host2')]])->assertRedirect();
    expect(DB::table('hosts')->where('user_id', $this->a->id)->count())->toBe(2);
    foreach (['audit_logs', 'access_events', 'identity_checks'] as $table) {
        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $right) {
            expect(DB::selectOne("SELECT has_table_privilege(current_user, '$table', '$right') AS ok")->ok)->toBeFalse();
        }
    }
});
