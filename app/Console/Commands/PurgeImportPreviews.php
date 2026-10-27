<?php

namespace App\Console\Commands;

use App\Services\Imports\ListImport;
use Illuminate\Console\Command;

final class PurgeImportPreviews extends Command
{
    protected $signature = 'imports:purge';

    protected $description = 'Supprime les aperçus privés expirés après une heure.';

    public function handle(ListImport $imports): int
    {
        $count = $imports->purge();
        $this->info($count.' aperçu(s) supprimé(s).');

        return self::SUCCESS;
    }
}
