<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\Groups;
use App\Services\Poste\DeskRefused;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function groupUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif L9', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

/** Réserve les badges B-001 à B-0NN au groupe du seed. */
function reserveForGroup(Group $group, int $count): void
{
    DB::table('badges')->where('event_id', DemoSeeder::id('event'))->update(['reserved_device_id' => null, 'category_id' => DemoSeeder::id('category3')]);
    app(BadgeLots::class)->reserve(DemoSeeder::id('batch'), 1, $count, $group, test()->admin);
}

function groupMembersOf(): array
{
    return DB::table('visitors')->where('group_id', DemoSeeder::id('group'))->orderBy('full_name')->pluck('id')->all();
}

function refusal(callable $fn): ?DeskRefused
{
    try {
        $fn();
    } catch (DeskRefused $e) {
        return $e;
    }

    return null;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = groupUser('administrateur');
    $this->agent = groupUser('agent_accueil');
    $this->encadreur = groupUser('encadreur');
    $this->group = Group::query()->findOrFail(DemoSeeder::id('group'));
    DB::table('groups')->where('id', $this->group->id)->update(['supervisor_id' => $this->encadreur->id]);
    $this->groups = app(Groups::class);
    $this->device = Device::query()->findOrFail(DemoSeeder::id('device'));
    $this->members = groupMembersOf();
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
});

it('ne rattache un stagiaire qu\'à un seul groupe', function () {
    $other = $this->groups->create($this->group->event_id, 'Autre groupe', 'Car 2', null, $this->admin);
    $free = DemoSeeder::id('visitor0');
    $this->groups->attach($this->group->id, $free, $this->admin);
    expect(refusal(fn () => $this->groups->attach($other, $free, $this->admin))?->code_)->toBe('deja_dans_un_groupe')
        ->and(refusal(fn () => $this->groups->attach($this->group->id, $free, $this->admin))?->code_)->toBe('deja_dans_un_groupe');
    expect(DB::table('group_members')->where('visitor_id', $free)->count())->toBe(1);
    expect(fn () => DB::table('group_members')->insert(['id' => (string) Str::uuid(), 'group_id' => $other, 'visitor_id' => $free, 'created_at' => now(), 'updated_at' => now()]))->toThrow(Exception::class);
});

it('n\'accepte comme encadreur qu\'un compte de ce rôle', function () {
    expect(refusal(fn () => $this->groups->designate($this->group->id, $this->agent->id, $this->admin))?->code_)->toBe('encadreur_invalide');
});

it('mode encadreur : le contrôle exige la confirmation de l\'encadreur', function () {
    [$v] = $this->members;
    expect($this->groups->mode())->toBe('encadreur')
        ->and(refusal(fn () => $this->groups->check($this->agent, $this->group->id, $v))?->code_)->toBe('confirmation_encadreur_requise');
    $this->groups->confirm($this->encadreur, $this->group->id, $v);
    expect($this->groups->check($this->agent, $this->group->id, $v)['mode'])->toBe('encadreur');
    expect(DB::table('identity_checks')->where('visitor_id', $v)->exists())->toBeFalse();
});

it('mode identité : la pièce cochée est enregistrée dans identity_checks', function () {
    DB::table('settings')->where('key', 'controle_stagiaires')->update(['value' => 'identite']);
    [$v] = $this->members;
    expect(refusal(fn () => $this->groups->check($this->agent, $this->group->id, $v, false))?->code_)->toBe('identite_non_cochee');
    $this->groups->check($this->agent, $this->group->id, $v, true);
    expect(DB::table('identity_checks')->where('visitor_id', $v)->count())->toBe(1);
    $this->groups->check($this->agent, $this->group->id, $v, true); // rejeu sans effet
    expect(DB::table('identity_checks')->where('visitor_id', $v)->count())->toBe(1);
});

it('un encadreur ne voit ni ne confirme jamais un autre groupe', function () {
    $other = $this->groups->create($this->group->event_id, 'Groupe voisin', 'Car 2', groupUser('encadreur')->id, $this->admin);
    $stranger = DemoSeeder::id('visitor1');
    $this->groups->attach($other, $stranger, $this->admin);

    $this->actingAs($this->encadreur)->get('/mon-groupe')->assertOk()->assertInertia(fn ($page) => $page->has('groups', 1)->where('groups.0.id', $this->group->id)->where('state.group.id', $this->group->id));
    $this->actingAs($this->encadreur)->post("/mon-groupe/{$stranger}/confirmer")->assertForbidden();
    expect(DB::table('group_members')->where('visitor_id', $stranger)->value('confirmed_at'))->toBeNull();
    // un compte sans le rôle encadreur est refusé par la permission
    $this->actingAs($this->agent)->get('/mon-groupe')->assertForbidden();
});

it('l\'encadreur confirme chaque stagiaire de son groupe, de façon journalisée et rejouable', function () {
    [$v] = $this->members;
    $this->actingAs($this->encadreur)->post("/mon-groupe/{$v}/confirmer")->assertRedirect();
    $this->actingAs($this->encadreur)->post("/mon-groupe/{$v}/confirmer")->assertRedirect();
    expect(DB::table('group_members')->where('visitor_id', $v)->value('confirmed_by'))->toBe($this->encadreur->id)
        ->and(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'groupe.confirme_par_encadreur')->count())->toBe(1);
});

it('refuse un badge hors du lot réservé au groupe', function () {
    reserveForGroup($this->group, 40);
    [$v] = $this->members;
    $this->groups->confirm($this->encadreur, $this->group->id, $v);
    $this->groups->check($this->agent, $this->group->id, $v);
    $outside = Badge::query()->where('code', 'B-300')->firstOrFail();
    expect(refusal(fn () => $this->groups->deliver($this->device, $this->agent, $this->group->id, $v, $outside->code))?->code_)->toBe('hors_lot_groupe');
    // et le parcours standard refuse un badge réservé à un groupe pour un visiteur extérieur
    $reserved = Badge::query()->where('code', 'B-001')->firstOrFail();
    expect(fn () => app(BadgeService::class)->associer($reserved, DemoSeeder::id('visitor5'), $this->agent, $this->device->id))->toThrow(BadgeRefused::class);
});

it('remet un badge du lot, exige le contrôle préalable et reste idempotent', function () {
    reserveForGroup($this->group, 40);
    [$v] = $this->members;
    expect(refusal(fn () => $this->groups->deliver($this->device, $this->agent, $this->group->id, $v, 'B-001'))?->code_)->toBe('controle_requis');
    $this->groups->confirm($this->encadreur, $this->group->id, $v);
    $this->groups->check($this->agent, $this->group->id, $v);
    $this->groups->deliver($this->device, $this->agent, $this->group->id, $v, 'B-001');
    $this->groups->deliver($this->device, $this->agent, $this->group->id, $v, 'B-001');
    expect(DB::table('badge_assignments')->where('visitor_id', $v)->whereNull('returned_at')->count())->toBe(1)
        ->and($this->groups->state($this->group->id)['progress']['badged'])->toBe(1);
});

/** Remet un badge à $count stagiaires du groupe. */
function deliverTo(int $count): void
{
    $members = test()->members;
    foreach (array_slice($members, 0, $count) as $i => $v) {
        test()->groups->confirm(test()->encadreur, test()->group->id, $v);
        test()->groups->check(test()->agent, test()->group->id, $v);
        test()->groups->deliver(test()->device, test()->agent, test()->group->id, $v, 'B-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT));
    }
}

it('signale les absents au départ du car sans nom dans les alertes ni le journal', function () {
    reserveForGroup($this->group, 40);
    deliverTo(38);
    $result = $this->groups->depart($this->agent, $this->group->id);
    expect($result)->toBe(['boarded' => 38, 'absent' => 2]);
    expect($this->groups->depart($this->agent, $this->group->id))->toBe(['boarded' => 38, 'absent' => 2]);
    expect(DB::table('alerts')->where('type', 'groupe_incomplet')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'groupe.absent')->count())->toBe(2)
        ->and(refusal(fn () => $this->groups->deliver($this->device, $this->agent, $this->group->id, $this->members[39], 'B-040'))?->code_)->toBe('car_ferme');
    $names = DB::table('visitors')->where('group_id', $this->group->id)->pluck('full_name')->all();
    $text = DB::table('audit_logs')->where('id', '>', $this->auditStart)->get()->map(fn ($r) => json_encode($r))->implode(' ').DB::table('alerts')->get()->map(fn ($r) => json_encode($r))->implode(' ');
    foreach (array_unique($names) as $name) {
        expect($text)->not->toContain($name);
    }
});

it('contrôle l\'arrivée : scan, statut présent, événement d\'entrée et écarts signalés', function () {
    reserveForGroup($this->group, 40);
    deliverTo(10);
    // un 11e badge remis après coup n'est pas possible : on simule un badge scanné sans embarquement avant le départ
    $this->groups->depart($this->agent, $this->group->id);
    $codes = DB::table('badges')->whereIn('code', array_map(fn ($i) => 'B-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), range(1, 10)))->orderBy('code')->pluck('code')->all();
    foreach (array_slice($codes, 0, 9) as $code) {
        expect($this->groups->arrive($this->device, $this->agent, $code)['ecart'])->toBeNull();
    }
    expect($this->groups->arrive($this->device, $this->agent, $codes[0])['already'])->toBeTrue();
    $result = $this->groups->finishArrival($this->agent, $this->group->id);
    expect($result)->toBe(['arrived' => 9, 'missing' => 1]);
    $this->groups->finishArrival($this->agent, $this->group->id);
    expect(DB::table('alerts')->where('type', 'groupe_ecart')->count())->toBe(1)
        ->and(DB::table('alerts')->where('type', 'car_arrive')->count())->toBe(1)
        ->and(DB::table('access_events')->where('type', 'entree')->whereRaw("details->>'arrivee_groupe' = 'true'")->count())->toBe(9)
        ->and(DB::table('visitors')->where('group_id', $this->group->id)->where('status', 'present')->count())->toBe(9);
});

it('signale un badge scanné à l\'arrivée alors que la personne n\'était pas embarquée', function () {
    reserveForGroup($this->group, 40);
    deliverTo(3);
    DB::table('groups')->where('id', $this->group->id)->update(['departed_at' => now()]);
    $result = $this->groups->arrive($this->device, $this->agent, 'B-002');
    expect($result['ecart'])->toBe('non_embarque')
        ->and(DB::table('alerts')->where('type', 'groupe_ecart')->count())->toBe(1);
});

it('journalise le parcours avec des identifiants et des nombres uniquement', function () {
    reserveForGroup($this->group, 40);
    deliverTo(2);
    $this->groups->depart($this->agent, $this->group->id);
    $actions = DB::table('audit_logs')->where('id', '>', $this->auditStart)->pluck('action')->unique()->all();
    expect($actions)->toContain('groupe.ouvert', 'groupe.badge_remis', 'groupe.embarque', 'groupe.absent', 'groupe.confirme_par_encadreur');
});

it('enregistre la règle de contrôle depuis l\'administration et l\'affiche au rechargement', function () {
    $this->actingAs($this->admin)->put('/administration/groupes/reglage', ['mode' => 'identite'])->assertRedirect();
    expect(DB::table('settings')->where('key', 'controle_stagiaires')->value('value'))->toBe('identite');
    $this->actingAs($this->admin)->get('/administration/groupes')->assertOk()->assertInertia(fn ($page) => $page->where('mode', 'identite'));
    $this->actingAs($this->admin)->put('/administration/groupes/reglage', ['mode' => 'nimporte'])->assertSessionHasErrors('mode');
    expect(DB::table('audit_logs')->where('action', 'groupe.reglage_modifie')->count())->toBe(1);
});
