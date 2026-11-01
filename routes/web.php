<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminVisitorsController;
use App\Http\Controllers\BadgeDetailsController;
use App\Http\Controllers\BadgeLabelsController;
use App\Http\Controllers\BadgeLotsController;
use App\Http\Controllers\BadgeReservationsController;
use App\Http\Controllers\BadgesController;
use App\Http\Controllers\BadgeSettingsController;
use App\Http\Controllers\BadgeWorkshopController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\ControleController;
use App\Http\Controllers\DataManagementController;
use App\Http\Controllers\DemoLabController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\GroupesController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HostController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JournalExportController;
use App\Http\Controllers\ListImportsController;
use App\Http\Controllers\ModulesController;
use App\Http\Controllers\MonGroupeController;
use App\Http\Controllers\PilotageController;
use App\Http\Controllers\PosteApiController;
use App\Http\Controllers\PosteController;
use App\Http\Controllers\PostesController;
use App\Http\Controllers\PosteSyncController;
use App\Http\Controllers\PresencesController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\TwoFactorRecoveryController;
use App\Http\Controllers\ZonesController;
use App\Http\Middleware\EnsureAgentAccueil;
use App\Http\Middleware\EnsureF06Active;
use App\Http\Middleware\ResolvePoste;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/sante', HealthController::class)->middleware('throttle:sante');
Route::get('/installation', [InstallationController::class, 'show'])->middleware('throttle:installation-lecture');
Route::post('/installation', [InstallationController::class, 'store'])->middleware('throttle:installation-ecriture');
Route::get('/', fn () => Inertia::render('Home'))->name('home');
// Coquille publique du poste, sans aucune donnée : précachée par le service worker pour rouvrir /poste sans réseau (elle ne contient ni session ni nom).
Route::get('/poste/shell', fn () => Inertia::render('Poste'));
Route::get('/controle/shell', fn () => Inertia::render('Controle'));
Route::get('/groupe/shell', fn () => Inertia::render('Groupe'));
Route::get('/pilotage/evacuation/shell', fn () => Inertia::render('Pilotage/Evacuation'));
Route::middleware(['auth'])->group(function (): void {
    // 2FA disabled: old security entry redirects to normal authenticated space.
    Route::get('/securite', fn () => redirect('/espace'))->name('security');
    Route::group([], function (): void {
        Route::get('/hote/invites', [HostController::class, 'index'])->middleware('can:listes.mettre_a_jour');
        Route::post('/hote/invites', [HostController::class, 'store'])->middleware('can:listes.mettre_a_jour');
        Route::get('/hote/invites/{visitor}', [HostController::class, 'show'])->whereUuid('visitor')->middleware('can:listes.mettre_a_jour');
        Route::put('/hote/invites/{visitor}', [HostController::class, 'update'])->whereUuid('visitor')->middleware('can:listes.mettre_a_jour');
        Route::delete('/hote/invites/{visitor}', [HostController::class, 'destroy'])->whereUuid('visitor')->middleware('can:listes.mettre_a_jour');
        Route::get('/hote/notifications', [HostController::class, 'notifications'])->middleware('can:arrivees.recevoir_notification');
        Route::post('/hote/notifications/{notification}/lue', [HostController::class, 'read'])->whereUuid('notification')->middleware('can:arrivees.recevoir_notification');
        Route::post('/hote/notifications/{notification}/decision', [HostController::class, 'decide'])->whereUuid('notification')->middleware('can:visiteurs.valider_non_liste');
        Route::put('/administration/comptes/delai-hote', [AccountsController::class, 'hostTimeout'])->middleware('can:comptes.gerer');
        Route::put('/administration/comptes/{user}/hotes', [AccountsController::class, 'hosts'])->whereUuid('user')->middleware('can:comptes.gerer');
        Route::get('/api/presences', PresencesController::class)->middleware('can:presences.consulter');
        Route::get('/pilotage', [PilotageController::class, 'index'])->middleware('can:pilotage.consulter');
        Route::get('/pilotage/alertes', [PilotageController::class, 'alertes'])->middleware('can:alertes.consulter');
        Route::post('/pilotage/alertes/{alert}/prendre-en-compte', [PilotageController::class, 'prendreEnCompte'])->whereUuid('alert')->middleware('can:alertes.consulter');
        Route::get('/pilotage/presents', [PilotageController::class, 'presents'])->middleware('can:presences.consulter');
        Route::get('/pilotage/visiteurs/{visitor}', [PilotageController::class, 'visiteur'])->whereUuid('visitor')->middleware('can:pilotage.consulter');
        Route::post('/pilotage/badges/annuler', [PilotageController::class, 'annulerBadge'])->middleware('can:badges.annuler');
        Route::get('/pilotage/litiges', [PilotageController::class, 'litiges'])->middleware('can:litiges.decider');
        Route::post('/pilotage/litiges/{refusal}/decision', [PilotageController::class, 'decider'])->whereUuid('refusal')->middleware('can:litiges.decider');
        Route::get('/pilotage/groupes', [PilotageController::class, 'groupes'])->middleware(['can:groupes.suivre', 'can:cars.suivre', EnsureF06Active::class]);
        Route::get('/pilotage/evacuation', [PilotageController::class, 'evacuation'])->middleware('can:presences.consulter');
        Route::get('/pilotage/api/evacuation', [PilotageController::class, 'evacuationData'])->middleware('can:presences.consulter');
        Route::get('/administration/invites', [AdminVisitorsController::class, 'index'])->middleware('can:listes.importer');
        Route::post('/administration/invites', [AdminVisitorsController::class, 'store'])->middleware('can:listes.importer');
        Route::post('/administration/invites/{visitor}', [AdminVisitorsController::class, 'store'])->whereUuid('visitor')->middleware('can:listes.importer');
        Route::delete('/administration/invites/{visitor}', [AdminVisitorsController::class, 'destroy'])->whereUuid('visitor')->middleware('can:listes.importer');
        Route::get('/administration/invites/{visitor}/portrait', [AdminVisitorsController::class, 'portrait'])->whereUuid('visitor')->middleware('can:listes.importer');
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
        Route::get('/administration/badges/modeles', [BadgeWorkshopController::class, 'index'])->middleware('can:badges.gerer');
        Route::post('/administration/badges/modeles/apercu', [BadgeWorkshopController::class, 'preview'])->middleware('can:badges.gerer');
        Route::post('/administration/badges/modeles/personnes', [BadgeWorkshopController::class, 'people'])->middleware(['can:badges.gerer', 'can:listes.importer']);
        Route::post('/administration/badges/modeles/preparer', [BadgeWorkshopController::class, 'prepare'])->middleware(['can:badges.gerer', 'can:listes.importer']);
        Route::put('/administration/badges/modeles', [BadgeWorkshopController::class, 'save'])->middleware('can:badges.gerer');
        Route::get('/administration/badges/modeles/imprimer', [BadgeWorkshopController::class, 'print'])->middleware('can:badges.gerer');
        Route::post('/administration/badges/lots', [BadgeLotsController::class, 'store'])->middleware('can:badges.gerer');
        Route::post('/administration/badges/reservations', [BadgeReservationsController::class, 'store'])->middleware('can:badges.gerer');
        Route::put('/administration/badges/reglages', [BadgeSettingsController::class, 'update'])->middleware('can:badges.gerer');
        Route::get('/administration/badges/etiquettes', BadgeLabelsController::class)->middleware('can:badges.gerer');
        Route::get('/administration/badges/{badge}', BadgeDetailsController::class)->whereUuid('badge')->middleware('can:badges.gerer');

        Route::middleware(EnsureAgentAccueil::class)->group(function (): void {
            Route::get('/poste', PosteController::class)->middleware('can:visiteurs.rechercher');
            Route::get('/controle', ControleController::class)->middleware('can:badges.controler');
            Route::get('/groupe', fn () => Inertia::render('Groupe'))->middleware(['can:groupes.appel_nominal', EnsureF06Active::class]);
            Route::get('/poste/api/postes', [PosteApiController::class, 'postes'])->middleware('can:visiteurs.rechercher');
            Route::post('/poste/api/enroler', [PosteSyncController::class, 'enroler'])->middleware('can:visiteurs.rechercher');
            Route::middleware(ResolvePoste::class)->group(function (): void {
                Route::get('/poste/api/liste', [PosteSyncController::class, 'liste'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/liste/version', [PosteSyncController::class, 'version'])->middleware('can:visiteurs.rechercher');
                Route::post('/poste/api/sync', [PosteSyncController::class, 'sync'])->middleware(['can:visiteurs.rechercher', 'throttle:sync']);
                Route::post('/poste/api/efface', [PosteSyncController::class, 'efface'])->middleware('can:visiteurs.rechercher');
                Route::get('/poste/api/contexte', [PosteApiController::class, 'contexte'])->middleware('can:visiteurs.rechercher');
                Route::post('/poste/api/controle', [PosteApiController::class, 'controle'])->middleware('can:badges.controler');
                Route::post('/poste/api/sortie', [PosteApiController::class, 'sortie'])->middleware('can:badges.controler');
                Route::get('/poste/api/presents', [PosteApiController::class, 'presents'])->middleware('can:badges.controler');
                Route::get('/poste/api/recherche', [PosteApiController::class, 'recherche'])->middleware(['can:visiteurs.rechercher', 'throttle:recherche']);
                Route::get('/poste/api/visiteurs/{visitor}', [PosteApiController::class, 'fiche'])->middleware('can:visiteurs.verifier');
                Route::post('/poste/api/identite', [PosteApiController::class, 'identite'])->middleware('can:visiteurs.verifier');
                Route::post('/poste/api/avis-hote', [PosteApiController::class, 'avisHote'])->middleware('can:visiteurs.valider');
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

        Route::get('/espace', function (Request $request) {
            if ($request->user()->hasRole('administrateur')) {
                return redirect('/administration');
            }
            if ($request->user()->hasRole('superviseur')) {
                return redirect('/pilotage');
            }

            return Inertia::render('Space');
        })->name('space');
        Route::get('/administration/demonstration', [DemoLabController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/demonstration/generer', [DemoLabController::class, 'generate'])->middleware(['can:comptes.gerer', 'throttle:5,1']);
        Route::post('/administration/demonstration/remettre-a-zero', [DemoLabController::class, 'reset'])->middleware(['can:comptes.gerer', 'throttle:3,1']);
        Route::post('/administration/demonstration/{event}/nettoyer', [DemoLabController::class, 'clear'])->whereUuid('event')->middleware(['can:comptes.gerer', 'throttle:3,1']);
        Route::get('/administration/modules', [ModulesController::class, 'index'])->middleware('can:comptes.gerer');
        Route::put('/administration/modules/F06', [ModulesController::class, 'update'])->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::get('/administration', AdminDashboardController::class)->middleware('can:evenements.gerer');
        Route::get('/administration/evenements', [EventsController::class, 'index'])->middleware('can:evenements.gerer');
        Route::post('/administration/evenements', [EventsController::class, 'store'])->middleware('can:evenements.gerer');
        Route::post('/administration/comptes/{user}/2fa/preparer', [TwoFactorRecoveryController::class, 'prepare'])->whereUuid('user')->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::post('/administration/comptes/{user}/2fa/confirmer', [TwoFactorRecoveryController::class, 'confirm'])->whereUuid('user')->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::get('/administration/comptes', [AccountsController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/comptes', [AccountsController::class, 'store'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/inactivite', [AccountsController::class, 'inactivity'])->middleware('can:comptes.gerer');
        Route::put('/administration/comptes/{user}', [AccountsController::class, 'update'])->middleware(['can:comptes.gerer', 'can:roles.attribuer']);
        Route::put('/administration/comptes/{user}/mot-de-passe', [AccountsController::class, 'password'])->middleware('can:comptes.gerer');
        Route::get('/administration/donnees', [DataManagementController::class, 'index'])->middleware('can:comptes.gerer');
        Route::post('/administration/donnees/analyser', [DataManagementController::class, 'maintenancePreview'])->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::post('/administration/donnees/demander', [DataManagementController::class, 'maintenanceRequest'])->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::post('/administration/donnees/supprimer', [DataManagementController::class, 'destroy'])->middleware(['can:comptes.gerer', 'throttle:6,1']);
        Route::put('/administration/evenements/{event}', [EventsController::class, 'update'])->whereUuid('event')->middleware('can:evenements.gerer');
        Route::post('/administration/evenements/{event}/{action}', [DataManagementController::class, 'transition'])->whereUuid('event')->whereIn('action', ['cloturer', 'archiver'])->middleware('can:evenements.gerer');
        Route::get('/administration/conservation', [RetentionController::class, 'index'])->middleware('can:comptes.gerer');
        Route::put('/administration/conservation', [RetentionController::class, 'update'])->middleware('can:comptes.gerer');
        Route::get('/administration/journal/exporter', JournalExportController::class)->middleware(['can:journal.exporter', 'throttle:exports']);
        Route::get('/administration/journal', JournalController::class)->middleware('can:journal.consulter');
        Route::get('/administration/groupes', [GroupesController::class, 'index'])->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::post('/administration/groupes', [GroupesController::class, 'store'])->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::put('/administration/groupes/reglage', [GroupesController::class, 'mode'])->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::put('/administration/groupes/{group}/encadreur', [GroupesController::class, 'designate'])->whereUuid('group')->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::post('/administration/groupes/{group}/stagiaires', [GroupesController::class, 'attach'])->whereUuid('group')->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::delete('/administration/groupes/{group}/stagiaires/{visitor}', [GroupesController::class, 'detach'])->whereUuid(['group', 'visitor'])->middleware(['can:groupes.gerer', EnsureF06Active::class]);
        Route::get('/mon-groupe', [MonGroupeController::class, 'index'])->middleware(['can:groupes.confirmer_presence', EnsureF06Active::class]);
        Route::post('/mon-groupe/{visitor}/confirmer', [MonGroupeController::class, 'confirm'])->whereUuid('visitor')->middleware(['can:groupes.confirmer_presence', EnsureF06Active::class]);
    });
});
