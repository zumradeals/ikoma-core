<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\JournalController;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Home'))->name('home');
Route::middleware(['auth'])->group(function (): void {
    Route::get('/securite', fn () => Inertia::render('Auth/Security', ['confirmed' => (bool) auth()->user()->two_factor_confirmed_at]))->middleware('password.confirm')->name('security');
    Route::middleware(RequireTwoFactor::class)->group(function (): void {
        Route::get('/espace', fn () => Inertia::render('Space'))->name('space');
        Route::get('/administration/comptes', [AccountsController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/comptes', [AccountsController::class, 'store'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/inactivite', [AccountsController::class, 'inactivity'])->middleware('can:comptes.gerer');
        Route::put('/administration/comptes/{user}', [AccountsController::class, 'update'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/{user}/mot-de-passe', [AccountsController::class, 'password'])->middleware('can:comptes.gerer');
        Route::get('/administration/journal', JournalController::class)->middleware('can:journal.consulter');
    });
});
