<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\JournalExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class JournalExportController
{
    public function __invoke(Request $r): StreamedResponse
    {
        Gate::authorize('journal.exporter');
        $data = $r->validate(['debut' => 'required|date_format:Y-m-d', 'fin' => 'required|date_format:Y-m-d|after_or_equal:debut']);
        abort_if(Carbon::parse($data['debut'])->diffInDays(Carbon::parse($data['fin'])) > 366, 422, 'Choisissez une période de 366 jours au plus.');
        $maximum = min(50000, max(1, (int) DB::table('settings')->where('key', 'journal_export_max_lignes')->value('value')));
        // Capturer une limite haute avant de journaliser l'export ; la lecture suivante ne change pas son contenu.
        $last = (int) DB::table('audit_logs')->max('id');
        $query = DB::table('audit_logs')->where('id', '<=', $last)->whereBetween('occurred_at', [$data['debut'].'T00:00:00.000000Z', $data['fin'].'T23:59:59.999999Z']);
        $count = (clone $query)->count();
        abort_if($count > $maximum, 422, 'Trop de lignes. Choisissez une période plus courte.');
        app(Audit::class)->append('journal.exporte', $r->user()->id, ['debut' => $data['debut'], 'fin' => $data['fin'], 'lignes' => $count, 'derniere_ligne' => $last]);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            JournalExport::row($stream, ['Numéro', 'Heure UTC', 'Acteur (identifiant)', 'Action', 'Empreinte', 'Empreinte précédente']);
            foreach ($query->orderBy('id')->cursor() as $row) {
                // Aucune jointure nominative, aucun détail libre (un ancien journal peut en contenir).
                JournalExport::row($stream, [(string) $row->id, $row->occurred_at, $row->actor_id ?? '', $row->action, $row->hash, $row->previous_hash]);
            }
            fclose($stream);
        }, 'journal-ikoma.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
