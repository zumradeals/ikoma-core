<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Alertes du contrôle d'accès (audit global B2 et B3). Chaque alerte reste sans nom de personne : un code de badge, un nom de point de contrôle
 * et un nombre. Une cause qui se répète dans la même tranche de cinq minutes ne crée qu'une alerte (clé de dédoublonnage).
 */
final class ControlAlerts
{
    private const BUCKET_SECONDS = 300;

    public function __construct(private readonly Alerts $alerts) {}

    /**
     * Un badge refusé à un contrôle ou à une sortie. Un badge annulé est critique : c'est le cas du badge perdu ou volé que quelqu'un essaie encore.
     *
     * @param  array{type:string,id:?string,name:string}  $point
     */
    public function refused(string $eventId, ?string $badgeId, ?string $badgeCode, string $reason, bool $scannedToken, array $point, ?string $declaredAt, string $context = 'controle'): void
    {
        $severity = match ($reason) {
            'annule' => 'critique',
            'restitue', 'non_associe', 'invite_retire', 'autre_evenement' => 'haute',
            'inconnu' => $scannedToken ? 'haute' : 'normale', // un QR inconnu est suspect ; un code tapé à la main est souvent une faute de frappe
            'hors_periode' => 'normale',
            default => null,
        };
        if ($severity === null) {
            return;
        }
        $at = $this->at($declaredAt);
        $what = ['annule' => 'annulé', 'restitue' => 'déjà restitué', 'non_associe' => 'non associé à une personne', 'invite_retire' => 'd’un invité retiré de la liste', 'autre_evenement' => 'd’un autre événement', 'inconnu' => 'inconnu', 'hors_periode' => 'hors de sa période de validité'][$reason];
        $where = $context === 'sortie' ? 'à la sortie' : 'au point';
        $code = $badgeCode !== null ? 'Badge '.$badgeCode : 'Badge';
        $message = $code.' '.$what.' présenté '.$where.' « '.$point['name'].' ».'.$this->offlineSuffix($declaredAt, $at);
        $this->alerts->raise($eventId, 'controle_refuse', $severity, $badgeId !== null ? 'badge' : null, $badgeId, $message, $this->key('ctl', [$eventId, $badgeId ?? $reason, $reason, $severity, $point['name']], $at));
        $this->burst($eventId, $point, $at);
    }

    /**
     * Badge valide, mais la zone ne fait pas partie de celles de sa catégorie.
     *
     * @param  array{type:string,id:?string,name:string}  $point
     */
    public function zoneDenied(string $eventId, string $badgeId, string $badgeCode, array $point, ?string $declaredAt): void
    {
        $at = $this->at($declaredAt);
        $this->alerts->raise($eventId, 'zone_interdite', 'haute', 'badge', $badgeId, 'Badge '.$badgeCode.' présenté à « '.$point['name'].' » : zone non autorisée pour sa catégorie.'.$this->offlineSuffix($declaredAt, $at), $this->key('zone', [$eventId, $badgeId, $point['name']], $at));
        $this->burst($eventId, $point, $at);
    }

    /**
     * Le même badge validé à deux points différents en moins de N secondes : une copie est probable. Les entrées, sorties et contrôles valides comptent.
     *
     * @param  array{type:string,id:?string,name:string}  $point
     */
    public function doublePresentation(string $eventId, string $badgeId, string $badgeCode, string $currentEventId, array $point, ?string $declaredAt): void
    {
        $seconds = $this->setting('controle_delai_min_points', 15);
        $at = $this->at($declaredAt);
        $other = DB::selectOne(
            "SELECT checkpoint FROM access_events
              WHERE event_id = ? AND badge_id = ? AND id <> ?
                AND (type IN ('entree', 'sortie') OR (type = 'controle' AND details->>'verdict' = 'valide'))
                AND checkpoint IS DISTINCT FROM ?
                AND COALESCE((details->>'heure_declaree')::timestamptz, occurred_at) BETWEEN ?::timestamptz - make_interval(secs => ?) AND ?::timestamptz + make_interval(secs => ?)
              ORDER BY occurred_at DESC LIMIT 1",
            [$eventId, $badgeId, $currentEventId, $point['name'], $at->toIso8601String(), $seconds, $at->toIso8601String(), $seconds],
        );
        if ($other === null) {
            return;
        }
        $this->alerts->raise($eventId, 'badge_double', 'haute', 'badge', $badgeId, 'Badge '.$badgeCode.' présenté à « '.$other->checkpoint.' » puis à « '.$point['name'].' » en moins de '.$seconds.' s : copie possible.'.$this->offlineSuffix($declaredAt, $at), $this->key('dbl', [$eventId, $badgeId, $point['name'], (string) $other->checkpoint], $at));
    }

    /**
     * Trop de refus au même point en peu de temps : quelqu'un essaie des badges.
     *
     * @param  array{type:string,id:?string,name:string}  $point
     */
    private function burst(string $eventId, array $point, CarbonImmutable $at): void
    {
        $count = $this->setting('controle_rafale_nombre', 5);
        $minutes = $this->setting('controle_rafale_minutes', 5);
        $refused = (int) DB::selectOne(
            "SELECT count(*) AS n FROM access_events
              WHERE event_id = ? AND type = 'controle' AND checkpoint = ? AND (details->>'verdict' = 'refuse' OR details->>'zone_ok' = 'false')
                AND COALESCE((details->>'heure_declaree')::timestamptz, occurred_at) BETWEEN ?::timestamptz - make_interval(mins => ?) AND ?::timestamptz",
            [$eventId, $point['name'], $at->toIso8601String(), $minutes, $at->toIso8601String()],
        )->n;
        if ($refused < $count) {
            return;
        }
        $this->alerts->raise($eventId, 'controle_rafale', 'haute', null, null, $refused.' badges refusés en '.$minutes.' minutes au point « '.$point['name'].' ».', $this->key('raf', [$eventId, $point['name']], $at, $minutes * 60));
    }

    /** L'heure du contrôle : celle que la tablette a déclarée hors ligne (jamais dans le futur), sinon maintenant. */
    private function at(?string $declaredAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if ($declaredAt === null) {
            return $now;
        }
        try {
            $declared = CarbonImmutable::parse($declaredAt);
        } catch (\Throwable) {
            return $now;
        }

        return $declared->greaterThan($now) ? $now : $declared;
    }

    private function offlineSuffix(?string $declaredAt, CarbonImmutable $at): string
    {
        return $declaredAt === null ? '' : ' Contrôle fait hors ligne à '.$at->format('H:i').'.';
    }

    /** @param list<string> $parts */
    private function key(string $prefix, array $parts, CarbonImmutable $at, int $bucket = self::BUCKET_SECONDS): string
    {
        return $prefix.':'.substr(hash('sha256', implode('|', $parts)), 0, 32).':'.intdiv($at->getTimestamp(), $bucket);
    }

    private function setting(string $key, int $default): int
    {
        return max(1, (int) (DB::table('settings')->where('key', $key)->value('value') ?? $default));
    }
}
