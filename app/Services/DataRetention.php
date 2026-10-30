<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DataRetention
{
    public const TYPES = ['visiteurs', 'evenements', 'photos', 'synchronisations', 'alertes', 'notifications', 'audit'];

    /** @return array<string,int> */
    public function settings(): array
    {
        $result = [];
        foreach (self::TYPES as $type) {
            $result[$type] = (int) (DB::table('settings')->where('key', 'conservation_'.$type.'_jours')->value('value') ?? 0);
        }

        return $result;
    }

    /** @return array<string,int> */
    public function purge(): array
    {
        $this->requirePrivileged();
        if (DB::table('settings')->where('key', 'conservation_validee')->value('value') !== '1') {
            throw new \RuntimeException('Les durées doivent être approuvées dans Administration > Conservation.');
        }
        $days = $this->settings();

        $counts = DB::transaction(function () use ($days): array {
            DB::statement('SELECT pg_advisory_xact_lock(731902)');
            // La purge est une maintenance privilégiée : bloquer les écritures concurrentes avant de décider ou d'effacer un fichier.
            DB::statement('LOCK TABLE events, hosts, devices, visitors, badges, badge_assignments, access_events, identity_checks, photos, sync_operations, sync_conflicts, notifications, alerts, refusals, import_batches, groups IN SHARE ROW EXCLUSIVE MODE');
            DB::table('hosts')->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('devices')->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('visitors')->orderBy('id')->lockForUpdate()->get(['id']);
            $counts = array_fill_keys(self::TYPES, 0);
            $now = now();
            foreach (DB::table('events')->where('ends_at', '<', $now)->orderBy('id')->lockForUpdate()->get() as $event) {
                // Ne jamais effacer une identité nécessaire à une évacuation en cours, même si la date de fin est dépassée.
                if (app(Presence::class)->count($event->id) > 0 || DB::table('sync_conflicts')->where('event_id', $event->id)->whereNull('resolved_at')->exists() || DB::table('alerts')->where('event_id', $event->id)->whereNull('resolved_at')->exists()) {
                    continue;
                }
                $elapsed = (int) Carbon::parse($event->ends_at)->diffInDays($now);
                $visitors = DB::table('visitors')->where('event_id', $event->id);
                $ids = (clone $visitors)->select('id');
                if ($days['photos'] > 0 && $elapsed >= $days['photos']) {
                    foreach (DB::table('photos')->whereIn('visitor_id', clone $ids)->get(['id', 'file_reference']) as $photo) {
                        if (preg_match('~^photos/[0-9a-f-]{36}\.enc$~', $photo->file_reference) !== 1) {
                            throw new \RuntimeException('Référence de photo non conforme : purge arrêtée.');
                        }
                        app(FileDeletionQueue::class)->enqueue($photo->file_reference);
                        $counts['photos'] += DB::table('photos')->where('id', $photo->id)->delete();
                    }
                    // Photos de pièces d'identité (option de l'administrateur) : même échéance que les photos ; le type reste, le fichier et le numéro disparaissent.
                    foreach (DB::table('identity_checks')->whereIn('visitor_id', clone $ids)->whereNotNull('document_file')->get(['id', 'document_file']) as $check) {
                        if (preg_match('~^identity-documents/[0-9a-f-]{36}\.enc$~', $check->document_file) !== 1) {
                            throw new \RuntimeException('Référence de pièce non conforme : purge arrêtée.');
                        }
                        app(FileDeletionQueue::class)->enqueue($check->document_file);
                        $counts['photos'] += DB::table('identity_checks')->where('id', $check->id)->update(['document_file' => null, 'document_number' => null]);
                    }
                }
                if (($days['photos'] > 0 && $elapsed >= $days['photos']) || ($days['visiteurs'] > 0 && $elapsed >= $days['visiteurs'])) {
                    foreach ((clone $visitors)->whereNotNull('profile_photo_file')->get(['id', 'profile_photo_file']) as $portrait) {
                        app(FileDeletionQueue::class)->enqueue($portrait->profile_photo_file);
                        $counts['photos'] += DB::table('visitors')->where('id', $portrait->id)->update(['profile_photo_file' => null, 'profile_photo_print_allowed' => false]);
                    }
                }
                if ($days['notifications'] > 0 && $elapsed >= $days['notifications']) {
                    $counts['notifications'] += DB::table('notifications')->where('event_id', $event->id)->delete();
                }
                if ($days['alertes'] > 0 && $elapsed >= $days['alertes']) {
                    $counts['alertes'] += DB::table('alerts')->where('event_id', $event->id)->whereNotNull('resolved_at')->delete();
                }
                $anonymized = $event->anonymized_at !== null;
                if ($days['visiteurs'] > 0 && $elapsed >= $days['visiteurs'] && $event->anonymized_at === null) {
                    $anonymized = true;
                    // Sans identité, aucune nouvelle admission ni rejeu n'est permis. Les références statistiques restent stables.
                    DB::table('devices')->where('event_id', $event->id)->update(['revoked_at' => $now, 'enrollment_hash' => null, 'enrollment_expires_at' => null, 'updated_at' => $now]);
                    $counts['visiteurs'] += (clone $visitors)->update(['full_name' => DB::raw("'Visiteur anonymisé ' || id::text"), 'organisation' => null, 'position' => null, 'host_id' => null, 'access_note' => null, 'withdrawn_at' => $now, 'updated_at' => $now]);
                    DB::table('hosts')->where('event_id', $event->id)->update(['name' => DB::raw("'Hôte anonymisé ' || id::text"), 'service' => null, 'user_id' => null, 'updated_at' => $now]);
                    DB::table('refusals')->whereIn('visitor_id', clone $ids)->update(['reason' => 'Motif effacé à échéance', 'supervisor_decision' => null]);
                    DB::table('notifications')->where('event_id', $event->id)->delete();
                    DB::table('badges')->where('event_id', $event->id)->update(['cancellation_reason' => null]);
                    DB::table('badge_assignments')->where('event_id', $event->id)->update(['cancellation_reason' => null]);
                    DB::table('import_batches')->where('event_id', $event->id)->update(['file_reference' => null]);
                    DB::table('events')->where('id', $event->id)->update(['anonymized_at' => $now, 'list_revision' => DB::raw('list_revision + 1'), 'updated_at' => $now]);
                }
                if ($days['synchronisations'] > 0 && $elapsed >= $days['synchronisations'] && $anonymized) {
                    $counts['synchronisations'] += DB::table('sync_conflicts')->where('event_id', $event->id)->delete();
                    $counts['synchronisations'] += DB::table('sync_operations')->whereIn('device_id', DB::table('devices')->where('event_id', $event->id)->select('id'))->delete();
                    DB::table('alerts')->where('event_id', $event->id)->where('subject_type', 'conflit')->delete();
                }
                if ($days['evenements'] > 0 && $elapsed >= $days['evenements']) {
                    if ($event->retention_summary === null) {
                        $summary = ['visiteurs' => (clone $visitors)->count(), 'statuts' => (clone $visitors)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(), 'mouvements' => DB::table('access_events')->where('event_id', $event->id)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')->all()];
                        DB::table('events')->where('id', $event->id)->update(['retention_summary' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                        DB::table('access_events')->where('event_id', $event->id)->delete();
                        DB::table('identity_checks')->whereIn('visitor_id', clone $ids)->delete();
                    }
                    $counts['evenements'] += DB::table('events')->where('id', $event->id)->update(['name' => 'Événement anonymisé '.$event->id, 'site' => null]);
                    DB::table('groups')->where('event_id', $event->id)->update(['name' => DB::raw("'Groupe anonymisé ' || id::text"), 'bus_number' => null, 'supervisor_id' => null]);
                    DB::table('devices')->where('event_id', $event->id)->update(['name' => DB::raw("'Poste anonymisé ' || id::text"), 'station' => null]);
                }
            }
            app(Audit::class)->append('donnees.purgees', null, ['nombres' => $counts]);

            return $counts;
        });
        DB::afterCommit(fn () => app(FileDeletionQueue::class)->drain());

        return $counts;
    }

    public function requirePrivileged(): void
    {
        if (! DB::selectOne("SELECT has_table_privilege(current_user, 'audit_logs', 'DELETE') AND has_table_privilege(current_user, 'access_events', 'DELETE') AND has_table_privilege(current_user, 'identity_checks', 'DELETE') AS allowed")->allowed) {
            throw new \RuntimeException('La purge exige une console privilégiée distincte du compte applicatif.');
        }
    }
}
