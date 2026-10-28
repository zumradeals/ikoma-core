<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Approvals;
use App\Services\Poste\ListSnapshot;
use App\Services\Watchlist;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function watchActor(string $role): User
{
    $user = User::create(['name' => 'Compte fictif A2', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->event = DemoSeeder::id('event');
    $this->admin = watchActor('administrateur');
    $this->admin2 = watchActor('administrateur');
    $this->officer = watchActor('officier_securite');
    $this->agent = watchActor('agent_accueil');
    $this->visitor = DB::table('visitors')->where('event_id', $this->event)->whereNull('withdrawn_at')->orderBy('id')->first(['id', 'full_name']);
    DB::table('visitors')->where('id', $this->visitor->id)->update(['full_name' => 'Éloïse Traoré-Fictive']);
});

it('réserve la liste d’interdits à l’officier de sécurité', function () {
    foreach (['administrateur', 'superviseur', 'agent_accueil', 'hote'] as $role) {
        $this->actingAs(watchActor($role))->get('/autorite/interdits')->assertForbidden();
        $this->post('/autorite/interdits', ['full_name' => 'Quelqu’un', 'reason' => 'Motif fictif'])->assertForbidden();
    }
    $this->actingAs($this->officer)->get('/autorite/interdits')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Autorite/Interdits')->where('entries', []));
});

it('reconnaît la personne malgré les accents, les majuscules et l’ordre des mots, sans signaler un autre nom', function () {
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'TRAORE fictive ELOISE', 'reason' => 'Motif fictif confidentiel'])->assertRedirect()->assertSessionHas('status');
    expect(app(Watchlist::class)->isFlagged($this->visitor->id))->toBeTrue();
    DB::table('visitors')->where('id', $this->visitor->id)->update(['full_name' => 'Éloïse Traoré']); // un nom différent n'est pas signalé
    expect(app(Watchlist::class)->isFlagged($this->visitor->id))->toBeFalse();
    $this->post('/autorite/interdits', ['full_name' => 'traore fictive eloise', 'reason' => 'Motif fictif'])->assertSessionHasErrors('full_name'); // déjà sur la liste
});

it('montre « personne signalée » à la tablette sans jamais lui envoyer le motif ni le nom de la liste', function () {
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'Éloïse Traoré-Fictive', 'reason' => 'Motif fictif confidentiel']);
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $snapshot = app(ListSnapshot::class)->build($poste);
    expect(collect($snapshot['visitors'])->firstWhere('id', $this->visitor->id)['watch'])->toBeTrue();
    expect(json_encode($snapshot))->not->toContain('Motif fictif confidentiel')->and(collect($snapshot['visitors'])->where('watch', true))->toHaveCount(1);
});

it('empêche l’agent de valider une personne signalée sans l’avis du superviseur, et prévient le superviseur', function () {
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'Éloïse Traoré-Fictive', 'reason' => 'Motif fictif']);
    DB::table('devices')->where('id', DemoSeeder::id('device'))->update(['token_hash' => hash('sha256', str_repeat('8', 64))]);
    $headers = fn () => ['X-Device-Token' => str_repeat('8', 64), 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
    $this->actingAs($this->agent)->postJson('/poste/api/valider', ['visitor_id' => $this->visitor->id], $headers())->assertStatus(422)->assertJson(['code' => 'personne_signalee']);
    $alert = DB::table('alerts')->where('type', 'personne_signalee')->where('subject_id', $this->visitor->id)->first();
    expect($alert)->not->toBeNull()->and($alert->severity)->toBe('critique')->and($alert->message)->not->toContain('Traoré')->not->toContain('Motif');
    // avec l'avis du superviseur, l'accueil continue (identité et photo restent exigées)
    $this->postJson('/poste/api/valider', ['visitor_id' => $this->visitor->id, 'avis' => 'superviseur'], $headers())->assertStatus(422)->assertJson(['code' => 'identite_requise']);
});

it('alerte à l’ajout si un invité déjà listé correspond, et à l’import ou à l’ajout par un hôte, sans nom dans l’alerte ni le journal', function () {
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'Éloïse Traoré-Fictive', 'reason' => 'Motif fictif']);
    $a = DB::table('alerts')->where('type', 'personne_signalee')->where('event_id', $this->event)->first();
    expect($a)->not->toBeNull()->and($a->message)->toContain('1 personne')->and($a->message)->not->toContain('Traoré');
    $logs = DB::table('audit_logs')->where('action', 'interdit.ajoute')->get()->map(fn ($l) => json_encode($l))->implode('');
    expect($logs)->not->toContain('Traoré')->not->toContain('Motif');
    expect(app(Watchlist::class)->checkNew($this->event, null, $this->visitor->id))->toBe(1);
});

it('lève l’interdiction tout de suite dans le profil standard', function () {
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'Éloïse Traoré-Fictive', 'reason' => 'Motif fictif']);
    $entry = DB::table('watchlist_entries')->whereNull('lifted_at')->value('id');
    $this->delete("/autorite/interdits/$entry")->assertSessionHas('status', 'Interdiction levée.');
    expect(app(Watchlist::class)->isFlagged($this->visitor->id))->toBeFalse()->and(DB::table('watchlist_entries')->where('id', $entry)->value('lifted_by'))->toBe($this->officer->id);
});

it('exige la validation d’un administrateur pour lever une interdiction dans le profil renforcé', function () {
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $this->actingAs($this->officer)->post('/autorite/interdits', ['full_name' => 'Éloïse Traoré-Fictive', 'reason' => 'Motif fictif']);
    $entry = DB::table('watchlist_entries')->whereNull('lifted_at')->value('id');
    $this->delete("/autorite/interdits/$entry")->assertSessionHas('status', 'Demande envoyée : un administrateur doit valider la levée.');
    expect(app(Watchlist::class)->isFlagged($this->visitor->id))->toBeTrue(); // pas encore levée
    $approval = DB::table('approvals')->where('action', 'interdit.lever')->value('id');
    // l'officier ne peut pas valider lui-même (pas administrateur) ; un administrateur le peut
    $this->actingAs($this->officer)->post("/administration/approbations/$approval/approuver")->assertForbidden();
    $this->actingAs($this->admin)->post("/administration/approbations/$approval/approuver")->assertRedirect();
    expect(app(Watchlist::class)->isFlagged($this->visitor->id))->toBeFalse()->and(DB::table('audit_logs')->where('action', 'interdit.leve')->exists())->toBeTrue();
    expect(app(Approvals::class)->pendingCount())->toBe(0);
});
