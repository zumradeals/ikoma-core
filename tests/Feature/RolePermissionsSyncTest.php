<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Mise à jour d'une instance déjà installée : les permissions ajoutées à config/roles.php après l'installation sont rattrapées (ajout seulement).
 */

uses(DatabaseTransactions::class);

it('rattrape les permissions manquantes d’un rôle existant sans rien retirer ni créer de rôle', function () {
    $this->seed(RoleSeeder::class);
    $admin = Role::findByName('administrateur', 'web');
    $admin->revokePermissionTo(['pilotage.consulter', 'presences.consulter']);
    $admin->givePermissionTo('listes.importer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('administrateur', 'web')->hasPermissionTo('pilotage.consulter'))->toBeFalse();
    $before = Role::count();

    (include database_path('migrations/2026_12_03_000000_sync_missing_role_permissions.php'))->up();

    $fresh = Role::findByName('administrateur', 'web');
    expect($fresh->hasPermissionTo('pilotage.consulter'))->toBeTrue()->and($fresh->hasPermissionTo('presences.consulter'))->toBeTrue()
        ->and($fresh->hasPermissionTo('listes.importer'))->toBeTrue()->and(Role::count())->toBe($before);
    // Rejouée : aucun changement.
    (include database_path('migrations/2026_12_03_000000_sync_missing_role_permissions.php'))->up();
    expect(Role::findByName('administrateur', 'web')->permissions->count())->toBe($fresh->permissions->count());
});

it('n’ajoute pas à un rôle une permission qui ne lui appartient pas dans la configuration', function () {
    $this->seed(RoleSeeder::class);
    (include database_path('migrations/2026_12_03_000000_sync_missing_role_permissions.php'))->up();
    expect(Role::findByName('agent_accueil', 'web')->hasPermissionTo('comptes.gerer'))->toBeFalse();
});
