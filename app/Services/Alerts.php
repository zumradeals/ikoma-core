<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alertes du superviseur. Une alerte ne contient jamais de nom ni de donnée personnelle : seulement un type, une gravité, un objet
 * (identifiant) et une phrase courte. Le nom n'apparaît qu'à la consultation de l'objet, par un compte autorisé.
 */
final class Alerts
{
    public const SEVERITIES = ['critique' => 0, 'haute' => 1, 'normale' => 2];

    public function __construct(private readonly Audit $audit) {}

    /** Crée l'alerte si la même cause n'en a pas déjà créé une (clé de dédoublonnage). */
    public function raise(string $eventId, string $type, string $severity, ?string $subjectType, ?string $subjectId, string $message, ?string $dedupeKey = null): void
    {
        $now = now();
        DB::table('alerts')->insertOrIgnore(['id' => (string) Str::uuid(), 'event_id' => $eventId, 'type' => $type, 'severity' => $severity, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'dedupe_key' => $dedupeKey, 'message' => mb_substr($message, 0, 200), 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Ferme, sans intervention humaine, les alertes ouvertes dont la cause a disparu. */
    public function resolve(string $subjectType, string $subjectId, ?string $type = null): void
    {
        $q = DB::table('alerts')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNull('acknowledged_at')->whereNull('resolved_at');
        if ($type !== null) {
            $q->where('type', $type);
        }
        $q->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    public function acknowledge(string $alertId, User $actor): void
    {
        DB::transaction(function () use ($alertId, $actor): void {
            $alert = DB::table('alerts')->where('id', $alertId)->lockForUpdate()->first() ?? throw new DeskRefused('alerte_inconnue', 'Alerte introuvable.', 404);
            if ($alert->acknowledged_at !== null) {
                return; // déjà prise en compte (par un autre superviseur ou un double appui) : rien de plus
            }
            DB::table('alerts')->where('id', $alert->id)->update(['acknowledged_by' => $actor->id, 'acknowledged_at' => now(), 'updated_at' => now()]);
            $this->audit->append('alerte.prise_en_compte', $actor->id, ['alert_id' => $alert->id, 'type' => $alert->type, 'severity' => $alert->severity, 'event_id' => $alert->event_id]);
        });
    }
}
