<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * État de la dernière sauvegarde, tel que l'enregistre scripts/backup.sh (commande `sauvegarde:enregistrer`). Aucune donnée personnelle :
 * une date et un résultat. Le suivi n'est actif qu'après l'installation de production (`sauvegarde_active`).
 */
final class BackupStatus
{
    public function __construct(private readonly Audit $audit) {}

    private function setting(string $key): string
    {
        return (string) (DB::table('settings')->where('key', $key)->value('value') ?? '');
    }

    public function enabled(): bool
    {
        return $this->setting('sauvegarde_active') === '1';
    }

    public function record(bool $success): void
    {
        $now = now();
        DB::transaction(function () use ($success, $now): void {
            DB::table('settings')->where('key', 'sauvegarde_dernier_resultat')->update(['value' => $success ? 'ok' : 'echec']);
            if ($success) {
                DB::table('settings')->where('key', 'sauvegarde_derniere_reussite')->update(['value' => $now->toIso8601String()]);
            }
            $this->audit->append($success ? 'sauvegarde.reussie' : 'sauvegarde.echouee', null, []);
        });
    }

    /**
     * @return array{etat:string,derniere_reussite:?string,age_heures:?int,seuil_heures:int}
     *                                                                                       etat : « inactif » (suivi non activé), « jamais », « ok », « ancienne » (au-delà du seuil) ou « echec » (la dernière tentative a échoué).
     */
    public function state(): array
    {
        $threshold = max(1, (int) ($this->setting('sauvegarde_alerte_heures') ?: 26));
        if (! $this->enabled()) {
            return ['etat' => 'inactif', 'derniere_reussite' => null, 'age_heures' => null, 'seuil_heures' => $threshold];
        }
        $last = $this->setting('sauvegarde_derniere_reussite');
        $age = $last === '' ? null : (int) floor(Carbon::parse($last)->diffInMinutes(now()) / 60);
        $etat = match (true) {
            $this->setting('sauvegarde_dernier_resultat') === 'echec' => 'echec',
            $last === '' => 'jamais',
            $age > $threshold => 'ancienne',
            default => 'ok',
        };

        return ['etat' => $etat, 'derniere_reussite' => $last ?: null, 'age_heures' => $age, 'seuil_heures' => $threshold];
    }
}
