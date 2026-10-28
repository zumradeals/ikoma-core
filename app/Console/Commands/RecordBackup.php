<?php

namespace App\Console\Commands;

use App\Services\BackupStatus;
use Illuminate\Console\Command;

final class RecordBackup extends Command
{
    protected $signature = 'sauvegarde:enregistrer {resultat : ok ou echec}';

    protected $description = 'Enregistre le résultat de la dernière sauvegarde (appelé par scripts/backup.sh).';

    public function handle(BackupStatus $status): int
    {
        $result = (string) $this->argument('resultat');
        if (! in_array($result, ['ok', 'echec'], true)) {
            $this->error('Le résultat doit être « ok » ou « echec ».');

            return self::INVALID;
        }
        $status->record($result === 'ok');
        $this->info('Résultat de sauvegarde enregistré : '.$result);

        return self::SUCCESS;
    }
}
