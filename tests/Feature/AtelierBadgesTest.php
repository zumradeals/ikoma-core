<?php

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgePreparation;
use App\Services\QrSvg;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseTransactions::class);

function atelierAccount(string $role): User
{
    $user = User::create(['name' => 'Compte fictif atelier', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
    $this->admin = atelierAccount('administrateur');
    DB::table('settings')->where('key', 'badge_marge_pourcent')->update(['value' => '0']);
    $this->batch = app(BadgeLots::class)->generate(DemoSeeder::id('event'), DemoSeeder::id('category1'), 20, $this->admin);
    $this->visual = ['event' => DemoSeeder::id('event'), 'design' => 'visiteur', 'title' => 'Site fictif IKOMA', 'primary' => '#0066B3', 'accent' => '#F75C03', 'orientation' => 'paysage'];
    $this->selection = [...$this->visual, 'batch' => $this->batch->id, 'format' => 'pvc', 'cols' => 2, 'rows' => 5, 'side' => 'recto', 'mode' => 'reutilisable'];
    $this->n0 = (int) DB::table('badges')->where('badge_batch_id', $this->batch->id)->selectRaw('min(substring(code from 3)::integer) as n')->value('n');
    $this->tokens = DB::table('badges')->where('badge_batch_id', $this->batch->id)->pluck('qr_token')->all();
    $this->actingAs($this->admin);
});

function runRow(string $id): object
{
    return DB::table('badge_print_runs')->where('id', $id)->firstOrFail();
}

function businessSnapshot(): string
{
    return json_encode([DB::table('badges')->orderBy('id')->get(), DB::table('badge_assignments')->orderBy('id')->get(), DB::table('holder_cards')->count(), DB::table('holder_entitlements')->count()], JSON_THROW_ON_ERROR);
}

// --- Aperçus ---------------------------------------------------------------------------------------------------------------------

it('prévisualise un modèle avec un badge d’exemple : aucun vrai QR, aucun lot lu, aucune exposition, aucune trace', function () {
    $before = businessSnapshot();
    DB::enableQueryLog();
    $response = $this->postJson('/administration/atelier/apercu-fictif', $this->visual)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    $html = $response->json('recto').$response->json('verso');
    expect($response->json('recto'))->toContain('EXEMPLE · SANS VALEUR')->and($response->json('recto'))->toContain('B-000')->and($response->json('verso'))->toContain('Consignes de visite');
    foreach ($this->tokens as $token) {
        expect($html)->not->toContain($token);
    }
    expect($response->json('recto'))->toContain(app(QrSvg::class)->render(QrSvg::payload(str_repeat('0', 64)), 'QR d’exemple, sans valeur'))
        ->and($sql)->not->toContain('qr_token')->and($sql)->not->toContain('badge_batches')
        ->and(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->toBeNull()
        ->and(DB::table('badge_print_runs')->count())->toBe(0)->and(businessSnapshot())->toBe($before);
});

it('marque le lot comme exposé AVANT de rendre le vrai QR d’un aperçu réel', function () {
    expect(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->toBeNull();
    $response = $this->postJson('/administration/atelier/apercu', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 0])->assertOk();
    $first = Badge::where('badge_batch_id', $this->batch->id)->orderBy('code')->firstOrFail();
    expect(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->not->toBeNull()
        ->and($response->json('html'))->toContain(app(QrSvg::class)->render(QrSvg::payload($first->qr_token), 'QR du badge '.$first->code));
});

// --- Tirages et traçabilité en trois états ------------------------------------------------------------------------------------

it('trace un tirage en trois états distincts sans jamais toucher aux états métier', function () {
    $business = businessSnapshot();
    $plan = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 19])->assertOk()->json();
    $run = runRow($plan['run']);
    expect($run->nature)->toBe('officiel')->and($run->badge_count)->toBe(20)->and($run->format)->toBe('pvc')->and($run->operator_id)->toBe($this->admin->id)
        ->and(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->not->toBeNull();
    $states = fn () => DB::table('badge_print_events')->where('print_run_id', $plan['run'])->orderBy('created_at')->pluck('event_type')->all();
    expect($states())->toBe(['generated']);

    // Confirmer avant d'avoir demandé l'impression est refusé.
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 20, 'spoiled' => 0])->assertUnprocessable()->assertJsonValidationErrors('printed');

    $document = $this->get($plan['document_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(substr_count($document->getContent(), '<article class="access-card '))->toBe(20)->and($document->getContent())->toContain('data-run="'.$plan['run'].'"')->and(substr_count($document->getContent(), '<script'))->toBe(1);
    // Rouvrir le document ne crée ni nouveau tirage ni nouvel état.
    expect(DB::table('badge_print_runs')->count())->toBe(1)->and($states())->toBe(['generated']);

    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/demande')->assertOk()->assertJsonPath('state', 'requested');
    expect($states())->toBe(['generated', 'requested']);
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 21, 'spoiled' => 0])->assertUnprocessable();
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 19, 'spoiled' => 1, 'note' => 'Un badge gâché fictif'])->assertOk()->assertJsonPath('state', 'operator_confirmed');
    expect($states())->toBe(['generated', 'requested', 'operator_confirmed']);
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 20, 'spoiled' => 0])->assertUnprocessable();

    $actions = DB::table('audit_logs')->whereIn('action', ['atelier.document_genere', 'atelier.impression_demandee', 'atelier.impression_confirmee', 'badge.impression_preparee'])->orderBy('id')->pluck('action')->all();
    expect($actions)->toContain('atelier.document_genere')->toContain('atelier.impression_demandee')->toContain('atelier.impression_confirmee')->toContain('badge.impression_preparee');
    $details = DB::table('audit_logs')->where('action', 'atelier.impression_confirmee')->value('details');
    expect($details)->not->toContain('gâché fictif');
    // Imprimer ne change ni statut, ni association, ni réservation, ni carte, ni habilitation.
    expect(businessSnapshot())->toBe($business);
});

it('ne permet ni de modifier ni de supprimer une trace de tirage', function () {
    $plan = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 1])->assertOk()->json();
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/demande')->assertOk();
    foreach (['badge_print_runs' => 'id', 'badge_print_events' => 'print_run_id'] as $table => $key) {
        // Un savepoint par tentative : une erreur SQL n'abandonne pas la transaction du test.
        expect(fn () => DB::transaction(fn () => DB::table($table)->where($key, $plan['run'])->update(['updated_at' => now()])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->where($key, $plan['run'])->delete()))->toThrow(QueryException::class);
    }
    expect(DB::table('badge_print_events')->where('print_run_id', $plan['run'])->count())->toBe(2);
});

it('imprime un essai marqué avec un badge d’exemple, sans lot, sans exposition et sans vrai QR', function () {
    $plan = $this->postJson('/administration/atelier/tirages', [...$this->visual, 'essai' => true, 'format' => 'pvc', 'cols' => 2, 'rows' => 5])->assertOk()->json();
    $run = runRow($plan['run']);
    expect($run->nature)->toBe('essai')->and($run->batch_id)->toBeNull()->and($run->badge_count)->toBe(2);
    $html = $this->get($plan['document_url'])->assertOk()->getContent();
    expect($html)->toContain('ESSAI')->and($html)->toContain('EXEMPLE · SANS VALEUR')->and(substr_count($html, '<article class="access-card '))->toBe(2);
    foreach ($this->tokens as $token) {
        expect($html)->not->toContain($token);
    }
    expect(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->toBeNull();
    // Un essai ne se transforme jamais en tirage officiel : sa nature reste « essai » après confirmation.
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/demande')->assertOk();
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 2, 'spoiled' => 0])->assertOk();
    expect(runRow($plan['run'])->nature)->toBe('essai');
});

it('refuse un essai avec un lot et un tirage officiel sans lot (cohérence en base)', function () {
    expect(fn () => DB::table('badge_print_runs')->insert(['id' => (string) Str::uuid(), 'kind' => 'visiteur', 'event_id' => DemoSeeder::id('event'), 'batch_id' => $this->batch->id, 'operator_id' => $this->admin->id, 'format' => 'pvc', 'face' => 'recto', 'nature' => 'essai', 'badge_count' => 1, 'options' => '{}', 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

// --- Étiquettes, alignement, anciens liens ----------------------------------------------------------------------------------------

it('traite les étiquettes A4 comme un format de l’Atelier : même suivi, même marquage, même rendu', function () {
    $plan = $this->postJson('/administration/atelier/tirages', ['format' => 'etiquettes', 'batch' => $this->batch->id, 'cols' => 2, 'rows' => 3])->assertOk()->json();
    $run = runRow($plan['run']);
    expect($run->format)->toBe('etiquettes')->and($run->badge_count)->toBe(20)->and($run->nature)->toBe('officiel')->and(DB::table('badge_batches')->where('id', $this->batch->id)->value('print_exposed_at'))->not->toBeNull();
    $response = $this->get($plan['document_url'])->assertOk();
    $html = $response->getContent();
    expect(substr_count($html, '<article class="label"'))->toBe(20)->and(substr_count($html, '<script'))->toBe(1)->and($html)->toContain('data-run="'.$plan['run'].'"')->and($html)->toContain('repeat(2, 1fr)');
    foreach ($this->tokens as $token) {
        expect($html)->not->toContain($token);
    }
    $this->postJson('/administration/atelier/tirages', ['format' => 'etiquettes', 'batch' => $this->batch->id, 'cols' => 9])->assertUnprocessable()->assertJsonValidationErrors('cols');
});

it('conserve les anciens liens : l’atelier et les étiquettes redirigent vers l’Atelier avec la sélection', function () {
    $this->get('/administration/badges/modeles')->assertRedirect('/administration/atelier');
    $this->get('/administration/badges/etiquettes?batch='.$this->batch->id.'&cols=2&rows=3&from=5&to=9')->assertRedirect('/administration/atelier?format=etiquettes&batch='.$this->batch->id.'&from=5&to=9&cols=2&rows=3');
    $this->get('/administration/badges/etiquettes?batch='.$this->batch->id.'&cols=9')->assertSessionHasErrors('cols');
    $this->get('/administration/atelier?format=etiquettes&batch='.$this->batch->id.'&cols=2&rows=3')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Atelier')->where('initial.format', 'etiquettes')->where('initial.batch', $this->batch->id)->where('initial.event', DemoSeeder::id('event'))->has('runs'));
    // L'ancienne page d'impression directe reste utilisable et est désormais tracée.
    $this->get('/administration/badges/modeles/imprimer?'.http_build_query([...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 2]))->assertOk();
    expect(DB::table('badge_print_runs')->count())->toBe(1);
});

it('fournit une page d’essai d’alignement commune, sans QR, sans donnée personnelle et sans trace', function () {
    foreach (['pvc', 'a4'] as $format) {
        $html = $this->get('/administration/atelier/alignement?format='.$format.'&orientation=portrait&cols=2&rows=2')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        expect($html)->toContain('aucun QR')->and($html)->not->toContain('<svg');
    }
    $this->get('/administration/atelier/alignement?format=a4&orientation=paysage&cols=3&rows=5')->assertSessionHasErrors('cols');
    expect(DB::table('badge_print_runs')->count())->toBe(0);
});

// --- Motif de réimpression (DC-3) -----------------------------------------------------------------------------------------------

it('exige un motif à chaque nouvelle génération d’un badge nominatif, même si le tirage précédent n’est pas confirmé', function () {
    $person = DB::table('visitors')->where('event_id', DemoSeeder::id('event'))->whereNull('withdrawn_at')->whereNull('holder_id')->whereNotIn('id', DB::table('badge_assignments')->whereNull('returned_at')->whereNull('cancelled_at')->pluck('visitor_id'))->first();
    $batch = app(BadgeLots::class)->generate($person->event_id, $person->category_id, 1, $this->admin);
    $number = (int) substr(Badge::where('badge_batch_id', $batch->id)->firstOrFail()->code, 2);
    app(BadgePreparation::class)->prepare($person->event_id, $batch->id, $number, $number, [$person->id], $this->admin);
    $nominatif = [...$this->selection, 'batch' => $batch->id, 'from' => $number, 'to' => $number, 'mode' => 'nominatif', 'personal_allowed' => true];

    $first = $this->postJson('/administration/atelier/tirages', $nominatif)->assertOk()->json();
    expect(runRow($first['run'])->reason)->toBeNull();
    // Le premier tirage n'est ni demandé ni confirmé : la nouvelle génération exige quand même un motif.
    $this->postJson('/administration/atelier/tirages', $nominatif)->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson('/administration/atelier/tirages', [...$nominatif, 'reason' => "Bac\x07 invalide"])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $second = $this->postJson('/administration/atelier/tirages', [...$nominatif, 'reason' => 'Imprimante bloquée fictive'])->assertOk()->json();
    expect(runRow($second['run'])->reason)->toBe('Imprimante bloquée fictive')->and(DB::table('audit_logs')->where('action', 'atelier.document_genere')->orderByDesc('id')->value('details'))->not->toContain('Imprimante');
});

it('ne demande pas de motif pour un badge anonyme réutilisable, mais le journalise quand il est fourni', function () {
    $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 2])->assertOk();
    $again = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 2])->assertOk()->json();
    expect(runRow($again['run'])->reason)->toBeNull();
    $third = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 2, 'reason' => 'Test fictif'])->assertOk()->json();
    expect(runRow($third['run'])->reason)->toBe('Test fictif')->and(json_decode((string) DB::table('audit_logs')->where('action', 'atelier.document_genere')->orderByDesc('id')->value('details'), true)['motif_fourni'])->toBeTrue();
});

// --- Suivi, permissions, architecture -----------------------------------------------------------------------------------------------

it('liste les derniers tirages pour l’Atelier sans donnée personnelle', function () {
    $plan = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 3])->assertOk()->json();
    $runs = $this->getJson('/administration/atelier/tirages?event='.DemoSeeder::id('event'))->assertOk()->json('runs');
    expect($runs[0]['id'])->toBe($plan['run'])->and($runs[0]['state'])->toBe('generated')->and($runs[0]['count'])->toBe(4)->and($runs[0]['by'])->toBe('Compte fictif atelier');
    $this->get('/administration/atelier')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Atelier')->has('events')->has('batches')->has('runs', 1)->missing('badges')->missing('visitors'));
});

it('applique les permissions côté serveur à toutes les routes de l’Atelier', function (string $role) {
    $plan = $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0 + 0])->assertOk()->json();
    $this->actingAs(atelierAccount($role));
    $this->get('/administration/atelier')->assertForbidden();
    $this->postJson('/administration/atelier/apercu-fictif', $this->visual)->assertForbidden();
    $this->postJson('/administration/atelier/apercu', $this->selection)->assertForbidden();
    $this->postJson('/administration/atelier/tirages', $this->selection)->assertForbidden();
    $this->getJson('/administration/atelier/tirages')->assertForbidden();
    $this->get($plan['document_url'])->assertForbidden();
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/demande')->assertForbidden();
    $this->postJson('/administration/atelier/tirages/'.$plan['run'].'/confirmation', ['printed' => 1, 'spoiled' => 0])->assertForbidden();
    $this->get('/administration/atelier/alignement?format=pvc&orientation=paysage')->assertForbidden();
    $this->postJson('/administration/atelier/personnes', [])->assertForbidden();
    $this->postJson('/administration/atelier/preparer', [])->assertForbidden();
    $this->get('/administration/badges/etiquettes?batch='.$this->batch->id)->assertForbidden();
    expect(DB::table('badge_print_events')->where('print_run_id', $plan['run'])->count())->toBe(1);
})->with(['agent_accueil', 'superviseur', 'hote', 'encadreur']);

it('exige la permission des listes pour le mode nominatif', function () {
    // Un compte qui gère les badges sans gérer les listes : l'Atelier lui est ouvert, le nominatif non.
    $badgesOnly = atelierAccount('agent_accueil');
    $badgesOnly->givePermissionTo('badges.gerer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($badgesOnly->fresh()->can('badges.gerer'))->toBeTrue()->and($badgesOnly->fresh()->can('listes.importer'))->toBeFalse();
    $this->actingAs($badgesOnly->fresh());
    $this->get('/administration/atelier')->assertOk();
    $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0, 'mode' => 'nominatif', 'personal_allowed' => true])->assertForbidden();
    // Le mode standard reste possible, sans aucune donnée personnelle.
    $this->postJson('/administration/atelier/tirages', [...$this->selection, 'from' => $this->n0, 'to' => $this->n0])->assertOk();
});

it('garde l’Atelier étranger aux états métier : aucun accès aux badges en écriture, au contrôle, aux cartes ni aux habilitations', function () {
    $forbidden = ['BadgeService', 'SiteControl', 'PermanentAccess', 'HolderCards', 'CardLifecycle', 'HolderEntitlements', 'permanent_holders', 'holder_cards', 'holder_entitlements', 'badge_assignments', 'zone_policies'];
    foreach (glob(app_path('Services/Atelier/*.php')) as $file) {
        $code = (string) file_get_contents($file);
        foreach ($forbidden as $word) {
            expect($code)->not->toContain($word, basename($file));
        }
        // Seules écritures permises : la traçabilité et le marquage d'exposition.
        preg_match_all("/DB::table\\('([a-z_]+)'\\)(?:->[a-zA-Z]+\\([^;]*?)?->(insert|update|delete)\\(/s", $code, $writes, PREG_SET_ORDER);
        foreach ($writes as $write) {
            expect(in_array($write[1], ['badge_print_runs', 'badge_print_events', 'badge_batches'], true), basename($file).' écrit dans '.$write[1])->toBeTrue();
        }
    }
    $controller = (string) file_get_contents(app_path('Http/Controllers/AtelierController.php'));
    foreach (['BadgeService', 'SiteControl', 'HolderCards', 'HolderEntitlements'] as $word) {
        expect($controller)->not->toContain($word);
    }
});
