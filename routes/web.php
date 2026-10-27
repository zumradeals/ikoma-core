<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\BadgesController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ListImportsController;
use App\Http\Controllers\ZonesController;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');
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

        Route::get('/espace', fn () => Inertia::render('Space'))->name('space');
        Route::get('/administration/comptes', [AccountsController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/comptes', [AccountsController::class, 'store'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/inactivite', [AccountsController::class, 'inactivity'])->middleware('can:comptes.gerer');
        Route::put('/administration/comptes/{user}', [AccountsController::class, 'update'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/{user}/mot-de-passe', [AccountsController::class, 'password'])->middleware('can:comptes.gerer');
        Route::get('/administration/journal', JournalController::class)->middleware('can:journal.consulter');
    });
});
