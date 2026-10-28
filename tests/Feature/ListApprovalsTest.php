<?php

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\HostGuests;
use App\Services\Imports\ListImport;
use App\Services\Poste\ListSnapshot;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function approvalActor(string $role): User
{
    $user = User::create(['name' => 'Compte fictif A3', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function approvalImport(string $event, User $actor, int $rows = 3): string
{
    $path = tempnam(sys_get_temp_dir(), 'ikoma-a3-');
    $h = fopen($path, 'w');
    fputcsv($h, ['nom complet', 'organisation', 'hôte', 'catégorie', 'début de validité', 'fin de validité'], ';', '"', '');
    for ($i = 1; $i <= $rows; $i++) {
        fputcsv($h, ['Invité fictif A3 '.Str::random(6), 'Atelier fictif', '', 'Visiteur', '', ''], ';', '"', '');
    }
    fclose($h);
    $service = app(ListImport::class);
    $preview = $service->preview($event, $actor->id, new UploadedFile($path, 'a3-fictive.csv', null, null, true), false);

    return $service->confirm($event, $actor->id, $preview['id'])['id'];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    Storage::fake('local');
    $this->event = DemoSeeder::id('event');
    $this->admin = approvalActor('administrateur');
    $this->officer = approvalActor('officier_securite');
    $this->agent = approvalActor('agent_accueil');
});

it('importe les invités en attente d’approbation dans le profil renforcé, et approuvés dans le profil standard', function () {
    $standard = approvalImport($this->event, $this->admin);
    expect(DB::table('visitors')->where('import_batch_id', $standard)->pluck('approval_status')->unique()->all())->toBe(['approuve']);
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $strict = approvalImport($this->event, $this->admin);
    expect(DB::table('visitors')->where('import_batch_id', $strict)->pluck('approval_status')->unique()->all())->toBe(['a_approuver']);
    // les invités déjà présents du jeu de démonstration restent approuvés
    expect(DB::table('visitors')->whereNull('import_batch_id')->where('approval_status', '<>', 'approuve')->count())->toBe(0);
});

it('réserve l’approbation à l’officier de sécurité : ni l’administrateur ni les autres rôles', function () {
    foreach (['administrateur', 'superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $this->actingAs(approvalActor($role))->get('/autorite/listes')->assertForbidden();
        $this->post('/autorite/listes/hotes/approuver', ['event' => $this->event])->assertForbidden();
    }
    $this->actingAs($this->officer)->get('/autorite/listes')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Autorite/Listes')->where('batches', []));
});

it('bloque l’accueil d’un invité non approuvé, partout, jusqu’à l’approbation de l’officier', function () {
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $batch = approvalImport($this->event, $this->admin, 2);
    $ids = DB::table('visitors')->where('import_batch_id', $batch)->pluck('id')->all();
    $admin = $this->admin;
    $lots = app(BadgeLots::class);
    $badge = Badge::where('badge_batch_id', $lots->generate($this->event, DB::table('visitors')->where('id', $ids[0])->value('category_id'), 5, $admin)->id)->orderBy('code')->first();
    // le service des badges refuse
    expect(fn () => app(BadgeService::class)->associer($badge, $ids[0], $this->agent, DemoSeeder::id('device')))->toThrow(BadgeRefused::class, 'approbation de l’officier de sécurité');
    // la copie de la tablette porte le statut
    $poste = Device::findOrFail(DemoSeeder::id('device'));
    $row = collect(app(ListSnapshot::class)->build($poste)['visitors'])->firstWhere('id', $ids[0]);
    expect($row['approval'])->toBe('a_approuver');
    // le poste en ligne refuse aussi la validation et l'identité
    DB::table('devices')->where('id', $poste->id)->update(['token_hash' => hash('sha256', str_repeat('7', 64))]);
    $headers = ['X-Device-Token' => str_repeat('7', 64), 'X-Operation-Id' => (string) Str::uuid(), 'Accept' => 'application/json'];
    $this->actingAs($this->agent)->postJson('/poste/api/valider', ['visitor_id' => $ids[0]], $headers)->assertStatus(422)->assertJson(['code' => 'non_approuve']);

    // l'officier approuve toute la liste : l'accueil redevient possible
    $this->actingAs($this->officer)->get('/autorite/listes')->assertInertia(fn (Assert $p) => $p->has('batches', 1)->where('batches.0.count', 2)->where('selected', $batch)->has('visitors', 2));
    $this->post("/autorite/listes/$batch/approuver", ['event' => $this->event])->assertRedirect()->assertSessionHas('status', '2 invités approuvés.');
    expect(DB::table('visitors')->whereIn('id', $ids)->pluck('approval_status')->unique()->all())->toBe(['approuve'])->and(DB::table('visitors')->where('id', $ids[0])->value('approved_by'))->toBe($this->officer->id);
    expect(app(BadgeService::class)->associer($badge, $ids[0], $this->agent, DemoSeeder::id('device'), 'superviseur')->badge_id)->toBe($badge->id);
});

it('permet de refuser une sélection, et un invité refusé reste bloqué avec un message propre', function () {
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $batch = approvalImport($this->event, $this->admin, 3);
    $ids = DB::table('visitors')->where('import_batch_id', $batch)->pluck('id')->all();
    $this->actingAs($this->officer)->post("/autorite/listes/$batch/refuser", ['event' => $this->event, 'ids' => [$ids[0]]])->assertRedirect()->assertSessionHas('status', '1 invité refusé.');
    expect(DB::table('visitors')->where('id', $ids[0])->value('approval_status'))->toBe('refuse');
    expect(DB::table('visitors')->whereIn('id', [$ids[1], $ids[2]])->pluck('approval_status')->unique()->all())->toBe(['a_approuver']);
    $this->post("/autorite/listes/$batch/approuver", ['event' => $this->event, 'ids' => [$ids[1]]])->assertSessionHas('status', '1 invité approuvé.');
    // le refusé n'est plus proposé à l'approbation
    $this->get('/autorite/listes')->assertInertia(fn (Assert $p) => $p->where('batches.0.count', 1));
    // des identifiants d'un autre lot sont ignorés : rien n'est approuvé hors du lot demandé
    $other = approvalImport($this->event, $this->admin, 1);
    $foreign = DB::table('visitors')->where('import_batch_id', $other)->value('id');
    $this->post("/autorite/listes/$batch/approuver", ['event' => $this->event, 'ids' => [$foreign]])->assertSessionHas('status', '0 invité approuvé.');
    expect(DB::table('visitors')->where('id', $foreign)->value('approval_status'))->toBe('a_approuver');
});

it('journalise les décisions par nombres seulement, jamais par nom, et fait suivre les tablettes', function () {
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $batch = approvalImport($this->event, $this->admin, 2);
    $revision = DB::table('events')->where('id', $this->event)->value('list_revision');
    $this->actingAs($this->officer)->post("/autorite/listes/$batch/approuver", ['event' => $this->event]);
    $log = DB::table('audit_logs')->where('action', 'liste.approuvee')->orderByDesc('id')->first();
    expect(json_decode($log->details, true))->toMatchArray(['event_id' => $this->event, 'lot' => $batch, 'nombre' => 2, 'tout_le_lot' => true])->and($log->actor_id)->toBe($this->officer->id)
        ->and($log->details)->not->toContain('Invité fictif');
    expect(DB::table('events')->where('id', $this->event)->value('list_revision'))->toBeGreaterThan($revision);
});

it('met en attente l’invité ajouté par un hôte, et redemande l’approbation quand l’hôte le modifie, dans le profil renforcé seulement', function () {
    $host = approvalActor('hote');
    DB::table('hosts')->where('id', DemoSeeder::id('host0'))->update(['user_id' => $host->id]);
    $input = ['host_id' => DemoSeeder::id('host0'), 'full_name' => 'Invité fictif hôte A3', 'organisation' => 'Atelier fictif', 'category_id' => DemoSeeder::id('category1'), 'valid_from' => '2027-01-10 08:00', 'valid_until' => '2027-01-10 18:00'];
    $guests = app(HostGuests::class);
    // profil standard : approuvé d'office
    $first = $guests->save($host, $input);
    expect(DB::table('visitors')->where('id', $first['id'])->value('approval_status'))->toBe('approuve');
    // profil renforcé : en attente dans le lot « hôtes », puis approuvé par l'officier
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'renforce']);
    $second = $guests->save($host, ['full_name' => 'Autre invité fictif A3'] + $input);
    expect(DB::table('visitors')->where('id', $second['id'])->value('approval_status'))->toBe('a_approuver');
    $this->actingAs($this->officer)->get('/autorite/listes')->assertInertia(fn (Assert $p) => $p->where('batches.0.key', 'hotes')->where('batches.0.count', 1));
    $this->post('/autorite/listes/hotes/approuver', ['event' => $this->event]);
    expect(DB::table('visitors')->where('id', $second['id'])->value('approval_status'))->toBe('approuve');
    // l'hôte change le nom d'un invité approuvé : il faut le réapprouver
    $guests->save($host, ['full_name' => 'Nom modifié fictif A3'] + $input, $second['id']);
    expect(DB::table('visitors')->where('id', $second['id'])->value('approval_status'))->toBe('a_approuver')->and(DB::table('visitors')->where('id', $second['id'])->value('approved_by'))->toBeNull();
    // profil standard : une modification ne réclame rien
    DB::table('settings')->where('key', 'profil_securite')->update(['value' => 'standard']);
    DB::table('visitors')->where('id', $first['id'])->update(['approval_status' => 'approuve']);
    $guests->save($host, ['full_name' => 'Nom modifié standard A3'] + $input, $first['id']);
    expect(DB::table('visitors')->where('id', $first['id'])->value('approval_status'))->toBe('approuve');
});
