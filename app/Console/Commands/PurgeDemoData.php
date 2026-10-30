<?php

namespace App\Console\Commands;

use App\Services\DemoCleanup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retire uniquement le jeu fixe créé par DemoSeeder et ses comptes nominaux
 * seedés. Une opération réelle enregistrée entraîne un refus intégral.
 */
final class PurgeDemoData extends Command
{
    protected $signature = 'demo:purger {--confirmer : Exécuter le nettoyage après sauvegarde vérifiée}';

    protected $description = 'Inspecte puis retire le jeu de démonstration IKOMA sans purger les données réelles';

    public function handle(): int
    {
        try {
            $counts = app(DemoCleanup::class)->run((bool) $this->option('confirmer'));
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->info($this->option('confirmer') ? 'Jeu fictif retiré. Les autres événements, utilisateurs et le journal d’audit sont conservés.' : 'Simulation uniquement. Réalisez une sauvegarde complète, créez un administrateur réel avec 2FA, puis relancez avec --confirmer.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Purge interrompue : '.$e->getMessage());
            $this->line('Aucune suppression partielle n’est validée : transaction annulée.');

            return self::FAILURE;
        }
    }
}
