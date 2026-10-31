<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACCESS-FOUNDATION-001 : le droit d'utiliser F01-F09 est permanent pendant la
 * licence standard ; seul F06 possède un état d'exploitation modifiable.
 *
 * La valeur absente/invalide signifie OFF (échec fermé), sans jamais retirer
 * le droit contractuel ni modifier les données des neuf modules.
 */
final class FoundingModules
{
    public const F06_KEY = 'module_f06_active';

    public const MODULES = [
        'F01' => 'Administration et configuration',
        'F02' => 'Gestion des personnes et des listes',
        'F03' => 'Gestion des badges',
        'F04' => 'Poste d’accueil',
        'F05' => 'Contrôle des accès et sorties',
        'F06' => 'Groupes et cars',
        'F07' => 'Pilotage et supervision',
        'F08' => 'Espace hôte',
        'F09' => 'Audit, conservation et traçabilité',
    ];

    public function f06Active(): bool
    {
        return DB::table('settings')->where('key', self::F06_KEY)->value('value') === '1';
    }

    public function requireF06(): void
    {
        if (! $this->f06Active()) {
            throw new DeskRefused('module_f06_inactif', 'Groupes et cars (F06) est désactivé par l’administrateur. Les autres modules restent accessibles.', 403);
        }
    }

    /** @return list<array{id:string,name:string,included:bool,active:bool,toggleable:bool}> */
    public function catalog(): array
    {
        $enabled = $this->f06Active();

        return collect(self::MODULES)->map(fn (string $name, string $id): array => [
            'id' => $id, 'name' => $name, 'included' => true,
            'active' => $id === 'F06' ? $enabled : true,
            'toggleable' => $id === 'F06',
        ])->values()->all();
    }

    /**
     * Pas d'effacement, pas de bascule à chaud de groupes déjà engagés.
     * Le verrou sur le réglage sérialise les décisions administratives.
     */
    public function setF06(bool $active, User $actor): void
    {
        DB::transaction(function () use ($active, $actor): void {
            $row = DB::table('settings')->where('key', self::F06_KEY)->lockForUpdate()->first();
            if ($row === null) {
                throw ValidationException::withMessages(['module' => 'État F06 absent : appliquez les migrations avant de modifier les modules.']);
            }
            $previous = $row->value === '1';
            if ($previous === $active) {
                return;
            }
            if (! $active) {
                $ongoing = DB::table('groups')
                    ->whereNotNull('opened_at')->whereNull('arrival_closed_at')
                    ->exists();
                if ($ongoing) {
                    throw ValidationException::withMessages(['module' => 'Impossible de désactiver F06 : des groupes sont engagés et leur arrivée n’est pas clôturée. Terminez les opérations et synchronisez les tablettes.']);
                }
            }
            DB::table('settings')->where('key', self::F06_KEY)->update(['value' => $active ? '1' : '0']);
            app(Audit::class)->append('module.f06.exploitation', $actor->id, [
                'module' => 'F06', 'old' => $previous, 'new' => $active, 'contractually_included' => true,
            ]);
        });
    }
}
