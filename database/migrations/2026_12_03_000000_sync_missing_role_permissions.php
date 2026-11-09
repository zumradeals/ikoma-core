<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rattrape les permissions de rôle ajoutées à config/roles.php après l'installation.
 *
 * Les rôles ne sont synchronisés qu'à l'installation : une instance déjà installée ne reçoit pas les permissions ajoutées plus tard (ex. supervision, présences et alertes pour
 * l'administrateur), et l'accès aux écrans correspondants (Opérations) lui est refusé après une mise à jour.
 *
 * ADDITIF seulement : une permission manquante est ajoutée à un rôle EXISTANT ; aucune n'est retirée, aucun rôle n'est créé, rien d'autre n'est modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (config('roles') as $name => $permissions) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            if ($role === null) {
                continue;
            }
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, 'web');
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Rien à défaire : l'ajout de permissions manquantes est conservé.
    }
};
