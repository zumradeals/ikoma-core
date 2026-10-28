<?php

namespace App\Http\Controllers;

use App\Services\BackupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Page de santé pour la surveillance : sans connexion, sans aucune donnée personnelle ni version (L12). Elle dit seulement si les éléments
 * essentiels fonctionnent : base de données, espace disque, dernière sauvegarde, conflits de synchronisation en attente.
 */
final class HealthController
{
    private const DISK_WARNING_PERCENT = 10;

    public function __invoke(BackupStatus $backups): JsonResponse
    {
        try {
            DB::selectOne('SELECT 1');
            $base = 'ok';
            $conflicts = (int) DB::table('sync_conflicts')->where('status', 'ouvert')->count();
            $backup = $backups->state();
        } catch (\Throwable) {
            return response()->json(['statut' => 'ko', 'base' => 'ko'], 503, ['Cache-Control' => 'no-store']);
        }
        $total = (float) @disk_total_space(storage_path());
        $free = (float) @disk_free_space(storage_path());
        $freePercent = $total > 0 ? (int) round($free / $total * 100) : null;
        $disk = $freePercent === null ? 'inconnu' : ($freePercent < self::DISK_WARNING_PERCENT ? 'faible' : 'ok');
        $degraded = $disk !== 'ok' || in_array($backup['etat'], ['jamais', 'ancienne', 'echec'], true);

        return response()->json([
            'statut' => $degraded ? 'degrade' : 'ok', 'base' => $base,
            'disque' => ['etat' => $disk, 'libre_pourcent' => $freePercent],
            'sauvegarde' => ['etat' => $backup['etat'], 'age_heures' => $backup['age_heures']],
            'synchronisation' => ['conflits_ouverts' => $conflicts],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
