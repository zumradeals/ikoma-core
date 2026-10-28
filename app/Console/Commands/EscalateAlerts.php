<?php

namespace App\Console\Commands;

use App\Services\Alerts;
use Illuminate\Console\Command;

final class EscalateAlerts extends Command
{
    protected $signature = 'alertes:escalader';

    protected $description = 'Remonte à l’administrateur les alertes critiques ou hautes sans prise en compte dans le délai réglé.';

    public function handle(Alerts $alerts): int
    {
        $this->info($alerts->escalate().' alerte(s) escaladée(s).');

        return self::SUCCESS;
    }
}
