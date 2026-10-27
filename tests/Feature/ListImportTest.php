<?php

use App\Models\User;
use App\Services\Imports\ListAnalysis;
use App\Services\Imports\ListImport;
use App\Services\VisitorSearch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

function importCsv(array $rows, string $separator = ';', array $headers = ['nom complet', 'organisation', 'hôte', 'catégorie', 'début de validité', 'fin de validité']): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ikoma-import-');
    $h = fopen($path, 'w');
    fputcsv($h, $headers, $separator, '"', '');
    foreach ($rows as $row) {
        fputcsv($h, $row, $separator, '"', '');
    }fclose($h);

    return new UploadedFile($path, 'exercice-fictif.csv', null, null, true);
}
function importActor(string $role = 'administrateur'): User
{
    $user = User::create(['name' => 'Compte fictif L3', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    Storage::fake('local');
    $this->actor = importActor();
    $this->event = DemoSeeder::id('event');
    $this->service = app(ListImport::class);
});

it('contrôle 1000 lignes avec des nombres exacts avant toute écriture puis importe atomiquement et supprime le fichier', function () {
    $file = new UploadedFile(base_path('tests/fixtures/liste-fictive-1000.csv'), 'liste-fictive-1000.csv', null, null, true);
    $before = DB::table('visitors')->count();
    $start = microtime(true);
    $p = $this->service->preview($this->event, $this->actor->id, $file, false);
    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeLessThan(10.0);
    expect($p['summary'])->toBe(['read' => 1000, 'importable' => 985, 'blocked' => 15, 'warnings' => 8]);
    expect(DB::table('visitors')->count())->toBe($before);
    expect(DB::table('import_batches')->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('imports'))->toHaveCount(2);
    $start = microtime(true);
    $result = $this->service->confirm($this->event, $this->actor->id, $p['id']);
    $elapsedImport = microtime(true) - $start;
    fwrite(STDOUT, sprintf("\nL3 1000 lignes : aperçu %.3fs ; import %.3fs\n", $elapsed, $elapsedImport));
    expect($elapsedImport)->toBeLessThan(30.0);
    expect(DB::table('visitors')->count())->toBe($before + 985);
    expect(DB::table('visitors')->where('import_batch_id', $p['id'])->distinct()->pluck('status')->all())->toBe(['attendu']);
    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
    expect(DB::table('visitors')->where('import_batch_id', $p['id'])->where('full_name', "'=Essai fictif")->exists())->toBeTrue();
    expect(app(VisitorSearch::class)->search($this->event, 'Personne fictive L3 0001')[0]['full_name'])->toBe('Personne fictive L3 0001');
    $batch = DB::table('import_batches')->find($p['id']);
    expect($batch->file_reference)->toBe('liste-fictive-1000.csv');
    $stored = json_decode($batch->summary, true);
    $expected = $p['summary'];
    ksort($stored);
    ksort($expected);
    expect($stored)->toBe($expected);
    $this->service->confirm($this->event, $this->actor->id, $p['id']);
    expect(DB::table('visitors')->count())->toBe($before + 985);
    $audit = DB::table('audit_logs')->where('actor_id', $this->actor->id)->where('action', 'like', 'liste.import_%')->get();
    expect($audit)->toHaveCount(2);
    foreach ($audit as $entry) {
        expect(array_keys(json_decode($entry->details, true)))->toBe(['id', 'event_id', 'read', 'importable', 'blocked', 'warnings']);
        expect($entry->details)->not->toContain('Personne', 'Essai', 'fictive', 'csv');
    }
    $this->artisan('audit:verify')->assertSuccessful();
});
it('reconnaît les en-têtes et références sans accents ni casse, nettoie et protège les quatre préfixes de formule', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([
        ["  Nom\t fictif   À  ", '  Organisation   fictive  ', '', 'visiteur', '', ''], ['=Formule fictive', '', '', 'VISITEUR', '', ''], ['+Formule fictive', '', '', 'Visiteur', '', ''], ['-Formule fictive', '', '', 'Visiteur', '', ''], ['@Formule fictive', '', '', 'Visiteur', '', ''],
    ], ',', ['NOM COMPLET', 'ORGANISATION', 'HOTE', 'CATEGORIE', 'DEBUT DE VALIDITE', 'FIN DE VALIDITE']), false);
    expect($p['summary']['importable'])->toBe(5);
    $this->service->confirm($this->event, $this->actor->id, $p['id']);
    foreach (['=', '+', '-', '@'] as $symbol) {
        expect(DB::table('visitors')->where('full_name', "'".$symbol.'Formule fictive')->exists())->toBeTrue();
    }
    $v = DB::table('visitors')->where('full_name', 'Nom fictif À')->first();
    expect($v->organisation)->toBe('Organisation fictive');
    $event = DB::table('events')->find($this->event);
    expect(strtotime($v->valid_from))->toBe(strtotime($event->starts_at));
    expect(strtotime($v->valid_until))->toBe(strtotime($event->ends_at));
});
it('bloque chaque anomalie et distingue les doublons des homonymes du fichier et de la base', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([
        ['', '', '', 'Visiteur', '', ''], [str_repeat('x', 161), '', '', 'Visiteur', '', ''], ['Catégorie fictive', '', '', 'Inconnue', '', ''], ['Hôte fictif', '', 'Inconnu', 'Visiteur', '', ''], ['Date fictive', '', '', 'Visiteur', '2027-02-30', ''], ['Ordre fictif', '', '', 'Visiteur', '2027-02-02', '2027-01-01'],
        ['Unique fictif', 'Société fictive', '', 'Visiteur', '', ''], ['UNIQUE FICTIF', 'societe fictive', '', 'Visiteur', '', ''], ['Unique fictif', 'Autre société fictive', '', 'Visiteur', '', ''], ['Kone Mariam', 'Organisation fictive 3', '', 'Visiteur', '', ''],
    ]), false);
    expect($p['summary']['blocked'])->toBeGreaterThanOrEqual(7);
    expect($p['summary']['warnings'])->toBeGreaterThanOrEqual(2);
    expect(json_encode($p['anomalies']))->toContain('Doublon', 'Homonyme');
    $this->service->confirm($this->event, $this->actor->id, $p['id']);
    expect(DB::table('visitors')->where('import_batch_id', $p['id'])->where('full_name', 'Unique fictif')->count())->toBe(2);
    $existing = DB::table('visitors')->where('import_batch_id', $p['id'])->where('full_name', 'Unique fictif')->first();
    $p2 = $this->service->preview($this->event, $this->actor->id, importCsv([[$existing->full_name, $existing->organisation, '', 'Visiteur', '', '']]), false);
    expect($p2['summary']['blocked'])->toBe(1);
    expect($p2['anomalies'][0]['related'][0]['id'])->toBe($existing->id);
});
it('refuse un hôte inconnu sauf option explicite et réutilise les noms normalisés', function () {
    $file = importCsv([['Personne fictive option A', '', 'Hôte fictif À créer', 'Visiteur', '', ''], ['Personne fictive option B', '', 'HOTE FICTIF A CREER', 'Visiteur', '', '']]);
    $p = $this->service->preview($this->event, $this->actor->id, $file, false);
    expect($p['summary']['blocked'])->toBe(2);
    $p = $this->service->preview($this->event, $this->actor->id, $file, true);
    expect($p['summary']['importable'])->toBe(2);
    $this->service->confirm($this->event, $this->actor->id, $p['id']);
    expect(DB::table('hosts')->where('event_id', $this->event)->count())->toBe(4);
    expect(DB::table('visitors')->where('import_batch_id', $p['id'])->distinct()->pluck('host_id')->count())->toBe(1);
});
it('refuse les limites de taille, de lignes, les faux formats et le CSV non UTF8 sans perdre un aperçu valide', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([['Valide fictif', '', '', 'Visiteur', '', '']]), false);
    $files = [UploadedFile::fake()->create('gros.csv', 5121), UploadedFile::fake()->createWithContent('faux.xlsx', 'du texte'), UploadedFile::fake()->createWithContent('latin.csv', "nom complet;catégorie\n\xE9;Visiteur"), UploadedFile::fake()->createWithContent('script.csv', '<html><script>malveillant</script></html>'), importCsv(array_fill(0, 5001, ['Ligne fictive', '', '', 'Visiteur', '', ''])), importCsv([['Test fictif']], ';', ['autre en-tête'])];
    foreach ($files as $file) {
        try {
            $this->service->preview($this->event, $this->actor->id, $file, false);
            $this->fail('Fichier accepté');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('file');
        }
    }
    expect($this->service->show($this->event, $this->actor->id)['id'])->toBe($p['id']);
    expect(DB::table('visitors')->where('full_name', 'Valide fictif')->exists())->toBeFalse();
});
it('annule toute écriture en cas d’erreur interne et conserve le fichier pour réessayer', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([['Atomique fictif', '', 'Nouvel hôte fictif', 'Visiteur', '', '']]), true);
    $before = DB::table('visitors')->count();
    $hosts = DB::table('hosts')->count();
    $fail = true;
    DB::connection()->beforeExecuting(function ($sql) use (&$fail) {
        if ($fail && str_starts_with($sql, 'insert into "audit_logs"')) {
            throw new RuntimeException('Erreur de recette sans donnée personnelle');
        }
    });
    try {
        try {
            $this->service->confirm($this->event, $this->actor->id, $p['id']);
            $this->fail('Erreur attendue');
        } catch (RuntimeException) {
            expect(DB::table('visitors')->count())->toBe($before);
            expect(DB::table('hosts')->count())->toBe($hosts);
            expect(DB::table('import_batches')->where('id', $p['id'])->exists())->toBeFalse();
        }
    } finally {
        $fail = false;
    }
    expect(Storage::disk('local')->allFiles('imports'))->toHaveCount(2);
});
it('isole les aperçus, verrouille l’événement, refuse les doubles clics périmés et journalise l’abandon', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([['Isolé fictif', '', '', 'Visiteur', '', '']]), false);
    $other = importActor();
    $this->actingAs($other)->get('/administration/imports?event_id='.$this->event)->assertForbidden();
    $this->post('/administration/imports/confirmer', ['event_id' => $this->event, 'id' => $p['id']])->assertForbidden();
    $this->post('/administration/imports/abandonner', ['event_id' => $this->event, 'id' => $p['id']])->assertForbidden();
    $this->post('/administration/imports/apercu', ['event_id' => $this->event, 'file' => importCsv([['Autre fictif', '', '', 'Visiteur', '', '']]), 'create_hosts' => false])->assertSessionHasErrors('file');
    $this->actingAs($this->actor)->post('/administration/imports/confirmer', ['event_id' => $this->event, 'id' => (string) Str::uuid()])->assertStatus(409);
    $this->service->abandon($this->event, $this->actor->id, $p['id']);
    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
    expect(DB::table('audit_logs')->where('action', 'liste.import_abandonne')->where('actor_id', $this->actor->id)->count())->toBe(1);
});
it('purge automatiquement après une heure et ne purge pas un fichier récent', function () {
    $this->service->preview($this->event, $this->actor->id, importCsv([['Purge fictive', '', '', 'Visiteur', '', '']]), false);
    $this->artisan('imports:purge')->assertSuccessful();
    expect(Storage::disk('local')->allFiles('imports'))->toHaveCount(2);
    $this->travel(61)->minutes();
    $this->artisan('imports:purge')->assertSuccessful();
    expect(Storage::disk('local')->allFiles('imports'))->toBe([]);
});
it('refuse toutes les routes aux autres rôles et permet le modèle à l’administrateur', function () {
    foreach (['superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $this->actingAs(importActor($role));
        foreach (['/administration/imports', '/administration/imports/modele'] as $url) {
            $this->get($url)->assertForbidden();
        }foreach (['apercu', 'confirmer', 'abandonner'] as $action) {
            $this->post('/administration/imports/'.$action, [])->assertForbidden();
        }
    }
    $this->actingAs($this->actor)->get('/administration/imports/modele')->assertOk()->assertSee('nom complet');
});
it('présente le rapport Inertia et force une nouvelle lecture si une référence change avant confirmation', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importCsv([['Concurrence fictive', '', '', 'Visiteur', '', '']]), false);
    $this->actingAs($this->actor)->get('/administration/imports?event_id='.$this->event)->assertOk()->assertInertia(fn (Assert $a) => $a->component('Admin/Imports')->where('preview.summary.importable', 1)->missing('preview.path')->missing('preview.actor_id'));
    DB::table('categories')->where('event_id', $this->event)->where('name', 'Visiteur')->update(['name' => 'Visiteur modifié']);
    $this->post('/administration/imports/confirmer', ['event_id' => $this->event, 'id' => $p['id']])->assertSessionHasErrors('file');
    expect(DB::table('import_batches')->where('id', $p['id'])->exists())->toBeFalse();
    expect($this->service->show($this->event, $this->actor->id)['summary']['blocked'])->toBe(1);
});

function importXlsx(bool $external = false, bool $macro = false): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ikoma-xlsx-');
    $z = new ZipArchive;
    $z->open($path, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Liste" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.($external ? '<Relationship Id="rId2" Target="https://exemple.invalid" TargetMode="External" Type="hyperlink"/>' : '').'</Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>nom complet</t></is></c><c r="B1" t="inlineStr"><is><t>catégorie</t></is></c></row><row r="2"><c r="A2"><f>EssaiFictif()</f><v>0</v></c><c r="B2" t="inlineStr"><is><t>Visiteur</t></is></c></row></sheetData></worksheet>');
    if ($macro) {
        $z->addFromString('xl/vbaProject.bin', 'macro fictive jamais exécutée');
    }$z->close();

    return new UploadedFile($path, 'tableur-fictif.xlsx', null, null, true);
}
it('lit un XLSX en flux sans évaluer la formule et refuse liens externes, macros et entités XML', function () {
    $p = $this->service->preview($this->event, $this->actor->id, importXlsx(), false);
    expect($p['summary']['importable'])->toBe(1);
    $this->service->confirm($this->event, $this->actor->id, $p['id']);
    expect(DB::table('visitors')->where('import_batch_id', $p['id'])->value('full_name'))->toBe("'=EssaiFictif()");
    foreach ([importXlsx(true), importXlsx(false, true)] as $file) {
        try {
            $this->service->preview($this->event, $this->actor->id, $file, false);
            $this->fail('Contenu actif accepté');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('file');
        }
    }
    $file = importXlsx();
    $zip = new ZipArchive;
    $zip->open($file->getPathname());
    $zip->addFromString('xl/entites.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
    $zip->close();
    expect(fn () => $this->service->preview($this->event, $this->actor->id, $file, false))->toThrow(ValidationException::class);
});
it('la base refuse un statut visiteur inconnu et accepte les quatre valeurs validées', function () {
    $id = DemoSeeder::id('visitor0');
    foreach (['attendu', 'present', 'sorti', 'refuse'] as $status) {
        DB::table('visitors')->where('id', $id)->update(['status' => $status]);
        expect(DB::table('visitors')->where('id', $id)->value('status'))->toBe($status);
    }
    try {
        DB::transaction(fn () => DB::table('visitors')->where('id', $id)->update(['status' => 'inconnu']));
        $this->fail('Statut inconnu accepté');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
});
it('accepte 5000 homonymes sans multiplier les fiches dans le rapport', function () {
    $rows = [];
    for ($i = 1; $i <= 5000; $i++) {
        $rows[] = ['Homonyme fictif de limite', 'Organisation fictive '.$i, '', 'Visiteur', '', ''];
    }
    $p = $this->service->preview($this->event, $this->actor->id, importCsv($rows), false);
    expect($p['summary'])->toBe(['read' => 5000, 'importable' => 5000, 'blocked' => 0, 'warnings' => 5000]);
    expect($p['groups'])->toHaveCount(1);
    expect($p['groups'][0]['references'])->toHaveCount(5000);
    expect(strlen(json_encode($p)))->toBeLessThan(5 * 1024 * 1024);
});

it('ignore les lignes entièrement vides du tableur', function () {
    $path = tempnam(sys_get_temp_dir(), 'l3');
    file_put_contents($path, "nom complet;catégorie\nAlice Fictive;Visiteur\n;\n\n;;\n");
    $analysis = app(ListAnalysis::class)->analyse($path, 'csv', $this->event, false);
    unlink($path);
    expect($analysis['summary']['read'])->toBe(1);
});
