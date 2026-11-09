<?php

use App\Models\User;
use App\Services\BadgesCartesSummary;
use App\Services\InstitutionalCategories;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Consolidation de la navigation (badges, cartes, modèles, impression) — tests de CARACTÉRISATION.
 * Ils figurent les routes d'administration des domaines concernés et leurs autorisations avant toute modification de la navigation :
 * aucune route ne disparaît, aucune permission ne change. Une route ajoutée doit être déclarée ici, une par une.
 * Données fictives uniquement.
 */

/** Méthode, URI, permissions (`can:`) et limitations (`throttle:`) de chaque route des domaines concernés. */
const CN_ROUTES = [
    ['POST', 'administration/participations/{visitor}/annonce', ['can:listes.importer'], []],
    ['POST', 'administration/participations/{visitor}/vehicules', ['can:listes.importer'], []],
    ['GET', 'administration/invites', ['can:listes.importer'], []],
    ['POST', 'administration/invites', ['can:listes.importer'], []],
    ['POST', 'administration/invites/{visitor}', ['can:listes.importer'], []],
    ['DELETE', 'administration/invites/{visitor}', ['can:listes.importer'], []],
    ['GET', 'administration/invites/{visitor}/portrait', ['can:listes.importer'], []],
    ['GET', 'administration/personnes', ['can:listes.importer'], []],
    ['GET', 'administration/personnes/recherche', ['can:listes.importer'], ['throttle:recherche']],
    ['POST', 'administration/personnes', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/personnes/{person}', ['can:listes.importer'], []],
    ['POST', 'administration/personnes/{person}/participations', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/participations/{participation}/cloturer', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/imports', ['can:listes.importer'], []],
    ['GET', 'administration/imports/modele', ['can:listes.importer'], []],
    ['POST', 'administration/imports/apercu', ['can:listes.importer'], []],
    ['POST', 'administration/imports/confirmer', ['can:listes.importer'], []],
    ['POST', 'administration/imports/abandonner', ['can:listes.importer'], []],
    ['GET', 'administration/atelier/parcours', ['can:badges.gerer'], []],
    ['GET', 'administration/atelier', ['can:badges.gerer'], []],
    ['POST', 'administration/atelier/apercu-fictif', ['can:badges.gerer'], ['throttle:atelier']],
    ['POST', 'administration/atelier/apercu', ['can:badges.gerer'], ['throttle:atelier']],
    ['POST', 'administration/atelier/personnes', ['can:badges.gerer', 'can:listes.importer'], []],
    ['POST', 'administration/atelier/preparer', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['PUT', 'administration/atelier/modeles', ['can:badges.gerer'], []],
    ['POST', 'administration/atelier/modeles/reinitialiser', ['can:badges.gerer'], ['throttle:atelier']],
    ['POST', 'administration/atelier/modeles/reprendre', ['can:badges.gerer'], ['throttle:atelier']],
    ['POST', 'administration/atelier/tirages', ['can:badges.gerer'], ['throttle:atelier']],
    ['GET', 'administration/atelier/tirages', ['can:badges.gerer'], []],
    ['GET', 'administration/atelier/tirages/{run}/document', ['can:badges.gerer'], []],
    ['POST', 'administration/atelier/tirages/{run}/demande', ['can:badges.gerer'], ['throttle:atelier']],
    ['POST', 'administration/atelier/tirages/{run}/confirmation', ['can:badges.gerer'], ['throttle:atelier']],
    ['GET', 'administration/atelier/alignement', ['can:badges.gerer'], []],
    ['GET', 'administration/badges', ['can:badges.gerer'], []],
    ['GET', 'administration/badges/supports', ['can:badges.gerer'], ['throttle:recherche']],
    ['GET', 'administration/badges/lots', ['can:badges.gerer'], []],
    ['GET', 'administration/badges/modeles', ['can:badges.gerer'], []],
    ['POST', 'administration/badges/modeles/apercu', ['can:badges.gerer'], []],
    ['POST', 'administration/badges/modeles/personnes', ['can:badges.gerer', 'can:listes.importer'], []],
    ['POST', 'administration/badges/modeles/preparer', ['can:badges.gerer', 'can:listes.importer'], []],
    ['PUT', 'administration/badges/modeles', ['can:badges.gerer'], []],
    ['GET', 'administration/badges/modeles/imprimer', ['can:badges.gerer'], []],
    ['POST', 'administration/badges/lots', ['can:badges.gerer'], []],
    ['POST', 'administration/badges/reservations', ['can:badges.gerer'], []],
    ['PUT', 'administration/badges/reglages', ['can:badges.gerer'], []],
    ['GET', 'administration/badges/etiquettes', ['can:badges.gerer'], []],
    ['GET', 'administration/badges/registre', ['can:badges.gerer'], []],
    ['POST', 'administration/badges/mise-a-disposition', ['can:badges.gerer'], ['throttle:titulaires']],
    ['GET', 'administration/badges/{badge}', ['can:badges.gerer'], []],
    ['GET', 'administration/habilitations', ['can:listes.importer'], []],
    ['GET', 'administration/acces', ['can:zones.gerer'], []],
    ['POST', 'administration/acces/correspondances', ['can:zones.gerer', 'can:postes.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/acces/correspondances/{mapping}/retirer', ['can:zones.gerer', 'can:postes.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/acces/habilitations', ['can:zones.gerer', 'can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/acces/habilitations/{entitlement}/suspendre', ['can:zones.gerer', 'can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/acces/habilitations/{entitlement}/reprendre', ['can:zones.gerer', 'can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/acces/habilitations/{entitlement}/retirer', ['can:zones.gerer', 'can:listes.importer'], ['throttle:titulaires']],
    ['PUT', 'administration/acces/reglage', ['can:zones.gerer', 'can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/habilitations', ['can:listes.importer', 'can:zones.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/habilitations/groupe', ['can:listes.importer', 'can:zones.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/habilitations/{entitlement}/retirer', ['can:listes.importer', 'can:zones.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/habilitations/zones/{zone}', ['can:zones.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/habilitations/confiance', ['can:postes.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/carte/revoquer', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/carte/remplacer', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['GET', 'administration/atelier/cartes', ['can:listes.importer', 'can:badges.gerer'], []],
    ['POST', 'administration/atelier/cartes', ['can:listes.importer', 'can:badges.gerer'], ['throttle:atelier']],
    ['GET', 'administration/titulaires/modeles', ['can:badges.gerer'], []],
    ['POST', 'administration/titulaires/modeles/{code}/apercu', ['can:badges.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/titulaires/modeles/{code}', ['can:categories.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/modeles/{code}/valider', ['can:categories.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/cartes/preparer-toutes', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/carte/activer', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/titulaires/modeles', ['can:categories.gerer'], ['throttle:titulaires']],
    ['GET', 'administration/titulaires/alignement', ['can:badges.gerer'], []],
    ['GET', 'administration/titulaires/{holder}/carte', ['can:listes.importer', 'can:badges.gerer'], []],
    ['POST', 'administration/titulaires/{holder}/carte', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/carte/imprimer', ['can:listes.importer', 'can:badges.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/portrait', ['can:listes.importer'], ['throttle:titulaires']],
    ['DELETE', 'administration/titulaires/{holder}/portrait', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/import/apercu', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/import/confirmer', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/import/abandonner', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/titulaires/import/modele', ['can:listes.importer'], []],
    ['POST', 'administration/titulaires/groupe/categorie', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/groupe/cloturer', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/titulaires/{holder}/portrait', ['can:listes.importer'], []],
    ['GET', 'administration/titulaires', ['can:listes.importer'], []],
    ['POST', 'administration/titulaires', ['can:listes.importer'], ['throttle:titulaires']],
    ['PUT', 'administration/titulaires/format', ['can:categories.gerer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/contexte-permanent', ['can:evenements.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/titulaires/{holder}', ['can:listes.importer'], ['throttle:titulaires']],
    ['POST', 'administration/titulaires/{holder}/cloturer', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/categories-institutionnelles', ['can:categories.gerer'], []],
    ['POST', 'administration/categories-institutionnelles/initialiser', ['can:categories.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/categories-institutionnelles/liaison', ['can:categories.gerer'], ['throttle:titulaires']],
    ['PUT', 'administration/categories-institutionnelles/{code}', ['can:categories.gerer'], ['throttle:titulaires']],
];

/** Routes ajoutées volontairement par la consolidation (déclarées ici pour rester visibles). */
const CN_ADDED = [
    ['PUT', 'administration/personnes/{person}', ['can:listes.importer'], ['throttle:titulaires']],
    ['GET', 'administration/modeles', ['can:badges.gerer'], []],
    ['GET', 'administration/site', ['can:postes.gerer'], []],
    ['PUT', 'administration/site', ['can:postes.gerer'], []],
    ['POST', 'administration/site/postes', ['can:postes.gerer'], []],
    ['PUT', 'administration/site/postes/{checkpoint}', ['can:postes.gerer'], []],
    ['POST', 'administration/site/postes/{checkpoint}/activation', ['can:postes.gerer'], []],
    ['POST', 'administration/site/tablettes/{device}/affectation', ['can:postes.gerer'], []],
    // Badges et cartes : contrat d'impression unique des supports émis (récapitulatif, aperçu, impression).
    ['GET', 'administration/badges/preparer', ['can:badges.gerer', 'can:listes.importer'], []],
    ['GET', 'administration/badges/preparer/personnes', ['can:badges.gerer', 'can:listes.importer'], ['throttle:recherche']],
    ['POST', 'administration/badges/preparer/recapitulatif', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['POST', 'administration/badges/preparer/generer', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['POST', 'administration/badges/impression/recapitulatif', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['POST', 'administration/badges/impression/donnees', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['POST', 'administration/badges/impression/apercu', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
    ['POST', 'administration/badges/impression', ['can:badges.gerer', 'can:listes.importer'], ['throttle:atelier']],
];

function cnUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif CN', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function cnScope(): array
{
    return ['badges', 'atelier', 'titulaires', 'habilitations', 'acces', 'categories-institutionnelles', 'personnes', 'imports', 'invites', 'participations', 'modeles', 'site'];
}

/** @return list<string> */
function cnActualRoutes(): array
{
    $rows = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        $parts = explode('/', $uri);
        if (($parts[0] ?? '') !== 'administration' || ! in_array($parts[1] ?? '', cnScope(), true)) {
            continue;
        }
        $middleware = array_values(array_unique(array_filter($route->gatherMiddleware(), 'is_string')));
        $can = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:')));
        $throttle = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'throttle:')));
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $rows[] = cnLine([$method, $uri, $can, $throttle]);
            }
        }
    }
    sort($rows);

    return $rows;
}

/** @param array{0: string, 1: string, 2: list<string>, 3: list<string>} $row */
function cnLine(array $row): string
{
    return $row[0].' '.$row[1].' | '.implode(',', $row[2]).' | '.implode(',', $row[3]);
}

function cnExpectedRoutes(): array
{
    $rows = array_map('cnLine', [...CN_ROUTES, ...CN_ADDED]);
    sort($rows);

    return $rows;
}

function cnUrl(string $uri): string
{
    return '/'.preg_replace('/\{[a-z]+\}/', '00000000-0000-4000-8000-000000000000', str_replace('{code}', 'AILCT-01', $uri));
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
});

it('conserve exactement les routes d’administration des domaines concernés, avec leurs permissions et limitations', function () {
    expect(cnActualRoutes())->toBe(cnExpectedRoutes());
});

it('refuse à tout compte non administrateur chaque route de ces domaines, côté serveur', function () {
    foreach (['superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $this->actingAs(cnUser($role));
        foreach ([...CN_ROUTES, ...CN_ADDED] as [$method, $uri]) {
            $this->call($method, cnUrl($uri))->assertForbidden();
        }
    }
});

it('renvoie à la connexion tout visiteur non authentifié sur chacune de ces routes', function () {
    foreach ([...CN_ROUTES, ...CN_ADDED] as [$method, $uri]) {
        $response = $this->call($method, cnUrl($uri));
        expect($response->getStatusCode())->toBe(302)->and((string) $response->headers->get('Location'))->toContain('/login');
    }
});

it('laisse l’administrateur atteindre chaque page de consultation de ces domaines', function () {
    $this->actingAs(cnUser('administrateur'));
    $reached = 0;
    foreach ([...CN_ROUTES, ...CN_ADDED] as [$method, $uri]) {
        if ($method !== 'GET') {
            continue;
        }
        $status = $this->call('GET', cnUrl($uri))->getStatusCode();
        expect($status, $uri)->not->toBe(403)->and($status, $uri)->not->toBe(401);
        $reached++;
    }
    expect($reached)->toBeGreaterThan(20);
});

it('garde les anciens liens et leurs paramètres : Atelier, étiquettes, anciens modèles', function () {
    $this->actingAs(cnUser('administrateur'));
    $this->get('/administration/atelier?mode=cartes&format=a4&from=1&to=5&cols=2&rows=3')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Atelier')->where('initial.format', 'a4')->where('initial.from', fn ($v) => (int) $v === 1)->where('initial.to', fn ($v) => (int) $v === 5)->where('initial.cols', fn ($v) => (int) $v === 2)->where('initial.rows', fn ($v) => (int) $v === 3));
    $this->get('/administration/badges/modeles?format=a4')->assertRedirect('/administration/atelier?format=a4');
    $this->get('/administration/badges/modeles')->assertRedirect('/administration/atelier');
    expect((string) $this->get('/administration/badges/etiquettes?batch='.DemoSeeder::id('batch'))->headers->get('Location'))->toContain('/administration/atelier?')->toContain('format=etiquettes');
});

it('conserve les contrats des documents imprimés : demande et confirmation de tirage, retours', function () {
    $this->actingAs(cnUser('administrateur'));
    $run = '00000000-0000-4000-8000-000000000000';
    // Les documents imprimés appellent ces deux routes par fetch : elles gardent leur adresse et leur méthode.
    foreach (['demande', 'confirmation'] as $action) {
        $status = $this->postJson("/administration/atelier/tirages/$run/$action", ['printed' => 0, 'spoiled' => 0])->getStatusCode();
        expect($status)->toBeIn([404, 422]);
    }
    expect(DB::table('badge_print_runs')->count())->toBe(0);
});

it('sert la page Modèles avec le modèle de badge de chaque activité et le modèle de carte choisi, sans droit nouveau', function () {
    $admin = cnUser('administrateur');
    app(InstitutionalCategories::class)->initialize($admin);
    $this->actingAs($admin);
    $this->get('/administration/modeles')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Modeles')->where('section', 'badges')->has('events')->has('preferences')->has('cards.categories', 8)->where('cards.canConfigure', true));
    $this->get('/administration/modeles?section=cartes&categorie=AILCT-02')->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', 'cartes')->where('cards.selected', 'AILCT-02'));
    $event = DemoSeeder::id('event');
    $this->get('/administration/modeles?event='.$event)->assertOk()->assertInertia(fn ($page) => $page->where('initialEvent', $event));
    // l'ancienne page « Personnalisation des cartes » renvoie vers l'espace Modèles en conservant la catégorie
    $this->get('/administration/titulaires/modeles?categorie=AILCT-02')->assertRedirect('/administration/modeles?section=cartes&categorie=AILCT-02');
});

it('fournit les compteurs du cadre seulement sur les pages de l’espace Badges et cartes', function () {
    $this->actingAs(cnUser('administrateur'));
    // D'abord une page hors de l'espace : les propriétés partagées s'accumulent dans un même processus de test, pas entre deux requêtes réelles.
    $this->get('/administration/postes')->assertOk()->assertInertia(fn ($page) => $page->missing('badgesCartes'));
    foreach (['/administration/badges', '/administration/titulaires', '/administration/atelier', '/administration/modeles'] as $url) {
        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->has('badgesCartes.visitors.available')->has('badgesCartes.visitors.to_return')->has('badgesCartes.cards.active')->has('badgesCartes.cards.holders'));
    }
});

it('compte les badges disponibles et les badges à restituer d’après les tables existantes', function () {
    $summary = app(BadgesCartesSummary::class)->get();
    expect($summary['visitors']['available'])->toBe((int) DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')->where('e.is_permanent', false)->where('b.status', 'Disponible')->count())
        ->and($summary['cards']['active'])->toBe((int) DB::table('holder_cards')->where('status', 'active')->count());
});
