<?php

namespace App\Console\Commands;

use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyAudit extends Command
{
    protected $signature = 'audit:verify';

    protected $description = "Vérifie l'intégrité de la chaîne du journal";

    public function handle(Audit $audit): int
    {
        $previous = str_repeat('0', 64);
        foreach (DB::table('audit_logs')->orderBy('id')->cursor() as $row) {
            if ($row->previous_hash !== $previous || ! hash_equals($row->hash, $audit->digest($previous, $row->actor_id, $row->action, $row->occurred_at, $row->details))) {
                $this->error('Rupture du journal à la ligne '.$row->id);

                return self::FAILURE;
            }
            $previous = $row->hash;
        }
        $this->info('Chaîne du journal intacte.');

        return self::SUCCESS;
    }
}
