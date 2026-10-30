<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Models\Visitor;
use App\Services\BadgeLots;
use App\Services\BadgePreparation;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\Imports\ListAnalysis;
use App\Services\Poste\ListSnapshot;
use App\Services\ProfilePortrait;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseTransactions::class);

function manualInput(string $name = 'Personne fictive atelier'): array
{
    return ['event_id' => DemoSeeder::id('event'), 'full_name' => $name, 'organisation' => 'Atelier fictif', 'position' => 'Stagiaire fictif', 'host_id' => DemoSeeder::id('host0'), 'category_id' => DemoSeeder::id('category1'), 'group_id' => null, 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 20:00', 'remove_photo' => false, 'photo_print_allowed' => false];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    Storage::fake('local');
    $this->admin = User::create(['name' => 'Admin fictif portraits', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('administrateur');
    $this->actingAs($this->admin);
    $this->printData = ['event' => DemoSeeder::id('event'), 'design' => 'vip', 'title' => 'Site fictif', 'primary' => '#0B3C6B', 'accent' => '#C9962B', 'orientation' => 'paysage', 'format' => 'pvc', 'cols' => 2, 'rows' => 5, 'side' => 'recto'];
});

it('affiche dès l’ouverture la liste paginée de l’événement choisi, sans mélanger les événements', function () {
    $this->get('/administration/invites?event_id='.DemoSeeder::id('event'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Visitors')->where('eventId', DemoSeeder::id('event'))
            ->has('list.data')->where('searchQuery', ''));

    $event = DemoSeeder::id('event');
    $now = now();
    foreach (range(1, 28) as $i) {
        DB::table('visitors')->insert(['id' => (string) Str::uuid(), 'event_id' => $event,
            'full_name' => 'Nouveau fictif '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'category_id' => DemoSeeder::id('category1'), 'valid_from' => $now,
            'valid_until' => $now->copy()->addDay(), 'source' => 'listé',
            'status' => 'attendu', 'created_at' => $now, 'updated_at' => $now]);
    }

    $this->get('/administration/invites?event_id='.$event.'&page=2')
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Visitors')->where('eventId', $event)
            ->where('list.current_page', 2)->where('list.total', fn ($total) => $total > 20)
            ->has('list.data'));
});

it('crée sans photo et synchronise la fiche sans assimiler le profil au contrôle d’identité', function () {
    $this->post('/administration/invites', manualInput())->assertRedirect();
    $person = Visitor::where('full_name', 'Personne fictive atelier')->firstOrFail();
    $snapshot = app(ListSnapshot::class)->build(Device::findOrFail(DemoSeeder::id('device')));
    $entry = collect($snapshot['visitors'])->firstWhere('id', $person->id);
    expect($entry)->not->toBeNull()->and($entry['photo_done'])->toBeFalse()->and($entry['identity_done'])->toBeFalse();
    expect($person->profile_photo_file)->toBeNull();
});

it('recadre, réencode et chiffre le portrait ; permet remplacement et retrait avant contrôle', function () {
    $this->post('/administration/invites', [...manualInput(), 'photo' => UploadedFile::fake()->image('portrait.png', 800, 800), 'photo_print_allowed' => true])->assertRedirect();
    $person = Visitor::where('full_name', 'Personne fictive atelier')->firstOrFail();
    $old = $person->profile_photo_file;
    $encrypted = Storage::disk('local')->get($old);
    $bytes = Crypt::decryptString($encrypted);
    expect(getimagesizefromstring($bytes))->toMatchArray([0 => 360, 1 => 480, 2 => IMAGETYPE_JPEG]);
    expect($encrypted)->not->toBe($bytes)->and(DB::table('photos')->where('visitor_id', $person->id)->count())->toBe(0);
    $this->get('/administration/invites/'.$person->id.'/portrait')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post('/administration/invites/'.$person->id, [...manualInput(), 'photo' => UploadedFile::fake()->image('nouveau.jpg', 640, 480)])->assertRedirect();
    expect($person->fresh()->profile_photo_file)->not->toBe($old)->and($person->fresh()->profile_photo_print_allowed)->toBeFalse();
    $this->post('/administration/invites/'.$person->id, [...manualInput(), 'remove_photo' => true])->assertRedirect();
    expect($person->fresh()->profile_photo_file)->toBeNull();
    $this->get('/administration/invites/'.$person->id.'/portrait')->assertNotFound();
});

it('refuse les contenus non image et retire les métadonnées du portrait', function () {
    $this->postJson('/administration/invites', [...manualInput(), 'photo' => UploadedFile::fake()->createWithContent('portrait.jpg', '<script>secret</script>')])->assertUnprocessable();
    $file = UploadedFile::fake()->image('portrait.jpg', 360, 480);
    $jpeg = $file->getContent();
    $comment = 'EXIF SECRET FICTIF';
    $dirty = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);
    expect(app(ProfilePortrait::class)->clean($dirty))->not->toContain($comment);
});

it('partage la normalisation, refuse les doublons et avertit les homonymes comme l’import', function () {
    $this->post('/administration/invites', manualInput())->assertRedirect();
    $this->postJson('/administration/invites', manualInput('  Personne fictive atelier  '))->assertUnprocessable()->assertJsonValidationErrors('full_name');
    $this->post('/administration/invites', [...manualInput(), 'organisation' => 'Autre organisation'])->assertRedirect()->assertSessionHas('visitor_feedback', fn ($value) => str_contains($value, 'Homonyme'));
    $path = tempnam(sys_get_temp_dir(), 'ikoma-csv');
    file_put_contents($path, "nom complet;organisation;hote;categorie\nPersonne fictive atelier;Atelier fictif;Hôte fictif 1;Visiteur\n");
    try {
        $analysis = app(ListAnalysis::class)->analyse($path, 'csv', DemoSeeder::id('event'), false);
        expect($analysis['summary']['blocked'])->toBe(1);
    } finally {
        unlink($path);
    }
});

it('protège les historiques et les portraits contre les rôles non habilités', function () {
    $this->post('/administration/invites', [...manualInput(), 'photo' => UploadedFile::fake()->image('p.jpg')])->assertRedirect();
    $person = Visitor::where('full_name', 'Personne fictive atelier')->firstOrFail();
    DB::table('access_events')->insert(['id' => Str::uuid(), 'event_id' => $person->event_id, 'visitor_id' => $person->id, 'type' => 'controle', 'occurred_at' => now()]);
    $this->postJson('/administration/invites/'.$person->id, manualInput('Historique falsifié'))->assertStatus(409);
    expect($person->fresh()->full_name)->toBe('Personne fictive atelier');
    foreach (['hote', 'superviseur', 'agent_accueil'] as $role) {
        $user = User::create(['name' => 'Rôle fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->get('/administration/invites/'.$person->id.'/portrait')->assertForbidden();
        $this->postJson('/administration/invites', manualInput())->assertForbidden();
        $this->postJson('/administration/badges/modeles/personnes', ['event' => $person->event_id, 'batch' => DemoSeeder::id('batch')])->assertForbidden();
    }
});

it('prépare sans activation et imprime avec ou sans portrait, jamais dans le mode anonyme', function () {
    $this->post('/administration/invites', [...manualInput(), 'photo' => UploadedFile::fake()->image('portrait.png'), 'photo_print_allowed' => true])->assertRedirect();
    $person = Visitor::where('full_name', 'Personne fictive atelier')->firstOrFail();
    $batch = app(BadgeLots::class)->generate($person->event_id, $person->category_id, 1, $this->admin);
    $badge = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->firstOrFail();
    $token = $badge->qr_token;
    $number = (int) substr($badge->code, 2);
    $this->postJson('/administration/badges/modeles/preparer', ['event' => $person->event_id, 'batch' => $batch->id, 'from' => $number, 'to' => $number, 'visitor_ids' => [$person->id], 'personal_allowed' => true])->assertOk();
    expect($badge->fresh()->status->value)->toBe('Disponible')->and(DB::table('badge_assignments')->where('badge_id', $badge->id)->exists())->toBeFalse();
    expect(app(BadgeService::class)->controler($token)->valid)->toBeFalse();
    $options = [...$this->printData, 'batch' => $batch->id, 'from' => $number, 'to' => $number];
    $anonymous = $this->postJson('/administration/badges/modeles/apercu', $options)->assertOk()->json('html');
    expect($anonymous)->not->toContain($person->full_name)->not->toContain('data:image/jpeg');
    $personal = [...$options, 'mode' => 'nominatif', 'personal_allowed' => true];
    $without = $this->postJson('/administration/badges/modeles/apercu', $personal)->assertOk()->json('html');
    expect($without)->toContain($person->full_name)->not->toContain('data:image/jpeg');
    $with = $this->postJson('/administration/badges/modeles/apercu', [...$personal, 'show_photo' => true])->assertOk()->json('html');
    expect($with)->toContain('data:image/jpeg');
    $this->get('/administration/badges/modeles/imprimer?'.http_build_query([...$personal, 'show_photo' => true]))->assertOk();
    expect($badge->fresh()->qr_token)->toBe($token)->and($badge->fresh()->status->value)->toBe('Disponible');
    $agent = User::create(['name' => 'Agent fictif', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $agent->assignRole('agent_accueil');
    expect(fn () => app(BadgeService::class)->associer($badge, DemoSeeder::id('visitor1'), $agent))->toThrow(BadgeRefused::class, 'autre personne');
    app(BadgeService::class)->associer($badge, $person, $agent);
    expect(app(BadgeService::class)->controler($token)->valid)->toBeTrue();
});

it('refuse une préparation sur stock réservé ou sur une catégorie différente, sans écriture partielle', function () {
    $this->post('/administration/invites', manualInput())->assertRedirect();
    $person = Visitor::where('full_name', 'Personne fictive atelier')->firstOrFail();
    expect(fn () => app(BadgePreparation::class)->prepare($person->event_id, DemoSeeder::id('batch'), 1, 1, [$person->id], $this->admin))->toThrow(ValidationException::class);
    expect(Badge::whereNotNull('printed_for_visitor_id')->count())->toBe(0);
});

it('actualise la version du snapshot lorsque des badges sont réservés à une tablette', function () {
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $lot = app(BadgeLots::class)->generate($poste->event_id, DemoSeeder::id('category1'), 1, $this->admin);
    $badge = Badge::where('badge_batch_id', $lot->id)->firstOrFail();
    $snapshot = app(ListSnapshot::class);
    $avant = $snapshot->version($poste);
    DB::table('badges')->where('id', $badge->id)->update(['reserved_device_id' => $poste->id, 'updated_at' => now()]);
    $apres = $snapshot->version($poste);
    expect($apres)->not->toBe($avant);
    $ligne = collect($snapshot->build($poste)['badges'])->firstWhere('code', $badge->code);
    expect($ligne)->not->toBeNull()->and($ligne['mine'])->toBeTrue();
});

it('prépare et imprime 200 destinations nominatives sans activation ni modification des QR', function () {
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 200, $this->admin);
    $ids = [];
    for ($i = 0; $i < 200; $i++) {
        $id = (string) Str::uuid();
        $ids[] = $id;
        DB::table('visitors')->insert(['id' => $id, 'event_id' => DemoSeeder::id('event'), 'full_name' => 'Nominatif fictif '.$i, 'category_id' => DemoSeeder::id('category1'), 'valid_from' => '2027-01-10 08:00:00', 'valid_until' => '2027-01-10 20:00:00', 'source' => 'listé', 'status' => 'attendu', 'created_at' => now(), 'updated_at' => now()]);
    }
    $tokens = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->pluck('qr_token')->all();
    $badges = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get();
    $from = (int) substr($badges->first()->code, 2);
    $to = (int) substr($badges->last()->code, 2);
    $this->postJson('/administration/badges/modeles/preparer', ['event' => DemoSeeder::id('event'), 'batch' => $batch->id, 'from' => $from, 'to' => $to, 'visitor_ids' => $ids, 'personal_allowed' => true])->assertOk();
    $options = [...$this->printData, 'batch' => $batch->id, 'format' => 'a4', 'mode' => 'nominatif', 'personal_allowed' => true];
    $response = $this->get('/administration/badges/modeles/imprimer?'.http_build_query($options))->assertOk();
    expect(substr_count($response->getContent(), '<article class="access-card '))->toBe(200)
        ->and(Badge::where('badge_batch_id', $batch->id)->where('status', 'Disponible')->count())->toBe(200)
        ->and(Badge::where('badge_batch_id', $batch->id)->orderBy('code')->pluck('qr_token')->all())->toBe($tokens);
});
