<?php

namespace App\Console\Commands;

use App\Services\DataRetention;
use Illuminate\Console\Command;

final class PurgePersonalData extends Command
{
    protected $signature = 'donnees:purger {--confirmer : Exécuter les suppressions irréversibles}';

    protected $description = 'Purge les données échues après approbation des durées, depuis une console privilégiée';

    public function handle(DataRetention $retention): int
    {
        if (! $this->option('confirmer')) {
            $this->error('Simulation : aucune suppression. Durées en jours : '.json_encode($retention->settings()));
            $this->line('Utiliser --confirmer après validation du client et sauvegarde contrôlée.');

            return self::SUCCESS;
        }
        try {
            $counts = $retention->purge();
            $this->info('Purge terminée : '.json_encode($counts));
        } catch (\Throwable) {
            $this->error('Purge arrêtée. Vérifiez le compte privilégié, les durées approuvées et les fichiers privés.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
