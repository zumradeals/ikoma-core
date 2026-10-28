<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Audit;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\ControlAlerts;
use App\Services\Presence;
use App\Services\QrSvg;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contrôle d'un badge sur site et sortie (document de fonctionnement, section 9). Le contrôle s'appuie sur BadgeService::controler ;
 * chaque contrôle, valide ou refusé, crée un événement « controle ». Aucun nom de personne dans le journal d'audit.
 * Un refus n'est pas une exception (il ne doit pas annuler son propre événement ni son entrée de journal) : il est renvoyé dans le résultat.
 */
final class SiteControl
{
    public function __construct(private readonly BadgeService $badges, private readonly Audit $audit, private readonly Presence $presence, private readonly ControlAlerts $controlAlerts) {}

    /**
     * @param  array{type:string,id:string}|null  $point
     * @return array<string, mixed>
     */
    public function control(Device $poste, User $agent, string $code, ?array $point, ?string $declaredAt = null, ?bool $localValid = null): array
    {
        $checkpoint = $this->point($poste, $point);
        $verdict = $this->badges->controler($code, $poste->event_id, $agent, false);
        $badge = $verdict->badgeId ? DB::table('badges')->where('id', $verdict->badgeId)->first(['id', 'event_id', 'code']) : null;
        $valid = $verdict->valid;
        $reason = $verdict->reason;
        $message = $verdict->message;
        if ($badge !== null && $badge->event_id !== $poste->event_id) {
            [$valid, $reason, $message, $badge] = [false, 'autre_evenement', 'Badge d’un autre événement.', null];
        }
        $zoneOk = null;
        $zones = [];
        $category = null;
        if ($valid) {
            $visitor = DB::table('visitors')->where('id', $verdict->person['id'])->first(['id', 'category_id']);
            $zones = DB::table('category_zone as cz')->join('zones as z', 'z.id', '=', 'cz.zone_id')->where('cz.category_id', $visitor->category_id)->orderBy('z.name')->get(['z.id', 'z.name']);
            $category = DB::table('categories')->where('id', $visitor->category_id)->first(['id', 'name', 'color', 'priority_circuit']);
            if ($checkpoint['type'] === 'zone') {
                $zoneOk = $zones->contains('id', $checkpoint['id']);
            }
        }
        $now = now();
        $eventId = (string) Str::uuid();
        DB::table('access_events')->insert(['id' => $eventId, 'event_id' => $poste->event_id, 'visitor_id' => $valid ? $verdict->person['id'] : null, 'badge_id' => $badge?->id, 'actor_id' => $agent->id, 'type' => 'controle', 'checkpoint' => $checkpoint['name'], 'occurred_at' => $now, 'details' => json_encode(array_filter(['verdict' => $valid ? 'valide' : 'refuse', 'raison' => $reason, 'point_type' => $checkpoint['type'], 'point_id' => $checkpoint['id'], 'zone_ok' => $zoneOk, 'poste_id' => $poste->id, 'heure_declaree' => $declaredAt, 'differe' => $declaredAt !== null ? true : null, 'verdict_tablette' => $localValid === null ? null : ($localValid ? 'valide' : 'refuse')], fn ($v) => $v !== null), JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        if (! $valid || $zoneOk === false) {
            $this->audit->append('controle.refuse', $agent->id, ['access_event_id' => $eventId, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'badge_id' => $badge?->id, 'raison' => $valid ? 'zone_non_autorisee' : $reason, 'point_id' => $checkpoint['id']]);
            // Le superviseur est prévenu tout de suite (audit global B2) : un badge annulé qui se présente n'est plus seulement dans le journal.
            if (! $valid) {
                $this->controlAlerts->refused($poste->event_id, $badge?->id, $badge?->code, (string) $reason, preg_match('/^(IKOMA1:)?[0-9a-f]{64}$/i', trim($code)) === 1, $checkpoint, $declaredAt);
            } elseif ($badge !== null) {
                $this->controlAlerts->zoneDenied($poste->event_id, $badge->id, $badge->code, $checkpoint, $declaredAt);
            }
        }
        if ($valid && $badge !== null) {
            $this->controlAlerts->doublePresentation($poste->event_id, $badge->id, $badge->code, $eventId, $checkpoint, $declaredAt);
        }

        return [
            'valid' => $valid, 'reason' => $reason, 'message' => $message, 'badge_code' => $badge?->code, 'zone_ok' => $zoneOk, 'point' => $checkpoint['name'],
            'person' => $valid ? ['name' => $verdict->person['name']] : null, 'organisation' => $valid ? $verdict->organisation : null,
            'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'color' => $category->color, 'priority_circuit' => (bool) $category->priority_circuit] : null,
            'zones' => $zones instanceof Collection ? $zones->pluck('name')->all() : [], 'source' => 'serveur',
        ];
    }

    /**
     * Sortie : le badge est scanné à la sortie, le visiteur passe au statut « sorti » et le badge est restitué (réglage badge_reutilisable).
     *
     * @param  array{type:string,id:string}|null  $point
     * @return array<string, mixed>
     */
    public function exit(Device $poste, User $agent, string $code, ?array $point, ?string $declaredAt = null): array
    {
        $checkpoint = $this->point($poste, $point);
        $input = trim($code);
        if (str_starts_with($input, QrSvg::PREFIX)) {
            $input = substr($input, strlen(QrSvg::PREFIX));
        }
        $query = DB::table('badges')->select('id', 'event_id', 'status', 'code');
        if (preg_match('/^[0-9a-f]{64}$/i', $input) === 1) {
            $query->where('qr_token', strtolower($input));
        } else {
            $query->where('event_id', $poste->event_id)->whereRaw('upper(code) = ?', [mb_strtoupper($input)]);
        }
        $badge = $query->first();
        if ($badge === null || $badge->event_id !== $poste->event_id) {
            return $this->refused($poste, $agent, $badge && $badge->event_id === $poste->event_id ? $badge->id : null, 'inconnu', $badge === null ? 'Badge inconnu : vérifiez le code ou scannez à nouveau.' : 'Badge d’un autre événement.');
        }
        if ($badge->status === 'Annulé') {
            $this->controlAlerts->refused($poste->event_id, $badge->id, $badge->code, 'annule', false, $checkpoint, $declaredAt, 'sortie');

            return $this->refused($poste, $agent, $badge->id, 'annule', 'Badge annulé : la sortie n’est pas enregistrée. Prévenez le superviseur.');
        }
        $active = DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNull('returned_at')->whereNull('cancelled_at')->value('visitor_id');
        if ($active === null) {
            $last = DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNotNull('returned_at')->orderByDesc('returned_at')->value('visitor_id');
            if ($last !== null && DB::table('visitors')->where('id', $last)->value('status') === 'sorti') {
                return $this->refused($poste, $agent, $badge->id, 'deja_sorti', 'Sortie déjà enregistrée pour ce badge.');
            }

            return $this->refused($poste, $agent, $badge->id, $badge->status === 'Disponible' ? 'non_associe' : 'restitue', $badge->status === 'Disponible' ? 'Badge non associé à une personne : aucune sortie à enregistrer.' : 'Badge restitué : aucune sortie à enregistrer.');
        }

        return DB::transaction(function () use ($poste, $agent, $badge, $active, $checkpoint, $declaredAt): array {
            $visitor = DB::table('visitors')->where('id', $active)->lockForUpdate()->first();
            if ($visitor->status !== 'present') {
                $map = ['sorti' => ['deja_sorti', 'Sortie déjà enregistrée pour cette personne.'], 'refuse' => ['visiteur_refuse', 'Accès refusé à cette personne : aucune sortie à enregistrer.']];
                [$reason, $message] = $map[$visitor->status] ?? ['sortie_sans_entree', 'Aucune entrée enregistrée pour cette personne : la sortie est refusée.'];

                return $this->refused($poste, $agent, $badge->id, $reason, $message);
            }
            $now = now();
            $eventId = (string) Str::uuid();
            DB::table('access_events')->insert(['id' => $eventId, 'event_id' => $poste->event_id, 'visitor_id' => $visitor->id, 'badge_id' => $badge->id, 'actor_id' => $agent->id, 'type' => 'sortie', 'checkpoint' => $checkpoint['name'], 'occurred_at' => $now, 'details' => json_encode(array_filter(['point_type' => $checkpoint['type'], 'point_id' => $checkpoint['id'], 'poste_id' => $poste->id, 'heure_declaree' => $declaredAt], fn ($v) => $v !== null), JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('visitors')->where('id', $visitor->id)->update(['status' => 'sorti', 'updated_at' => $now]);
            try {
                $returned = $this->badges->restituer($badge->id, $agent);
            } catch (BadgeRefused) {
                throw new DeskRefused('non_associe', 'Le badge ne peut pas être restitué.');
            }
            $this->audit->append('sortie.enregistree', $agent->id, ['access_event_id' => $eventId, 'visitor_id' => $visitor->id, 'badge_id' => $badge->id, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'statut_badge' => $returned->status->value, 'presents' => $this->presence->count($poste->event_id)]);

            return ['ok' => true, 'badge_code' => $badge->code, 'badge_status' => $returned->status->value, 'person' => ['name' => $visitor->full_name], 'point' => $checkpoint['name'], 'presents' => $this->presence->count($poste->event_id), 'source' => 'serveur'];
        });
    }

    /** @return array<string, mixed> */
    private function refused(Device $poste, User $agent, ?string $badgeId, string $reason, string $message): array
    {
        $this->audit->append('sortie.refusee', $agent->id, ['badge_id' => $badgeId, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'raison' => $reason]);

        return ['ok' => false, 'refus' => true, 'reason' => $reason, 'message' => $message, 'badge_code' => null, 'source' => 'serveur'];
    }

    /**
     * @param  array{type:string,id:string}|null  $point
     * @return array{type:string,id:?string,name:string}
     */
    private function point(Device $poste, ?array $point): array
    {
        if ($point === null) {
            return ['type' => 'poste', 'id' => $poste->id, 'name' => $poste->station ?? $poste->name];
        }
        $row = $point['type'] === 'zone'
            ? DB::table('zones')->where('id', $point['id'])->where('event_id', $poste->event_id)->first(['id', 'name'])
            : DB::table('devices')->where('id', $point['id'])->where('event_id', $poste->event_id)->first(['id', 'name', 'station']);
        if ($row === null) {
            throw new DeskRefused('point_inconnu', 'Point de contrôle inconnu pour cet événement.', 422);
        }

        return ['type' => $point['type'], 'id' => $row->id, 'name' => $point['type'] === 'zone' ? $row->name : ($row->station ?? $row->name)];
    }
}
