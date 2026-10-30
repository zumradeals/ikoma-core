<?php

use App\Models\Device;
use App\Models\Event;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Zone;
use App\Services\BadgeArtwork;
use App\Services\BadgeLots;
use App\Services\DataLifecycle;
use App\Services\DataRetention;
use App\Services\FileDeletionQueue;
use App\Services\HostGuests;
use App\Services\Maintenance;
use App\Services\Poste\DeskRefused;
use App\Services\Poste\Devices;
use App\Services\Poste\ListSnapshot;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->admin = User::create(['name' => 'Administration fictive L33', 'email' => Str::uuid().'@exemple.test', 'password' => 'Fictif-L33!01234', 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->eventId = DemoSeeder::id('event');
    $this->personId = DemoSeeder::id('visitor0');
    $this->engine = app(DataLifecycle::class);
});

it('retire une personne inutilisée et actualise la copie tablette sans effacer sa fiche', function () {
    $device = Device::findOrFail(DemoSeeder::id('device'));
    $snapshot = app(ListSnapshot::class);
    $version = $snapshot->version($device);
    app(HostGuests::class)->withdraw($this->admin, $this->personId, true);
    expect(Visitor::findOrFail($this->personId)->withdrawn_at)->not->toBeNull()
        ->and($snapshot->version($device))->not->toBe($version)
        ->and(collect($snapshot->build($device)['visitors'])->contains('id', $this->personId))->toBeFalse();
    $this->actingAs($this->admin)->get('/administration/invites?visitor='.$this->personId)->assertOk()->assertInertia(fn (Assert $page) => $page->where('editable', false));
});

it('refuse de supprimer une personne contrôlée mais permet son retrait après sortie', function () {
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->eventId, 'visitor_id' => $this->personId, 'type' => 'validation', 'occurred_at' => now()]);
    expect($this->engine->inspect('personne', $this->personId, $this->admin)['allowed'])->toBeFalse();
    app(HostGuests::class)->withdraw($this->admin, $this->personId, true);
    expect(DB::table('access_events')->where('visitor_id', $this->personId)->exists())->toBeTrue();
});

it('protège une personne présente contre le retrait', function () {
    DB::table('visitors')->where('id', $this->personId)->update(['status' => 'present']);
    expect(fn () => app(HostGuests::class)->withdraw($this->admin, $this->personId, true))->toThrow(DeskRefused::class);
});

it('protège portrait et destination nominative', function () {
    DB::table('visitors')->where('id', $this->personId)->update(['profile_photo_file' => 'profile-portraits/'.Str::uuid().'.enc']);
    expect($this->engine->inspect('personne', $this->personId, $this->admin)['allowed'])->toBeFalse();
    DB::table('visitors')->where('id', $this->personId)->update(['profile_photo_file' => null]);
    DB::table('badges')->where('id', DemoSeeder::id('badge100'))->update(['printed_for_visitor_id' => $this->personId]);
    expect($this->engine->inspect('personne', $this->personId, $this->admin)['allowed'])->toBeFalse();
});

it('exige un aperçu frais une référence exacte et le mot de passe avant suppression', function () {
    $this->actingAs($this->admin)->get('/administration/donnees?entity=personne&id='.$this->personId)->assertOk();
    $this->post('/administration/donnees/supprimer', ['reference' => $this->personId, 'password' => 'incorrect'])->assertSessionHasErrors('password');
    expect(Visitor::find($this->personId))->not->toBeNull();
    $this->get('/administration/donnees?entity=personne&id='.$this->personId)->assertOk();
    DB::table('visitors')->where('id', $this->personId)->update(['organisation' => 'Modification concurrente fictive']);
    $this->post('/administration/donnees/supprimer', ['reference' => $this->personId, 'password' => 'Fictif-L33!01234'])->assertSessionHasErrors('operation');
    $this->get('/administration/donnees?entity=personne&id='.$this->personId)->assertOk();
    $this->post('/administration/donnees/supprimer', ['reference' => $this->personId, 'password' => 'Fictif-L33!01234'])->assertRedirect('/administration/donnees');
    expect(Visitor::find($this->personId))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'gestion.suppression_refusee')->exists())->toBeTrue();
});

it('refuse un aperçu expiré et un compte non habilité ou sans 2FA', function () {
    $this->actingAs($this->admin)->get('/administration/donnees?entity=personne&id='.$this->personId)->assertOk();
    $this->travel(11)->minutes();
    $this->post('/administration/donnees/supprimer', ['reference' => $this->personId, 'password' => 'Fictif-L33!01234'])->assertSessionHasErrors('operation');
    $this->travelBack();
    $agent = User::create(['name' => 'Agent fictif L33', 'email' => Str::uuid().'@exemple.test', 'password' => 'Fictif-L33!01234']);
    $agent->assignRole('agent_accueil');
    $this->actingAs($agent)->get('/administration/donnees')->assertForbidden();
    $this->admin->update(['two_factor_confirmed_at' => null]);
    $this->actingAs($this->admin)->get('/administration/donnees')->assertRedirect('/securite');
});

it('supprime seulement un lot inutilisé et ne réattribue jamais ses numéros', function () {
    $lots = app(BadgeLots::class);
    $batch = $lots->generate($this->eventId, DemoSeeder::id('category0'), 2, $this->admin);
    $codes = DB::table('badges')->where('badge_batch_id', $batch->id)->pluck('code')->all();
    $report = $this->engine->inspect('lot', $batch->id, $this->admin);
    expect($report['allowed'])->toBeTrue();
    $this->engine->delete('lot', $batch->id, $this->admin, $report['fingerprint']);
    expect(DB::table('retired_badge_identifiers')->where('event_id', $this->eventId)->count())->toBe(count($codes));
    $next = $lots->generate($this->eventId, DemoSeeder::id('category0'), 2, $this->admin);
    expect(DB::table('badges')->where('badge_batch_id', $next->id)->whereIn('code', $codes)->exists())->toBeFalse();
});

it('refuse les lots réservés imprimés nominatifs ou utilisés', function () {
    $batch = app(BadgeLots::class)->generate($this->eventId, DemoSeeder::id('category0'), 1, $this->admin);
    foreach ([['print_exposed_at' => now()], ['print_exposed_at' => null]] as $values) {
        DB::table('badge_batches')->where('id', $batch->id)->update($values);
        if ($values['print_exposed_at'] === null) {
            DB::table('badges')->where('badge_batch_id', $batch->id)->update(['status' => 'Annulé']);
        }
        expect($this->engine->inspect('lot', $batch->id, $this->admin)['allowed'])->toBeFalse();
    }
    expect($this->engine->inspect('lot', DemoSeeder::id('batch'), $this->admin)['allowed'])->toBeFalse();
});

it('refuse la clôture avec présence restitution ou synchronisation non résolue', function () {
    DB::table('visitors')->where('id', $this->personId)->update(['status' => 'present']);
    expect(fn () => $this->engine->transition($this->eventId, 'cloturer', $this->admin))->toThrow(ValidationException::class);
    DB::table('visitors')->where('id', $this->personId)->update(['status' => 'sorti']);
    DB::table('devices')->where('event_id', $this->eventId)->update(['enrolled_at' => now(), 'enrollment_hash' => null, 'revoked_at' => now(), 'wipe_acknowledged_at' => null]);
    expect($this->engine->closureReasons($this->eventId))->not->toBeEmpty();
    DB::table('devices')->where('event_id', $this->eventId)->update(['wipe_acknowledged_at' => now()]);
    expect($this->engine->closureReasons($this->eventId))->toBeEmpty();
});

it('clôture archive et conserve la consultation en bloquant les nouvelles admissions', function () {
    $this->engine->transition($this->eventId, 'cloturer', $this->admin);
    $this->engine->transition($this->eventId, 'archiver', $this->admin);
    $this->actingAs($this->admin)->get('/administration/evenements')->assertInertia(fn (Assert $page) => $page->has('events', 0));
    $this->get('/administration/evenements?archives=1')->assertInertia(fn (Assert $page) => $page->where('events.0.id', $this->eventId));
    expect(fn () => app(Devices::class)->issueCode(Device::findOrFail(DemoSeeder::id('device')), $this->admin))->toThrow(DeskRefused::class);
});

it('supprime un événement de préparation mais protège un événement avec activité', function () {
    expect($this->engine->inspect('evenement', $this->eventId, $this->admin)['allowed'])->toBeFalse();
    $event = Event::create(['name' => 'Préparation fictive L33', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    Zone::create(['event_id' => $event->id, 'name' => 'Accueil', 'est_accueil' => true]);
    $report = $this->engine->inspect('evenement', $event->id, $this->admin);
    $this->engine->delete('evenement', $event->id, $this->admin, $report['fingerprint']);
    expect(Event::find($event->id))->toBeNull();
});

it('protège les zones accueil et les catégories référencées', function () {
    expect($this->engine->inspect('zone', DemoSeeder::id('zone0'), $this->admin)['allowed'])->toBeFalse()
        ->and($this->engine->inspect('categorie', DemoSeeder::id('category0'), $this->admin)['allowed'])->toBeFalse();
});

it('montre les compteurs de base et sépare une demande de sa réussite', function () {
    $this->actingAs($this->admin)->get('/administration/donnees')->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.personnes_actives', 300));
    $this->post('/administration/donnees/analyser', ['operation' => 'demo'])->assertSessionHasNoErrors();
    $this->post('/administration/donnees/demander', ['operation' => 'demo', 'confirmation' => 'demo', 'password' => 'Fictif-L33!01234'])->assertSessionHasNoErrors();
    expect(DB::table('maintenance_requests')->value('status'))->toBe('en_attente')
        ->and(DB::table('visitors')->where('event_id', $this->eventId)->count())->toBe(300);
});

it('refuse une preuve de sauvegarde manquante sans supprimer les données', function () {
    expect(fn () => app(Maintenance::class)->verifyBackup('/inexistant/preuve.sha256'))->toThrow(RuntimeException::class);
    expect(DB::table('visitors')->where('event_id', $this->eventId)->count())->toBe(300);
});

it('annule aussi la file de fichiers si la transaction métier échoue', function () {
    Storage::fake('local');
    $path = 'photos/'.Str::uuid().'.enc';
    Storage::disk('local')->put($path, 'fichier fictif');
    try {
        DB::transaction(function () use ($path) {
            app(FileDeletionQueue::class)->enqueue($path);
            throw new RuntimeException('Échec fictif');
        });
    } catch (RuntimeException) {
    }
    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(DB::table('pending_file_deletions')->where('path', $path)->exists())->toBeFalse();
    app(FileDeletionQueue::class)->enqueue($path);
    expect(app(FileDeletionQueue::class)->drain())->toBe(0)->and(app(FileDeletionQueue::class)->drain())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('refuse la suppression d’un événement marqué actif même sans dépendance', function () {
    $event = Event::create(['name' => 'Événement actif fictif', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => 'actif']);
    expect($this->engine->inspect('evenement', $event->id, $this->admin)['allowed'])->toBeFalse();
});

it('refuse la clôture d’un parcours de groupe encore ouvert', function () {
    DB::table('groups')->where('id', DemoSeeder::id('group'))->update(['opened_at' => now()]);
    expect($this->engine->closureReasons($this->eventId))->not->toBeEmpty();
});

it('ne peut ni désapprouver ni raccourcir une politique de conservation approuvée', function () {
    DB::table('settings')->where('key', 'conservation_validee')->update(['value' => '1']);
    $durations = app(DataRetention::class)->settings();
    $this->actingAs($this->admin)->put('/administration/conservation', ['approved' => false, 'exportMaximum' => 10000, 'durations' => $durations])->assertSessionHasErrors('approved');
    $durations['photos'] = 1;
    $this->put('/administration/conservation', ['approved' => true, 'exportMaximum' => 10000, 'durations' => $durations])->assertSessionHasErrors('durations.photos');
});

it('modifie une préparation mais refuse une modification après contrôle', function () {
    $event = Event::create(['name' => 'Préparation fictive à corriger', 'starts_at' => now(), 'ends_at' => now()->addDay()]);
    $payload = ['name' => 'Préparation fictive corrigée', 'site' => 'Site fictif', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()];
    $this->actingAs($this->admin)->put('/administration/evenements/'.$event->id, $payload)->assertRedirect();
    expect($event->fresh()->name)->toBe($payload['name']);
    DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $this->eventId, 'visitor_id' => $this->personId, 'type' => 'validation', 'occurred_at' => now()]);
    $this->put('/administration/evenements/'.$this->eventId, $payload)->assertSessionHasErrors('name');
});

it('protège le dernier administrateur même contre un autre compte habilité à gérer les rôles', function () {
    $otherAdmins = User::role('administrateur')->where('id', '<>', $this->admin->id)->get();
    foreach ($otherAdmins as $other) {
        $other->removeRole('administrateur');
    }
    $operator = User::create(['name' => 'Opérateur fictif habilité', 'email' => Str::uuid().'@exemple.test', 'password' => 'Fictif-L33!01234', 'two_factor_confirmed_at' => now()]);
    $operator->givePermissionTo(['comptes.gerer', 'roles.attribuer']);
    $this->actingAs($operator)->put('/administration/comptes/'.$this->admin->id, ['active' => false, 'role' => 'administrateur'])->assertSessionHasErrors('active');
    expect($this->admin->fresh()->active)->toBeTrue();
});

it('protège un lot dès qu’un aperçu révèle ses véritables QR', function () {
    $batch = app(BadgeLots::class)->generate($this->eventId, DemoSeeder::id('category0'), 1, $this->admin);
    expect($this->engine->inspect('lot', $batch->id, $this->admin)['allowed'])->toBeTrue();
    app(BadgeArtwork::class)->select(['event' => $this->eventId, 'batch' => $batch->id]);
    expect($this->engine->inspect('lot', $batch->id, $this->admin)['allowed'])->toBeFalse();
});
