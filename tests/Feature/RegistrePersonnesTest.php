<?php

use App\Models\User;
use App\Services\Imports\HolderImport;
use App\Services\InstitutionalCategories;
use App\Services\PermanentHolders;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function personUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif registre', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);

    return $user;
}

function csvFile(string $content, string $name = 'personnes.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function personPng(): string
{
    $image = imagecreatetruecolor(120, 150);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** @return array<string, int> */
function cardTablesCounts(): array
{
    $counts = [];
    foreach (['holder_cards', 'holder_card_prints', 'badges', 'badge_batches', 'visitors', 'holder_entitlements', 'badge_assignments', 'events'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function holderRow(string $name): ?object
{
    return DB::table('permanent_holders')->where('full_name', $name)->first();
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);
    $this->admin = personUser('administrateur');
    $this->holders = app(PermanentHolders::class);
    app(InstitutionalCategories::class)->initialize($this->admin);
    $this->tag = Str::random(6);
});

// --- Nom, prénom, fonction, qualité -------------------------------------------------------------------------------------------

it('dérive le nom complet du prénom et du nom, et garde un nom complet historique sans le découper', function () {
    $split = $this->holders->create(['first_name' => 'Awa', 'last_name' => 'Fictive'.$this->tag, 'position' => 'Comptable', 'quality' => 'Stagiaire'], $this->admin);
    $row = DB::table('permanent_holders')->where('id', $split)->first();
    expect($row->full_name)->toBe('Awa Fictive'.$this->tag)->and($row->first_name)->toBe('Awa')->and($row->last_name)->toBe('Fictive'.$this->tag)
        ->and($row->position)->toBe('Comptable')->and($row->quality)->toBe('Stagiaire');

    $historic = $this->holders->create(['full_name' => 'Kouamé Jean Historique'.$this->tag], $this->admin);
    $old = DB::table('permanent_holders')->where('id', $historic)->first();
    expect($old->full_name)->toBe('Kouamé Jean Historique'.$this->tag)->and($old->first_name)->toBeNull()->and($old->last_name)->toBeNull();

    // Modifier la fonction d'une fiche historique ne la découpe jamais.
    $this->holders->update($historic, ['full_name' => $old->full_name, 'position' => 'Chef'], $this->admin);
    expect(DB::table('permanent_holders')->where('id', $historic)->value('first_name'))->toBeNull();
});

it('refuse un prénom sans nom (ou l’inverse) et protège la paire en base', function () {
    expect(fn () => $this->holders->create(['first_name' => 'Awa', 'last_name' => '', 'full_name' => 'Awa'], $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->create(['first_name' => '', 'last_name' => 'Fictive'], $this->admin))->toThrow(ValidationException::class);
    $id = $this->holders->create(['full_name' => 'Personne Paire'.$this->tag], $this->admin);
    expect(fn () => DB::table('permanent_holders')->where('id', $id)->update(['first_name' => 'Seul']))->toThrow(QueryException::class);
});

it('détecte le doublon sur le nom complet dérivé, quelle que soit la façon de saisir', function () {
    $this->holders->create(['first_name' => 'Awa', 'last_name' => 'Doublon'.$this->tag, 'organisation' => 'Org'], $this->admin);
    expect(fn () => $this->holders->create(['full_name' => 'awa doublon'.$this->tag, 'organisation' => 'ORG'], $this->admin))->toThrow(ValidationException::class);
});

it('recherche et filtre par fonction, qualité, catégorie absente et photo, sans exposer le chemin du portrait', function () {
    $a = $this->holders->create(['full_name' => 'Alpha '.$this->tag, 'position' => 'Archiviste', 'quality' => 'Détaché'], $this->admin);
    $b = $this->holders->create(['full_name' => 'Beta '.$this->tag, 'institutional_category' => 'AILCT-01'], $this->admin);
    $this->actingAs($this->admin);
    $this->post('/administration/titulaires/'.$a.'/portrait', ['photo' => UploadedFile::fake()->createWithContent('p.png', personPng()), 'print_allowed' => true])->assertRedirect();

    $this->get('/administration/titulaires?q=archiviste')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1)->where('holders.data.0.id', $a)->where('holders.data.0.has_photo', true)->missing('holders.data.0.photo_file'));
    $this->get('/administration/titulaires?q=detache')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1));
    $this->get('/administration/titulaires?q='.$this->tag.'&categorie=aucune')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1)->where('holders.data.0.id', $a));
    $this->get('/administration/titulaires?q='.$this->tag.'&photo=sans')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1)->where('holders.data.0.id', $b));
    $this->get('/administration/titulaires?q='.$this->tag.'&photo=avec')->assertInertia(fn (Assert $p) => $p->where('holders.total', 1)->where('holders.data.0.id', $a));
});

it('sert le portrait de la fiche aux seuls administrateurs, sans cache', function () {
    $id = $this->holders->create(['full_name' => 'Portrait '.$this->tag], $this->admin);
    $this->actingAs($this->admin)->get('/administration/titulaires/'.$id.'/portrait')->assertNotFound();
    $this->post('/administration/titulaires/'.$id.'/portrait', ['photo' => UploadedFile::fake()->createWithContent('p.png', personPng())])->assertRedirect();
    $response = $this->get('/administration/titulaires/'.$id.'/portrait')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs(personUser('agent_accueil'))->get('/administration/titulaires/'.$id.'/portrait')->assertForbidden();
});

// --- Enregistrer n'émet rien --------------------------------------------------------------------------------------------------

it('ne crée aucune carte, aucun badge, aucune fiche de participation ni habilitation en enregistrant, important ou classant des personnes', function () {
    $before = cardTablesCounts();
    $this->actingAs($this->admin);
    $this->post('/administration/titulaires', ['first_name' => 'Awa', 'last_name' => 'Seule'.$this->tag, 'institutional_category' => 'AILCT-02'])->assertRedirect();
    $this->post('/administration/titulaires/import/apercu', ['file' => csvFile("prenom;nom\nBintou;Import{$this->tag}\nCheick;Import2{$this->tag}\n")])->assertSessionHasNoErrors();
    $token = app(HolderImport::class)->show($this->admin)['id'];
    $this->post('/administration/titulaires/import/confirmer', ['token' => $token])->assertSessionHasNoErrors();
    $ids = DB::table('permanent_holders')->where('full_name', 'like', '%'.$this->tag)->pluck('id')->all();
    expect($ids)->toHaveCount(3);
    $this->post('/administration/titulaires/groupe/categorie', ['ids' => $ids, 'category' => 'AILCT-03'])->assertSessionHasNoErrors();
    expect(cardTablesCounts())->toBe($before);
});

// --- Import ---------------------------------------------------------------------------------------------------------------------

it('prépare un aperçu chiffré sans rien enregistrer, puis classe chaque ligne', function () {
    $this->holders->create(['full_name' => 'Déjà Présent'.$this->tag, 'organisation' => 'Org A'], $this->admin);
    $csv = "Prénom;Nom;Organisation;Fonction;Qualité;Catégorie;Début;Colonne inconnue\n"
        ."Nouvelle;Personne{$this->tag};Org A;Chef;Stagiaire;AILCT-01;01/02/2027;x\n"
        ."Déjà;Présent{$this->tag};org a;;;;;\n"
        ."Déjà;Présent{$this->tag};Org B;;;;;\n"
        ."Nouvelle;Personne{$this->tag};ORG A;;;;;\n"
        ."Erreur;Catégorie{$this->tag};;;;Inconnue;;\n"
        ."Erreur;Date{$this->tag};;;;;demain;\n";
    $count = DB::table('permanent_holders')->count();
    $this->actingAs($this->admin)->post('/administration/titulaires/import/apercu', ['file' => csvFile($csv)])->assertSessionHasNoErrors();
    expect(DB::table('permanent_holders')->count())->toBe($count);

    $preview = app(HolderImport::class)->show($this->admin);
    $status = array_column($preview['rows'], 'status', 'line');
    expect($status)->toBe([2 => 'ok', 3 => 'doublon_registre', 4 => 'homonyme', 5 => 'doublon_fichier', 6 => 'erreur', 7 => 'erreur'])
        ->and($preview['summary'])->toMatchArray(['lignes' => 6, 'a_creer' => 1, 'homonymes' => 1, 'doublons_registre' => 1, 'doublons_fichier' => 1, 'erreurs' => 2])
        ->and($preview['columns']['ignored'])->toBe(['Colonne inconnue']);
    // Le fichier d'aperçu est chiffré : aucun nom en clair sur le disque.
    $stored = Storage::disk('local')->get('holder-imports/'.$this->admin->id.'.enc');
    expect($stored)->not->toContain('Personne'.$this->tag)->and($stored)->not->toContain('Org A');
});

it('confirme en une transaction : seules les lignes valides sont créées, un homonyme seulement s’il est confirmé', function () {
    $this->holders->create(['full_name' => 'Déjà Présent'.$this->tag, 'organisation' => 'Org A'], $this->admin);
    $csv = "prenom,nom,organisation,categorie,fin_engagement\nNouvelle,Personne{$this->tag},Org A,Personnel AILCT,2027-12-31\nDéjà,Présent{$this->tag},Org B,,\nDéjà,Présent{$this->tag},Org A,,\n";
    $import = app(HolderImport::class);
    $import->preview($this->admin, csvFile($csv));
    $token = $import->show($this->admin)['id'];
    $first = DB::table('permanent_holders')->count();

    $result = $import->confirm($this->admin, $token, []);
    expect($result)->toMatchArray(['created' => 1, 'ignored' => 2])->and(DB::table('permanent_holders')->count())->toBe($first + 1);
    $row = holderRow('Nouvelle Personne'.$this->tag);
    expect($row->first_name)->toBe('Nouvelle')->and($row->institutional_category)->toBe('AILCT-01')->and($row->engagement_ends_on)->toBe('2027-12-31')->and($row->created_by)->toBe($this->admin->id);

    // Rejouer la confirmation (nouvelle tentative) ne crée rien de plus.
    expect(fn () => $import->confirm($this->admin, $token, []))->toThrow(ValidationException::class);
    expect(DB::table('permanent_holders')->count())->toBe($first + 1)->and($import->show($this->admin))->toBeNull();

    // Homonyme confirmé explicitement.
    $import->preview($this->admin, csvFile("prenom,nom,organisation\nDéjà,Présent{$this->tag},Org B\n"));
    $token = $import->show($this->admin)['id'];
    expect($import->confirm($this->admin, $token, [2])['created'])->toBe(1);
});

it('ne crée pas une personne devenue doublon entre l’aperçu et la confirmation', function () {
    $import = app(HolderImport::class);
    $import->preview($this->admin, csvFile("prenom,nom\nTardive,Doublon{$this->tag}\n"));
    $token = $import->show($this->admin)['id'];
    $this->holders->create(['full_name' => 'Tardive Doublon'.$this->tag], $this->admin);
    $count = DB::table('permanent_holders')->count();
    expect($import->confirm($this->admin, $token, []))->toMatchArray(['created' => 0, 'ignored' => 1])->and(DB::table('permanent_holders')->count())->toBe($count);
});

it('accepte un nom complet non découpé, rejette les colonnes ambiguës ou absentes et borne le volume', function () {
    $import = app(HolderImport::class);
    $import->preview($this->admin, csvFile("Nom complet;Fonction\nKouassi Yao Marie{$this->tag};Directrice\n"));
    $import->confirm($this->admin, $import->show($this->admin)['id'], []);
    $row = holderRow('Kouassi Yao Marie'.$this->tag);
    expect($row->first_name)->toBeNull()->and($row->position)->toBe('Directrice');

    $import->preview($this->admin, csvFile("nom\nSimple Nom{$this->tag}\n"));
    expect($import->show($this->admin)['rows'][0]['name'])->toBe('Simple Nom'.$this->tag);

    foreach (["nom;nom complet\nA;B\n", "organisation\nX\n", "prenom\nAwa\n", ''] as $bad) {
        expect(fn () => $import->preview($this->admin, csvFile($bad)))->toThrow(ValidationException::class);
    }
    $big = "nom complet\n".implode("\n", array_map(fn ($i) => "Personne Numero {$i}", range(1, HolderImport::MAX_LINES + 1)))."\n";
    expect(fn () => $import->preview($this->admin, csvFile($big)))->toThrow(ValidationException::class);
    expect(fn () => $import->preview($this->admin, UploadedFile::fake()->createWithContent('x.exe', 'MZ')))->toThrow(ValidationException::class);
});

it('journalise l’import sans nom ni organisation et conserve l’identifiant de chaque création', function () {
    $import = app(HolderImport::class);
    $import->preview($this->admin, csvFile("prenom;nom;organisation\nSecret;Journal{$this->tag};Entreprise Secrete\n"));
    $import->confirm($this->admin, $import->show($this->admin)['id'], []);
    $logs = DB::table('audit_logs')->whereIn('action', ['titulaire.import_apercu', 'titulaire.import_confirme', 'titulaire.cree'])->get();
    expect($logs->pluck('action')->unique()->sort()->values()->all())->toBe(['titulaire.cree', 'titulaire.import_apercu', 'titulaire.import_confirme']);
    foreach ($logs as $log) {
        expect($log->details)->not->toContain('Secret')->and($log->details)->not->toContain('Journal'.$this->tag)->and($log->details)->not->toContain('Entreprise');
    }
    $confirm = json_decode((string) DB::table('audit_logs')->where('action', 'titulaire.import_confirme')->latest('id')->value('details'), true);
    expect($confirm['crees'])->toBe(1)->and($confirm['premier'])->toBe($confirm['dernier']);
});

it('garde un aperçu par administrateur, l’abandonne, et purge les aperçus expirés ou illisibles', function () {
    $import = app(HolderImport::class);
    $other = personUser('administrateur');
    $import->preview($this->admin, csvFile("nom\nAbandon{$this->tag}\n"));
    expect($import->show($other))->toBeNull();
    $import->abandon($this->admin, $import->show($this->admin)['id']);
    expect($import->show($this->admin))->toBeNull();

    $import->preview($this->admin, csvFile("nom\nExpire{$this->tag}\n"));
    Storage::disk('local')->put('holder-imports/illisible.enc', 'corrompu');
    $this->travel(2)->hours();
    expect($import->show($this->admin))->toBeNull();
    $import->preview($this->admin, csvFile("nom\nExpire2{$this->tag}\n"));
    $this->travel(2)->hours();
    expect($import->purge())->toBeGreaterThanOrEqual(2)->and(Storage::disk('local')->files('holder-imports'))->toBe([]);
});

it('télécharge un modèle de fichier fictif', function () {
    $response = $this->actingAs($this->admin)->get('/administration/titulaires/import/modele')->assertOk();
    expect($response->streamedContent())->toBe(HolderImport::template())->and(HolderImport::template())->toContain('prenom;nom')->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

// --- Sélection multiple ---------------------------------------------------------------------------------------------------------

it('attribue une catégorie à plusieurs personnes sans toucher aux clôturées ni aux droits', function () {
    $a = $this->holders->create(['full_name' => 'Groupe A'.$this->tag], $this->admin);
    $b = $this->holders->create(['full_name' => 'Groupe B'.$this->tag, 'institutional_category' => 'AILCT-02'], $this->admin);
    $c = $this->holders->create(['full_name' => 'Groupe C'.$this->tag], $this->admin);
    $this->holders->close($c, 'Fin fictive', $this->admin);
    $zones = DB::table('category_zone')->count();
    $result = $this->holders->setCategoryMany([$a, $b, $c, $a], 'AILCT-02', $this->admin);
    expect($result)->toBe(['modifies' => 1, 'inchanges' => 1, 'clos' => 1])
        ->and(DB::table('permanent_holders')->where('id', $a)->value('institutional_category'))->toBe('AILCT-02')
        ->and(DB::table('permanent_holders')->where('id', $c)->value('institutional_category'))->toBeNull()
        ->and(DB::table('category_zone')->count())->toBe($zones);
    expect(fn () => $this->holders->setCategoryMany([$a], 'AILCT-99', $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->setCategoryMany([], 'AILCT-01', $this->admin))->toThrow(ValidationException::class);
    expect(fn () => $this->holders->setCategoryMany(array_map(fn () => (string) Str::uuid(), range(1, PermanentHolders::BULK_MAX + 1)), null, $this->admin))->toThrow(ValidationException::class);
    expect($this->holders->setCategoryMany([$a], null, $this->admin)['modifies'])->toBe(1)->and(DB::table('permanent_holders')->where('id', $a)->value('institutional_category'))->toBeNull();
});

it('clôture plusieurs personnes avec un motif et une confirmation, définitivement', function () {
    $a = $this->holders->create(['full_name' => 'Clore A'.$this->tag], $this->admin);
    $b = $this->holders->create(['full_name' => 'Clore B'.$this->tag], $this->admin);
    $this->actingAs($this->admin)->post('/administration/titulaires/groupe/cloturer', ['ids' => [$a, $b], 'reason' => 'Fin fictive'])->assertSessionHasErrors('confirmation');
    expect(DB::table('permanent_holders')->whereIn('id', [$a, $b])->where('status', 'actif')->count())->toBe(2);
    $this->post('/administration/titulaires/groupe/cloturer', ['ids' => [$a, $b], 'reason' => 'Fin fictive', 'confirmation' => true])->assertSessionHasNoErrors();
    expect(DB::table('permanent_holders')->whereIn('id', [$a, $b])->where('status', 'clos')->count())->toBe(2)
        ->and($this->holders->closeMany([$a], 'Encore', $this->admin))->toBe(['clos' => 0, 'deja_clos' => 1]);
    expect(fn () => DB::table('permanent_holders')->where('id', $a)->update(['quality' => 'Modifiée']))->toThrow(QueryException::class);
});

it('refuse les nouvelles opérations à tout rôle sans la permission Personnes et listes', function () {
    $id = $this->holders->create(['full_name' => 'Permission '.$this->tag], $this->admin);
    foreach (['agent_accueil', 'hote', 'encadreur', 'superviseur'] as $role) {
        $this->actingAs(personUser($role));
        $this->post('/administration/titulaires/import/apercu', ['file' => csvFile("nom\nX\n")])->assertForbidden();
        $this->post('/administration/titulaires/import/confirmer', ['token' => (string) Str::uuid()])->assertForbidden();
        $this->post('/administration/titulaires/groupe/categorie', ['ids' => [$id], 'category' => 'AILCT-01'])->assertForbidden();
        $this->post('/administration/titulaires/groupe/cloturer', ['ids' => [$id], 'reason' => 'x', 'confirmation' => true])->assertForbidden();
        $this->get('/administration/titulaires/import/modele')->assertForbidden();
    }
    expect(DB::table('permanent_holders')->where('id', $id)->value('status'))->toBe('actif');
});
