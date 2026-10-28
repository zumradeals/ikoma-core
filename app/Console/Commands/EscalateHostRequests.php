<?php

namespace App\Console\Commands;

use App\Services\HostNotifications;
use Illuminate\Console\Command;

final class EscalateHostRequests extends Command
{
    protected $signature = 'hotes:relancer';

    protected $description = 'Signale au superviseur les demandes sans réponse dans le délai réglé.';

    public function handle(HostNotifications $n): int
    {
        $this->info($n->escalate().' demande(s) signalée(s).');

        return self::SUCCESS;
    }
}
