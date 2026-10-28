<?php

namespace App\Console\Commands;

use App\Services\TwoFactorRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmergencyTwoFactor extends Command
{
    protected $signature = 'comptes:bris-de-glace {user : UUID du dernier administrateur} {--confirmation= : Saisir REINITIALISER-2FA}';

    protected $description = 'Réinitialise le 2FA du dernier administrateur, uniquement depuis une console privilégiée';

    public function handle(TwoFactorRecovery $recovery): int
    {
        if ($this->option('confirmation') !== 'REINITIALISER-2FA' || ! Str::isUuid((string) $this->argument('user')) || ! DB::selectOne("SELECT has_table_privilege(current_user, 'audit_logs', 'DELETE') AS allowed")->allowed) {
            $this->error('Console privilégiée, UUID et confirmation explicite requis.');

            return self::FAILURE;
        }
        try {
            $recovery->emergency((string) $this->argument('user'));
        } catch (\Throwable) {
            $this->error('Réinitialisation refusée. Vérifiez la condition du dernier administrateur actif.');

            return self::FAILURE;
        }
        $this->info('2FA réinitialisé et sessions invalidées. Reconnectez-vous pour activer un nouveau 2FA.');

        return self::SUCCESS;
    }
}
