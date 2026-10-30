<?php

namespace App\Console\Commands;

use App\Services\DataRetention;
use App\Services\FileDeletionQueue;
use App\Services\Maintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProcessMaintenance extends Command
{
    protected $signature = 'maintenance:traiter {--sauvegarde= : Manifeste local de sauvegarde vérifiable}';

    protected $description = 'Traite les demandes bornées de maintenance avec le compte privilégié distinct';

    public function handle(): int
    {
        app(DataRetention::class)->requirePrivileged();
        $failed = false;
        foreach (DB::table('maintenance_requests')->where('status', 'en_attente')->orderBy('created_at')->pluck('id') as $id) {
            try {
                app(Maintenance::class)->execute($id, (string) $this->option('sauvegarde'));
            } catch (\Throwable $e) {
                report($e);
                $this->error('Demande refusée : '.$id);
                $failed = true;
            }
        }
        $remaining = app(FileDeletionQueue::class)->drain();
        if ($remaining === 0) {
            DB::table('maintenance_requests')->whereIn('status', ['base_validee', 'fichiers_a_reprendre'])->update(['status' => 'terminee', 'updated_at' => now()]);
        }
        $this->info('Fichiers restant à traiter : '.$remaining);

        return $failed || $remaining ? self::FAILURE : self::SUCCESS;
    }
}
