<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

/*
 * V1 / PR 1 — garde-fous : caractérisation des routes d'administration non couvertes par ConsolidationNavigationTest et de toutes les routes du poste (`/poste/api`).
 * Elle fige leur existence, leurs permissions (`can:`) et leurs limitations (`throttle:`), et vérifie côté serveur que ces permissions sont bien appliquées par rôle.
 * Une route ajoutée ou modifiée doit être déclarée ici. Données fictives uniquement.
 */

const CV_ROUTES = [
    ['DELETE', 'administration/vehicules/{vehicle}', ['can:listes.importer'], []],
    ['PUT', 'administration/comptes/delai-hote', ['can:comptes.gerer'], []],
    ['PUT', 'administration/comptes/{user}/hotes', ['can:comptes.gerer'], []],
    ['GET', 'administration/zones', ['can:zones.gerer'], []],
    ['POST', 'administration/zones', ['can:zones.gerer'], []],
    ['PUT', 'administration/zones/{zone}', ['can:zones.gerer'], []],
    ['GET', 'administration/categories', ['can:categories.gerer'], []],
    ['POST', 'administration/categories', ['can:categories.gerer'], []],
    ['PUT', 'administration/categories/{category}', ['can:categories.gerer'], []],
    ['GET', 'poste/api/postes', ['can:visiteurs.rechercher'], []],
    ['POST', 'poste/api/enroler', ['can:visiteurs.rechercher'], []],
    ['GET', 'poste/api/liste', ['can:visiteurs.rechercher'], []],
    ['GET', 'poste/api/liste/version', ['can:visiteurs.rechercher'], []],
    ['POST', 'poste/api/sync', ['can:visiteurs.rechercher'], ['throttle:sync']],
    ['POST', 'poste/api/efface', ['can:visiteurs.rechercher'], []],
    ['GET', 'poste/api/contexte', ['can:visiteurs.rechercher'], []],
    ['POST', 'poste/api/controle', ['can:badges.controler'], []],
    ['POST', 'poste/api/sortie', ['can:badges.controler'], []],
    ['POST', 'poste/api/restitution', ['can:badges.controler', 'can:badges.associer'], []],
    ['POST', 'poste/api/restitution/verifier', ['can:badges.controler', 'can:badges.associer'], []],
    ['GET', 'poste/api/presents', ['can:badges.controler'], []],
    ['GET', 'poste/api/recherche', ['can:visiteurs.rechercher'], ['throttle:recherche']],
    ['GET', 'poste/api/visiteurs/{visitor}', ['can:visiteurs.verifier'], []],
    ['GET', 'poste/api/visiteurs/{visitor}/portrait', ['can:visiteurs.rechercher'], ['throttle:portrait']],
    ['POST', 'poste/api/identite', ['can:visiteurs.verifier'], []],
    ['POST', 'poste/api/avis-hote', ['can:visiteurs.valider'], []],
    ['POST', 'poste/api/valider', ['can:visiteurs.valider'], []],
    ['POST', 'poste/api/refuser', ['can:visiteurs.refuser'], []],
    ['POST', 'poste/api/badge', ['can:badges.associer'], []],
    ['POST', 'poste/api/entree', ['can:visiteurs.valider'], []],
    ['POST', 'poste/api/non-listes', ['can:visiteurs.enregistrer_non_liste'], []],
    ['GET', 'poste/api/attendus', ['can:visiteurs.rechercher'], []],
    ['GET', 'poste/api/incidents', ['can:visiteurs.rechercher'], []],
    ['GET', 'poste/api/vehicules', ['can:visiteurs.rechercher'], ['throttle:recherche']],
    ['POST', 'poste/api/vehicules/entree', ['can:visiteurs.valider'], []],
    ['POST', 'poste/api/vehicules/sortie', ['can:badges.controler'], []],
    ['GET', 'administration/postes', ['can:postes.gerer'], []],
    ['POST', 'administration/postes', ['can:postes.gerer'], []],
    ['PUT', 'administration/postes/reglages', ['can:postes.gerer'], []],
    ['POST', 'administration/postes/{device}/code', ['can:postes.gerer'], []],
    ['POST', 'administration/postes/{device}/revoquer', ['can:postes.gerer'], []],
    ['GET', 'administration/demonstration', ['can:comptes.gerer'], []],
    ['POST', 'administration/demonstration/generer', ['can:comptes.gerer'], ['throttle:5,1']],
    ['POST', 'administration/demonstration/remettre-a-zero', ['can:comptes.gerer'], ['throttle:3,1']],
    ['POST', 'administration/demonstration/{event}/nettoyer', ['can:comptes.gerer'], ['throttle:3,1']],
    ['GET', 'administration/identite', ['can:comptes.gerer'], []],
    ['PUT', 'administration/identite', ['can:comptes.gerer'], ['throttle:identite']],
    ['POST', 'administration/identite/logo', ['can:comptes.gerer'], ['throttle:identite']],
    ['DELETE', 'administration/identite/logo', ['can:comptes.gerer'], ['throttle:identite']],
    ['PUT', 'administration/identite/accueil', ['can:comptes.gerer'], ['throttle:identite']],
    ['POST', 'administration/identite/reinitialiser', ['can:comptes.gerer'], ['throttle:identite']],
    ['GET', 'administration/modules', ['can:comptes.gerer'], []],
    ['PUT', 'administration/modules/F06', ['can:comptes.gerer'], ['throttle:6,1']],
    ['GET', 'administration', ['can:evenements.gerer'], []],
    ['GET', 'administration/evenements', ['can:evenements.gerer'], []],
    ['GET', 'administration/operations', ['can:pilotage.consulter'], []],
    ['POST', 'administration/evenements', ['can:evenements.gerer'], []],
    ['GET', 'administration/comptes', ['can:comptes.gerer'], []],
    ['POST', 'administration/comptes', ['can:comptes.gerer', 'can:roles.attribuer'], []],
    ['PUT', 'administration/comptes/inactivite', ['can:comptes.gerer'], []],
    ['PUT', 'administration/comptes/{user}', ['can:comptes.gerer', 'can:roles.attribuer'], []],
    ['PUT', 'administration/comptes/{user}/mot-de-passe', ['can:comptes.gerer'], []],
    ['GET', 'administration/donnees', ['can:comptes.gerer'], []],
    ['POST', 'administration/donnees/analyser', ['can:comptes.gerer'], ['throttle:6,1']],
    ['POST', 'administration/donnees/demander', ['can:comptes.gerer'], ['throttle:6,1']],
    ['POST', 'administration/donnees/supprimer', ['can:comptes.gerer'], ['throttle:6,1']],
    ['PUT', 'administration/evenements/{event}', ['can:evenements.gerer'], []],
    ['POST', 'administration/evenements/{event}/{action}', ['can:evenements.gerer'], []],
    ['GET', 'administration/conservation', ['can:comptes.gerer'], []],
    ['PUT', 'administration/conservation', ['can:comptes.gerer'], []],
    ['GET', 'administration/journal/exporter', ['can:journal.exporter'], ['throttle:exports']],
    ['GET', 'administration/journal', ['can:journal.consulter'], []],
    ['GET', 'administration/groupes', ['can:groupes.gerer'], []],
    ['POST', 'administration/groupes', ['can:groupes.gerer'], []],
    ['PUT', 'administration/groupes/reglage', ['can:groupes.gerer'], []],
    ['PUT', 'administration/groupes/{group}/encadreur', ['can:groupes.gerer'], []],
    ['PUT', 'administration/groupes/{group}/capacite', ['can:groupes.gerer'], []],
    ['POST', 'administration/groupes/{group}/stagiaires', ['can:groupes.gerer'], []],
    ['DELETE', 'administration/groupes/{group}/stagiaires/{visitor}', ['can:groupes.gerer'], []],
];

/** Segments déjà caractérisés par ConsolidationNavigationTest. */
const CV_COVERED = ['badges', 'atelier', 'titulaires', 'habilitations', 'acces', 'categories-institutionnelles', 'personnes', 'imports', 'invites', 'participations', 'modeles', 'site'];

function cvUser(string $role): User
{
    $user = User::create(['name' => 'Compte fictif CV', 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24)]);
    $user->assignRole($role);
    $user->update(['two_factor_confirmed_at' => now()]);

    return $user;
}

function cvLine(string $method, string $uri, array $can, array $throttle): string
{
    return $method.' '.$uri.' | '.implode(',', $can).' | '.implode(',', $throttle);
}

/** @return list<string> */
function cvActual(): array
{
    $rows = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        $parts = explode('/', $uri);
        $isAdmin = $parts[0] === 'administration' && ! in_array($parts[1] ?? '', CV_COVERED, true);
        $isPoste = $parts[0] === 'poste' && ($parts[1] ?? '') === 'api';
        if (! $isAdmin && ! $isPoste) {
            continue;
        }
        $middleware = array_values(array_unique(array_filter($route->gatherMiddleware(), 'is_string')));
        $can = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:')));
        $throttle = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'throttle:')));
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                $rows[] = cvLine($method, $uri, $can, $throttle);
            }
        }
    }
    sort($rows);

    return $rows;
}

function cvUrl(string $uri): string
{
    return '/'.preg_replace('/\{[A-Za-z]+\}/', '00000000-0000-4000-8000-000000000000', $uri);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->travelTo('2027-01-10 10:00:00');
});

it('conserve exactement les routes d’administration restantes et toutes les routes du poste, avec leurs permissions et limitations', function () {
    $expected = array_map(fn (array $r): string => cvLine($r[0], $r[1], $r[2], $r[3]), CV_ROUTES);
    sort($expected);
    expect(cvActual())->toBe($expected);
});

it('applique côté serveur les permissions déclarées de chaque route d’administration, rôle par rôle', function () {
    // Une route à paramètre peut répondre 404 avant le contrôle de permission (liaison de modèle sur un identifiant fictif) : elle n'est jamais acceptée (jamais 2xx).
    $offenders = [];
    foreach (['administrateur', 'superviseur', 'agent_accueil', 'hote', 'encadreur'] as $role) {
        $user = cvUser($role);
        $this->actingAs($user);
        foreach (CV_ROUTES as [$method, $uri, $can]) {
            if (! str_starts_with($uri, 'administration') || $can === []) {
                continue;
            }
            $allowed = collect($can)->every(fn (string $c): bool => $user->can(substr($c, 4)));
            if ($allowed) {
                continue;
            }
            $status = $this->call($method, cvUrl($uri))->getStatusCode();
            // 429 : la limitation de débit d'une route s'applique avant le refus de rôle quand la même route est appelée plusieurs fois par le test.
            $expected = str_contains($uri, '{') ? [403, 404, 429] : [403, 429];
            if (! in_array($status, $expected, true)) {
                $offenders[] = $role.' '.$method.' '.$uri.' => '.$status;
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('ne laisse aucun rôle autre que l’agent d’accueil réussir une requête sur les routes du poste, administrateur compris', function () {
    // Le refus peut être un 403, un 404 (identifiant fictif) ou un 422 (validation avant le contrôle de rôle) : jamais un succès.
    $offenders = [];
    foreach (['administrateur', 'superviseur', 'hote', 'encadreur'] as $role) {
        $this->actingAs(cvUser($role));
        foreach (CV_ROUTES as [$method, $uri]) {
            if (str_starts_with($uri, 'poste/api')) {
                $status = $this->call($method, cvUrl($uri))->getStatusCode();
                if ($status < 400) {
                    $offenders[] = $role.' '.$method.' '.$uri.' => '.$status;
                }
            }
        }
    }
    expect($offenders)->toBe([]);
});

it('renvoie à la connexion tout visiteur non authentifié sur les routes caractérisées', function () {
    $offenders = [];
    foreach (CV_ROUTES as [$method, $uri]) {
        $status = $this->call($method, cvUrl($uri))->getStatusCode();
        // Une route à paramètre peut répondre 404 (liaison de modèle avant l'authentification) : jamais un succès.
        $ok = in_array($status, [302, 401], true) || (str_contains($uri, '{') && $status === 404);
        if (! $ok) {
            $offenders[] = $method.' '.$uri.' => '.$status;
        }
    }
    expect($offenders)->toBe([]);
});
