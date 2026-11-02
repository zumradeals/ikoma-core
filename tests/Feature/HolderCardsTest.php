<?php

use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\HolderCards;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function cardUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif cartes', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}

/** Titulaire fictif de la catégorie donnée. */
function cardHolder(string $category = 'AILCT-01', array $over = []): string
{
    return app(PermanentHolders::class)->create(array_merge(['full_name' => 'Personne Fictive '.Str::random(8), 'organisation' => 'Organisation fictive', 'institutional_category' => $category], $over), test()->admin);
}

function cardPng(): string
{
    $image = imagecreatetruecolor(120, 160);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = cardUser('administrateur');
    app(InstitutionalCategories::class)->initialize($this->admin);
    app(PermanentHolders::class)->createPermanentContext($this->admin);
    $this->cards = app(HolderCards::class);
});

// --- A05.1 huit modèles ------------------------------------------------------------------------------------------------------

it('émet une carte pour chacune des huit catégories institutionnelles avec son modèle', function () {
    $seen = [];
    foreach (array_keys(InstitutionalCategories::LABELS) as $code) {
        $holder = cardHolder($code);
        $this->cards->issue($holder, $this->admin);
        $data = $this->cards->cardData($holder);
        $recto = view('badges.holder-card-recto', ['card' => $data])->render();
        $verso = view('badges.holder-card-verso', ['card' => $data])->render();
        expect($recto)->toContain('data-template="'.$code.'"')->and($recto)->toContain(InstitutionalCategories::LABELS[$code])->and($verso)->toContain('data-template="'.$code.'"');
        $seen[] = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->value('b.code');
    }
    expect(array_unique($seen))->toHaveCount(8);
});

it('présente huit modèles d’exemple fictifs dont le QR ne correspond à aucun badge', function () {
    $this->actingAs($this->admin)->get('/administration/titulaires/modeles')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/ModelesCartes')->has('specimens', 8)->where('settings.valide', false));
    $specimen = $this->cards->specimen(collect(app(InstitutionalCategories::class)->all())->first());
    expect($specimen['specimen'])->toBeTrue()->and(DB::table('badges')->where('qr_token', str_repeat('0', 64))->exists())->toBeFalse();
});

// --- A05.2 recto, A05.3 verso ------------------------------------------------------------------------------------------------

it('imprime au recto le logo, le nom, la catégorie et l’identifiant stable, sans donnée annexe ni jeton', function () {
    $holder = cardHolder('AILCT-04', ['full_name' => 'Aminata Fictive Koné', 'position' => 'Fonction secrète', 'organisation' => 'Organisation secrète']);
    $this->cards->issue($holder, $this->admin);
    $data = $this->cards->cardData($holder);
    $recto = view('badges.holder-card-recto', ['card' => $data])->render();
    $identifier = DB::table('permanent_holders')->where('id', $holder)->value('identifier');
    $token = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->value('b.qr_token');
    expect($recto)->toContain('Aminata Fictive Koné')->and($recto)->toContain($identifier)->and($recto)->toContain('Délégations officielles')
        ->and($recto)->not->toContain('Fonction secrète')->and($recto)->not->toContain('Organisation secrète')->and($recto)->not->toContain($token);
});

it('place au verso un QR conforme au contrat IKOMA1 sans donnée personnelle ni secret dans la charge utile', function () {
    $holder = cardHolder('AILCT-02', ['full_name' => 'Nom Très Reconnaissable']);
    $this->cards->issue($holder, $this->admin);
    $token = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holder)->value('b.qr_token');
    $payload = QrSvg::payload($token);
    expect($payload)->toMatch('/^IKOMA1:[0-9a-f]{64}$/')->and($payload)->not->toContain('Reconnaissable');
    $verso = view('badges.holder-card-verso', ['card' => $this->cards->cardData($holder)])->render();
    expect($verso)->toContain('<svg')->and($verso)->not->toContain($token)->and($verso)->not->toContain('Reconnaissable')
        ->and($verso)->toContain(HolderCards::DEFAULT_NOTICE);
    // Le QR du verso est exactement celui que produit le moteur existant pour ce jeton.
    expect($verso)->toContain(substr(app(QrSvg::class)->render($payload, 'x'), strpos(app(QrSvg::class)->render($payload, 'x'), '<path'), 80));
});

it('rend les mentions du verso configurables et retire la mention provisoire à la validation par l’institution', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $recto = fn () => view('badges.holder-card-recto', ['card' => $this->cards->cardData($holder)])->render();
    expect($recto())->toContain('MODÈLE PROVISOIRE');
    $this->actingAs($this->admin)->put('/administration/titulaires/modeles', ['ligne1' => 'Ligne fictive un', 'ligne2' => 'Ligne fictive deux', 'contact' => 'contact@exemple.test', 'avis' => 'Avis fictif', 'valide' => true])->assertRedirect();
    $verso = view('badges.holder-card-verso', ['card' => $this->cards->cardData($holder)])->render();
    expect($recto())->not->toContain('PROVISOIRE')->and($verso)->toContain('Ligne fictive un')->and($verso)->toContain('contact@exemple.test')->and($verso)->toContain('Avis fictif');
    $this->put('/administration/titulaires/modeles', ['ligne1' => "x\x01y"])->assertSessionHasErrors('ligne1');
});

it('respecte les dimensions CR80 et la mise en page recto/verso dans les feuilles de style et la page d’impression', function () {
    $css = (string) file_get_contents(public_path('badges/holder-cards.css'));
    expect($css)->toContain('width: 53.98mm; height: 85.6mm');
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $html = $this->actingAs($this->admin)->post('/administration/titulaires/'.$holder.'/carte/imprimer')->assertOk()->getContent();
    expect($html)->toContain('@page { size: 53.98mm 85.6mm; margin: 0; }')->and($html)->toContain('data-side="recto"')->and($html)->toContain('data-side="verso"')
        ->and(strpos($html, 'data-side="recto"'))->toBeLessThan(strpos($html, 'data-side="verso"'));
});

// --- A05.4 cycle de vie ------------------------------------------------------------------------------------------------------

it('lie la carte au titulaire sans lui donner aucun accès et le contrôle la refuse', function () {
    $holder = cardHolder('AILCT-05');
    $id = $this->cards->issue($holder, $this->admin);
    $row = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.id', $id)->first(['b.id as badge', 'b.status', 'b.qr_token', 'b.event_id', 'b.category_id']);
    $permanent = app(PermanentHolders::class)->permanentContext();
    expect($row->event_id)->toBe($permanent->id)->and($row->status)->toBe('Disponible')
        ->and(DB::table('badge_assignments')->where('badge_id', $row->badge)->count())->toBe(0)
        ->and(DB::table('category_zone')->where('category_id', $row->category_id)->count())->toBe(0)
        ->and(DB::table('categories')->where('id', $row->category_id)->value('institutional_code'))->toBe('AILCT-05');
    $verdict = app(BadgeService::class)->controler(QrSvg::payload($row->qr_token), null, null, false);
    expect($verdict->valid)->toBeFalse()->and($verdict->reason)->toBe('non_associe');
});

it('refuse une seconde carte active, un titulaire clos ou sans catégorie, et l’absence de contexte permanent', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    expect(fn () => $this->cards->issue($holder, $this->admin))->toThrow(ValidationException::class);
    expect(DB::table('holder_cards')->where('holder_id', $holder)->count())->toBe(1);
    $none = app(PermanentHolders::class)->create(['full_name' => 'Sans Catégorie '.Str::random(6)], $this->admin);
    expect(fn () => $this->cards->issue($none, $this->admin))->toThrow(ValidationException::class);
    $closed = cardHolder();
    app(PermanentHolders::class)->close($closed, 'Motif fictif', $this->admin);
    expect(fn () => $this->cards->issue($closed, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('holder_cards')->insert(['id' => (string) Str::uuid(), 'holder_id' => $holder, 'badge_id' => DB::table('badges')->value('id'), 'event_id' => DB::table('badges')->value('event_id'), 'status' => 'active', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});

it('réimprime sans jamais réattribuer le badge, le QR ni l’identifiant, avec motif obligatoire et historique', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $before = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->where('k.holder_id', $holder)->first(['b.code', 'b.qr_token', 'h.identifier', 'k.id']);
    expect($this->cards->recordPrint($holder, null, $this->admin))->toBe('emission');
    expect(fn () => $this->cards->recordPrint($holder, null, $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->cards->recordPrint($holder, str_repeat('x', 201), $this->admin))->toThrow(ValidationException::class);
    expect($this->cards->recordPrint($holder, 'Carte abîmée (fictif)', $this->admin))->toBe('reimpression');
    $after = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->where('k.holder_id', $holder)->first(['b.code', 'b.qr_token', 'h.identifier', 'k.id']);
    expect($after)->toEqual($before)->and(DB::table('holder_cards')->where('holder_id', $holder)->count())->toBe(1);
    $prints = DB::table('holder_card_prints')->where('card_id', $before->id)->orderByRaw("kind = 'emission' DESC")->pluck('kind')->all();
    expect($prints)->toBe(['emission', 'reimpression']);
    $audit = DB::table('audit_logs')->whereIn('action', ['carte.imprimee', 'carte.reimprimee'])->pluck('details')->implode(' ');
    expect($audit)->toContain($before->identifier)->and($audit)->not->toContain('abîmée')->and($audit)->not->toContain('Personne Fictive');
});

it('protège l’historique des tirages : ajout seul, ni modification, ni suppression, ni remise à zéro', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $this->cards->recordPrint($holder, null, $this->admin);
    foreach (["UPDATE holder_card_prints SET reason = 'x'", 'DELETE FROM holder_card_prints', 'TRUNCATE holder_card_prints', 'DELETE FROM holder_cards', "UPDATE holder_cards SET holder_id = '".cardHolder()."'"] as $sql) {
        expect(fn () => DB::transaction(fn () => DB::statement($sql)))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('holder_card_prints')->insert(['id' => (string) Str::uuid(), 'card_id' => DB::table('holder_cards')->value('id'), 'kind' => 'emission', 'created_at' => now()])))->toThrow(QueryException::class);
});

it('n’altère ni la fabrication des lots standard ni les identifiants existants', function () {
    $event = (string) Str::uuid();
    DB::table('events')->insert(['id' => $event, 'name' => 'Événement fictif cartes', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $cats = [(string) Str::uuid()];
    DB::table('categories')->insert(['id' => $cats[0], 'event_id' => $event, 'name' => 'Catégorie fictive', 'color' => '#336699', 'priority_circuit' => false, 'created_at' => now(), 'updated_at' => now()]);
    $batch = app(BadgeLots::class)->generate($event, $cats[0], 3, $this->admin);
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    expect(DB::table('badges')->where('badge_batch_id', $batch->id)->count())->toBe($batch->quantity)
        ->and(DB::table('badges')->where('badge_batch_id', $batch->id)->min('code'))->toBe('B-001')
        ->and(DB::table('badges')->where('badge_batch_id', $batch->id)->where('printed_for_visitor_id', '<>', null)->count())->toBe(0);
    $codes = DB::table('badges')->pluck('qr_token');
    expect($codes->unique()->count())->toBe($codes->count());
});

// --- Portrait ----------------------------------------------------------------------------------------------------------------

it('stocke le portrait chiffré et ne l’imprime que si l’impression est autorisée', function () {
    Storage::fake('local');
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $this->actingAs($this->admin)->post('/administration/titulaires/'.$holder.'/portrait', ['photo' => UploadedFile::fake()->createWithContent('p.png', cardPng())])->assertRedirect();
    $row = DB::table('permanent_holders')->where('id', $holder)->first();
    expect($row->photo_file)->toMatch('~^holder-portraits/[0-9a-f-]{36}\.enc$~')->and(Storage::disk('local')->get($row->photo_file))->not->toContain("\xFF\xD8\xFF");
    expect(view('badges.holder-card-recto', ['card' => $this->cards->cardData($holder)])->render())->not->toContain('data:image/jpeg');
    $this->post('/administration/titulaires/'.$holder.'/portrait', ['photo' => UploadedFile::fake()->createWithContent('p.png', cardPng()), 'print_allowed' => true])->assertRedirect();
    expect(view('badges.holder-card-recto', ['card' => $this->cards->cardData($holder)])->render())->toContain('data:image/jpeg;base64,');
    $this->delete('/administration/titulaires/'.$holder.'/portrait')->assertRedirect();
    expect(DB::table('permanent_holders')->where('id', $holder)->value('photo_file'))->toBeNull();
    $this->post('/administration/titulaires/'.$holder.'/portrait', ['photo' => UploadedFile::fake()->createWithContent('p.png', 'pas une image')])->assertSessionHasErrors('photo');
});

// --- Droits, parcours HTTP, journal ------------------------------------------------------------------------------------------

it('réserve la chaîne de cartes aux administrateurs et ne produit aucune page sans authentification', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertRedirect('/login');
    foreach (['agent_accueil', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(cardUser($role));
        $this->get('/administration/titulaires/'.$holder.'/carte')->assertForbidden();
        $this->post('/administration/titulaires/'.$holder.'/carte')->assertForbidden();
        $this->post('/administration/titulaires/'.$holder.'/carte/imprimer')->assertForbidden();
        $this->post('/administration/titulaires/'.$holder.'/portrait')->assertForbidden();
        $this->get('/administration/titulaires/modeles')->assertForbidden();
        $this->get('/administration/titulaires/alignement')->assertForbidden();
        $this->put('/administration/titulaires/modeles', ['valide' => true])->assertForbidden();
    }
    expect(DB::table('holder_card_prints')->count())->toBe(0);
});

it('déroule le parcours administrateur : fiche, préparation, aperçu, impression, réimpression et historique', function () {
    $holder = cardHolder('AILCT-07');
    $this->actingAs($this->admin);
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/CarteTitulaire')->where('card', null)->where('preview', null));
    $this->post('/administration/titulaires/'.$holder.'/carte')->assertRedirect('/administration/titulaires/'.$holder.'/carte');
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertInertia(fn ($p) => $p->where('card.associated', false)->has('preview.recto')->has('preview.verso'));
    $response = $this->post('/administration/titulaires/'.$holder.'/carte/imprimer')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
    expect($m[1] ?? '')->not->toBe('')->and($response->getContent())->toContain('nonce="'.$m[1].'"');
    $this->post('/administration/titulaires/'.$holder.'/carte/imprimer')->assertSessionHasErrors('reason');
    $this->post('/administration/titulaires/'.$holder.'/carte/imprimer', ['reason' => 'Perte du support (fictif)'])->assertOk();
    $this->get('/administration/titulaires/'.$holder.'/carte')->assertInertia(fn ($p) => $p->has('card.prints', 2)->where('card.prints.0.kind', 'emission')->where('card.prints.1.kind', 'reimpression'));
    $details = DB::table('audit_logs')->whereIn('action', ['carte.emise', 'carte.apercu'])->pluck('details')->implode(' ');
    expect($details)->not->toContain('Personne Fictive');
});

it('sert une page d’essai d’alignement sans QR ni donnée personnelle et sans rien enregistrer', function () {
    $holder = cardHolder();
    $this->cards->issue($holder, $this->admin);
    $prints = DB::table('holder_card_prints')->count();
    $html = $this->actingAs($this->admin)->get('/administration/titulaires/alignement')->assertOk()->getContent();
    expect($html)->toContain('data-side="recto"')->and($html)->toContain('data-side="verso"')->and($html)->not->toContain('<svg')->and($html)->not->toContain('Personne Fictive')
        ->and(DB::table('holder_card_prints')->count())->toBe($prints);
});
