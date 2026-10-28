<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\BadgeLabelsController;
use App\Http\Controllers\BadgeLotsController;
use App\Http\Controllers\BadgeReservationsController;
use App\Http\Controllers\BadgesController;
use App\Http\Controllers\BadgeSettingsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ListImportsController;
use App\Http\Controllers\PosteApiController;
use App\Http\Controllers\PosteController;
use App\Http\Controllers\PostesController;
use App\Http\Controllers\PosteSyncController;
use App\Http\Controllers\ZonesController;
use App\Http\Middleware\EnsureAgentAccueil;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolvePoste;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');
// Coquille publique du poste, sans aucune donnée : précachée par le service worker pour rouvrir /poste sans réseau (elle ne contient ni session ni nom).
Route::get('/poste/shell', fn () => Inertia::render('Poste'));
Route::middleware(['auth'])->group(function (): void {
    Route::get('/securite', fn () => Inertia::render('Auth/Security', ['confirmed' => (bool) auth()->user()->two_factor_confirmed_at]))->middleware('password.confirm')->name('security');
    Route::middleware(RequireTwoFactor::class)->group(function (): void {
        Route::get('/administration/imports', [ListImportsController::class, 'index'])->middleware('can:listes.importer');
        Route::get('/administration/imports/modele', [ListImportsController::class, 'template'])->middleware('can:listes.importer');
        Route::post('/administration/imports/apercu', [ListImportsController::class, 'preview'])->middleware('can:listes.importer');
        Route::post('/administration/imports/confirmer', [ListImportsController::class, 'confirm'])->middleware('can:listes.importer');
        Route::post('/administration/imports/abandonner', [ListImportsController::class, 'abandon'])->middleware('can:listes.importer');
        Route::get('/administration/zones', [ZonesController::class, 'index'])->middleware('can:zones.gerer');
        Route::post('/administration/zones', [ZonesController::class, 'store'])->middleware('can:zones.gerer');
        Route::put('/administration/zones/{zone}', [ZonesController::class, 'update'])->middleware('can:zones.gerer');
        Route::get('/administration/categories', [CategoriesController::class, 'index'])->middleware('can:categories.gerer');
        Route::post('/administration/categories', [CategoriesController::class, 'store'])->middleware('can:categories.gerer');
        Route::put('/administration/categories/{category}', [CategoriesController::class, 'update'])->middleware('can:categories.gerer');
        Route::get('/administration/badges', BadgesController::class)->middleware('can:badges.gerer');
        Route::post('/administration/badges/lots', [BadgeLotsController::class, 'store'])->middleware('can:badges.gerer');
        Route::post('/administration/badges/reservations', [BadgeReservationsController::class, 'store'])->middleware('can:badges.gerer');
        Route::put('/administration/badges/reglages', [BadgeSettingsController::class, 'update'])->middleware('can:badges.gerer');
        Route::get('/administration/badges/etiquettes', BadgeLabelsController::class)->middleware('can:badges.gerer');

        Route::middleware(EnsureAgentAccueil::class)->group(function (): void {
            Route::get('/poste', PosteController::class)->middleware('can:visiteurs.rechercher');
            Route::get('/poste/api/postes', [PosteApiController::class, 'postes'])->middleware('can:visiteurs.rechercher');
            Route::post('/poste/api/enroler', [PosteSyncController::class, 'enroler'])->middleware('can:visiteurs.rechercher');
            Route::middleware(ResolvePoste::class)->group(function (): void {
                Route::get('/poste/api/liste', [PosteSyncController::class, 'liste'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/liste/version', [PosteSyncController::class, 'version'])->middleware('can:visiteurs.rechercher');
                Route::post('/poste/api/sync', [PosteSyncController::class, 'sync'])->middleware('can:visiteurs.rechercher');
                Route::post('/poste/api/efface', [PosteSyncController::class, 'efface'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/contexte', [PosteApiController::class, 'contexte'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/recherche', [PosteApiController::class, 'recherche'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/visiteurs/{visitor}', [PosteApiController::class, 'fiche'])->middleware('can:visiteurs.verifier');
                Route::post('/poste/api/identite', [PosteApiController::class, 'identite'])->middleware('can:visiteurs.verifier');
                Route::post('/poste/api/valider', [PosteApiController::class, 'valider'])->middleware('can:visiteurs.valider');
                Route::post('/poste/api/refuser', [PosteApiController::class, 'refuser'])->middleware('can:visiteurs.refuser');
                Route::post('/poste/api/badge', [PosteApiController::class, 'badge'])->middleware('can:badges.associer');
                Route::post('/poste/api/entree', [PosteApiController::class, 'entree'])->middleware('can:visiteurs.valider');
                Route::post('/poste/api/non-listes', [PosteApiController::class, 'nonListe'])->middleware('can:visiteurs.enregistrer_non_liste');
            });
        });

        Route::get('/administration/postes', [PostesController::class, 'index'])->middleware('can:postes.gerer');
        Route::post('/administration/postes', [PostesController::class, 'store'])->middleware('can:postes.gerer');
        Route::put('/administration/postes/reglages', [PostesController::class, 'settings'])->middleware('can:postes.gerer');
        Route::post('/administration/postes/{device}/code', [PostesController::class, 'code'])->middleware('can:postes.gerer');
        Route::post('/administration/postes/{device}/revoquer', [PostesController::class, 'revoke'])->middleware('can:postes.gerer');

        Route::get('/espace', fn () => Inertia::render('Space'))->name('space');
        Route::get('/administration/comptes', [AccountsController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/comptes', [AccountsController::class, 'store'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/inactivite', [AccountsController::class, 'inactivity'])->middleware('can:comptes.gerer');
        Route::put('/administration/comptes/{user}', [AccountsController::class, 'update'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/{user}/mot-de-passe', [AccountsController::class, 'password'])->middleware('can:comptes.gerer');
        Route::get('/administration/journal', JournalController::class)->middleware('can:journal.consulter');
    });
});
