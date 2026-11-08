<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Services\Alerts;
use App\Services\Audit;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\ControlAlerts;
use App\Services\PermanentAccess;
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
    public function __construct(private readonly BadgeService $badges, private readonly Audit $audit, private readonly Presence $presence, private readonly ControlAlerts $controlAlerts, private readonly PermanentAccess $permanent) {}

    /**
     * @param  array{type:string,id:string}|null  $point
     * @return array<string, mixed>
     */
    public function control(Device $poste, User $agent, string $code, ?array $point, ?string $declaredAt = null, ?bool $localValid = null, ?string $knownAt = null): array
    {
        PostCapabilities::require($poste, $agent, 'scan');
        $checkpoint = $this->point($poste, $point);
        if (($card = $this->permanent->resolveCard($code)) !== null) {
            return $this->permanentControl($poste, $agent, $card, $checkpoint, $declaredAt, $localValid, $knownAt);
        }
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
        PostCapabilities::require($poste, $agent, 'sortie');
        $checkpoint = $this->point($poste, $point);
        if (($card = $this->permanent->resolveCard($code)) !== null) {
            return $this->permanentExit($poste, $agent, $card, $checkpoint, $declaredAt);
        }
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
            // S02 : clôture réservée au superviseur (indicateur explicite de la catégorie, jamais son libellé). L'agent est refusé ; rien n'est restitué.
            if (DB::table('categories')->where('id', $visitor->category_id)->value('closure_supervisor_only')) {
                return $this->refused($poste, $agent, $badge->id, 'cloture_reservee_superviseur', 'La sortie de cette personne est réservée au superviseur : prévenez-le.');
            }
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

    /**
     * Carte permanente présentée à un point de contrôle : même journal (access_events), mêmes alertes et mêmes présences que les badges de visiteurs ;
     * seule la décision (PermanentAccess) est propre aux titulaires. Au poste d'entrée, une décision favorable enregistre l'entrée ; la carte n'est ni associée ni restituée.
     *
     * @param  array{type:string,id:?string,name:string}  $checkpoint
     * @return array<string, mixed>
     */
    private function permanentControl(Device $poste, User $agent, \stdClass $card, array $checkpoint, ?string $declaredAt, ?bool $localValid, ?string $knownAt): array
    {
        $now = now();
        $decision = $this->permanent->decide($card, $poste->event_id, $checkpoint, $now);
        if ($decision['allowed'] && $declaredAt !== null) {
            $decision = $this->permanent->offlineRefusal($card, $poste->event_id, $checkpoint, $declaredAt, $knownAt, $now) ?? $decision;
        }
        $valid = $decision['allowed'];
        $visitorId = DB::table('visitors')->where('holder_id', $card->holder_id)->where('event_id', $poste->event_id)->value('id');
        $type = 'controle';
        if ($valid) {
            $visitorId = $this->permanent->participation($card, $poste->event_id);
            if ($checkpoint['type'] === 'poste' && ! $this->presence->visitorPresent($visitorId)) {
                $type = 'entree';
            }
        }
        $eventId = (string) Str::uuid();
        $details = array_filter(['verdict' => $valid ? 'valide' : 'refuse', 'raison' => $decision['reason'], 'permanent' => true, 'holder_id' => $card->holder_id, 'identifiant' => $card->identifier, 'carte_rang' => $card->rank,
            'point_type' => $checkpoint['type'], 'point_id' => $checkpoint['id'], 'poste_id' => $poste->id, 'heure_declaree' => $declaredAt, 'differe' => $declaredAt !== null ? true : null, 'verdict_tablette' => $localValid === null ? null : ($localValid ? 'valide' : 'refuse')], fn ($v) => $v !== null);
        DB::table('access_events')->insert(['id' => $eventId, 'event_id' => $poste->event_id, 'visitor_id' => $visitorId, 'badge_id' => null, 'actor_id' => $agent->id, 'type' => $type, 'checkpoint' => $checkpoint['name'], 'occurred_at' => $now, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        if ($type === 'entree') {
            DB::table('visitors')->where('id', $visitorId)->update(['status' => 'present', 'updated_at' => $now]);
            $this->audit->append('entree.permanente', $agent->id, ['access_event_id' => $eventId, 'holder_id' => $card->holder_id, 'identifiant' => $card->identifier, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'presents' => $this->presence->count($poste->event_id)]);
        }
        if (! $valid) {
            $this->audit->append('controle.refuse', $agent->id, ['access_event_id' => $eventId, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'holder_id' => $card->holder_id, 'identifiant' => $card->identifier, 'raison' => $decision['reason'], 'point_id' => $checkpoint['id']]);
            $critical = str_starts_with((string) $decision['reason'], 'carte_');
            $bucket = intdiv(($declaredAt !== null ? (int) strtotime($declaredAt) : $now->getTimestamp()), 300);
            app(Alerts::class)->raise($poste->event_id, 'controle_refuse', $critical ? 'critique' : 'haute', null, null, 'Carte permanente '.$card->identifier.' refusée au point « '.$checkpoint['name'].' » : '.$decision['message'].($declaredAt !== null ? ' (contrôle hors ligne rejoué)' : ''), 'perm:'.$card->holder_id.':'.$decision['reason'].':'.$checkpoint['name'].':'.$bucket);
        }

        return [
            'valid' => $valid, 'reason' => $decision['reason'], 'message' => $decision['message'], 'badge_code' => $card->identifier, 'zone_ok' => $valid && $checkpoint['type'] === 'zone' ? true : null, 'point' => $checkpoint['name'],
            'person' => $valid ? ['name' => $card->full_name] : null, 'organisation' => $valid ? $card->organisation : null, 'category' => $valid ? $this->permanent->categoryOf($card->institutional_category) : null, 'zones' => $decision['zones'], 'source' => 'serveur',
            'permanent' => ['identifier' => $card->identifier, 'entered' => $type === 'entree'],
        ];
    }

    /**
     * Sortie d'un titulaire permanent : enregistrée, sans restitution ni révocation de la carte, et sans aucune condition d'habilitation, de confiance ou de statut de carte
     * (on doit toujours pouvoir sortir et être décompté). Une carte révoquée qui sort déclenche seulement une alerte au superviseur.
     *
     * @param  array{type:string,id:?string,name:string}  $checkpoint
     * @return array<string, mixed>
     */
    private function permanentExit(Device $poste, User $agent, \stdClass $card, array $checkpoint, ?string $declaredAt): array
    {
        return DB::transaction(function () use ($poste, $agent, $card, $checkpoint, $declaredAt): array {
            $visitor = DB::table('visitors')->where('holder_id', $card->holder_id)->where('event_id', $poste->event_id)->lockForUpdate()->first();
            if ($visitor === null || $visitor->status !== 'present') {
                $already = $visitor !== null && $visitor->status === 'sorti';

                return $this->refused($poste, $agent, null, $already ? 'deja_sorti' : 'sortie_sans_entree', $already ? 'Sortie déjà enregistrée pour ce titulaire.' : 'Aucune entrée enregistrée pour ce titulaire dans cet événement : la sortie est refusée.');
            }
            $now = now();
            $eventId = (string) Str::uuid();
            $details = array_filter(['permanent' => true, 'holder_id' => $card->holder_id, 'identifiant' => $card->identifier, 'carte_statut' => $card->card_status, 'point_type' => $checkpoint['type'], 'point_id' => $checkpoint['id'], 'poste_id' => $poste->id, 'heure_declaree' => $declaredAt], fn ($v) => $v !== null);
            DB::table('access_events')->insert(['id' => $eventId, 'event_id' => $poste->event_id, 'visitor_id' => $visitor->id, 'badge_id' => null, 'actor_id' => $agent->id, 'type' => 'sortie', 'checkpoint' => $checkpoint['name'], 'occurred_at' => $now, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('visitors')->where('id', $visitor->id)->update(['status' => 'sorti', 'updated_at' => $now]);
            $this->audit->append('sortie.permanente', $agent->id, ['access_event_id' => $eventId, 'holder_id' => $card->holder_id, 'identifiant' => $card->identifier, 'event_id' => $poste->event_id, 'device_id' => $poste->id, 'carte_statut' => $card->card_status, 'presents' => $this->presence->count($poste->event_id)]);
            $inactive = $card->card_status !== 'active';
            if ($inactive) {
                app(Alerts::class)->raise($poste->event_id, 'controle_refuse', 'critique', null, null, 'Carte permanente '.$card->identifier.' ('.$card->card_status.') présentée à la sortie « '.$checkpoint['name'].' » : sortie enregistrée.', 'permexit:'.$card->holder_id.':'.intdiv($now->getTimestamp(), 300));
            }

            return ['ok' => true, 'badge_code' => $card->identifier, 'badge_status' => 'Carte conservée', 'person' => ['name' => $visitor->full_name], 'point' => $checkpoint['name'], 'presents' => $this->presence->count($poste->event_id), 'source' => 'serveur', 'permanent' => ['identifier' => $card->identifier, 'card_kept' => true], 'warning' => $inactive ? 'Carte non valable : la sortie est enregistrée, le superviseur est prévenu.' : null];
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
