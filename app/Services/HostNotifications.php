<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Poste\Desk;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Notifications internes uniquement. Messages constants, aucun nom dans l'audit. */
final class HostNotifications
{
    public function __construct(private readonly Audit $audit) {}

    public function send(string $visitorId, string $type, ?User $requester = null, ?Device $device = null): ?string
    {
        $v = DB::table('visitors as v')->join('hosts as h', 'h.id', '=', 'v.host_id')->join('users as u', 'u.id', '=', 'h.user_id')->where('v.id', $visitorId)->whereNull('v.withdrawn_at')->where('u.active', true)->first(['v.event_id', 'h.user_id']);
        if (! $v || ! User::find($v->user_id)?->can('arrivees.recevoir_notification')) {
            return null;
        }
        $key = $type.':'.$visitorId.':'.$v->user_id;
        // Les avis hors période portent la version des dates : une modification de fiche ne réutilise pas un ancien accord.
        if ($type === 'hors_periode') {
            $key .= ':'.$this->periodKey($visitorId);
        }
        $id = (string) Str::uuid();
        $at = now();
        $created = DB::table('notifications')->insertOrIgnore(['id' => $id, 'recipient_id' => $v->user_id, 'event_id' => $v->event_id, 'visitor_id' => $visitorId, 'requester_id' => $requester?->id, 'device_id' => $device?->id, 'type' => $type, 'message' => match ($type) {
            'arrivee' => 'Votre invité vient d’entrer.','non_liste' => 'Un visiteur non listé attend votre réponse.',default => 'L’accueil demande votre avis pour une visite hors période.'
        }, 'dedupe_key' => $key, 'created_at' => $at, 'updated_at' => $at]);
        if ($created) {
            $this->audit->append('notification.creee', $requester?->id, ['notification_id' => $id, 'recipient_id' => $v->user_id, 'visitor_id' => $visitorId, 'event_id' => $v->event_id]);
        }

        return $created ? $id : DB::table('notifications')->where('dedupe_key', $key)->value('id');
    }

    public function periodKey(string $id): string
    {
        $v = DB::table('visitors')->where('id', $id)->first(['valid_from', 'valid_until']);

        return hash('sha256', $v->valid_from.'|'.$v->valid_until);
    }

    /** @return array{state:?string} */
    public function advice(string $visitorId): array
    {
        $recipient = DB::table('visitors as v')->join('hosts as h', 'h.id', '=', 'v.host_id')->where('v.id', $visitorId)->value('h.user_id');
        $n = DB::table('notifications')->where('recipient_id', $recipient)->where('visitor_id', $visitorId)->where('type', 'hors_periode')->where('dedupe_key', 'like', 'hors_periode:'.$visitorId.':%:'.$this->periodKey($visitorId))->orderByDesc('created_at')->first(['decision']);

        return ['state' => $n ? ($n->decision ?? 'en_attente') : null];
    }

    public function requestAdvice(Device $poste, User $agent, string $visitorId): void
    {
        DB::transaction(function () use ($poste, $agent, $visitorId) {
            $v = Visitor::where('event_id', $poste->event_id)->whereNull('withdrawn_at')->lockForUpdate()->find($visitorId) ?? throw new DeskRefused('visiteur_inconnu', 'Visiteur introuvable.', 404);
            if (! now()->lt($v->valid_from) && ! now()->gt($v->valid_until)) {
                throw new DeskRefused('avis_inutile', 'Cette visite est dans sa période de validité.');
            }
            if (! $this->send($visitorId, 'hors_periode', $agent, $poste)) {
                throw new DeskRefused('hote_sans_compte', 'Aucun compte hôte disponible. Appelez l’hôte ou le superviseur.');
            }
        });
    }

    public function answer(string $id, User $actor, bool $accepted, ?string $reason): void
    {
        DB::transaction(function () use ($id, $actor, $accepted, $reason) {
            $request = DB::table('notifications')->where('id', $id)->where('recipient_id', $actor->id)->first();
            abort_unless($request !== null, 403);
            $v = app(HostGuests::class)->owned($actor, $request->visitor_id, true);
            $n = DB::table('notifications')->where('id', $id)->where('recipient_id', $actor->id)->lockForUpdate()->first();
            abort_unless($n !== null, 403);
            if ($n->type === 'arrivee' || $n->decided_at !== null) {
                throw new DeskRefused('deja_traite', 'Cette demande a déjà été traitée.', 409);
            }
            if ($n->type === 'non_liste') {
                app(Desk::class)->decideHost($v->id, $accepted, $actor);
                if (! $accepted) {
                    $refusal = (string) Str::uuid();
                    DB::table('refusals')->insert(['id' => $refusal, 'visitor_id' => $v->id, 'actor_id' => $actor->id, 'reason' => $reason ?: 'Visite refusée par l’hôte.', 'refused_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    app(Alerts::class)->raise($v->event_id, 'refus', 'haute', 'visiteur', $v->id, 'L’hôte a refusé une visite non listée.', 'refus_hote:'.$id);
                }
            } else {
                if (! str_ends_with($n->dedupe_key, ':'.$this->periodKey($v->id))) {
                    throw new DeskRefused('demande_perimee', 'La période a changé. L’accueil doit refaire sa demande.', 409);
                }
                // Invalide la copie de la tablette sans modifier la période ni créer une entrée.
                DB::table('visitors')->where('id', $v->id)->update(['updated_at' => now()]);
            }
            DB::table('events')->where('id', $v->event_id)->increment('list_revision');
            DB::table('notifications')->where('id', $id)->update(['decision' => $accepted ? 'autorise' : 'refuse', 'reason' => $accepted ? null : $reason, 'decided_at' => now(), 'read_at' => now(), 'updated_at' => now()]);
            app(Alerts::class)->resolve('visiteur', $v->id, 'non_liste_attente');
            $this->audit->append($n->type === 'non_liste' ? 'hote.decision_non_liste' : 'hote.avis_hors_periode', $actor->id, ['notification_id' => $id, 'visitor_id' => $v->id, 'event_id' => $v->event_id]);
        });
    }

    /** Rattrape les demandes en attente lorsqu’un compte vient d’être rattaché à une fiche. */
    public function pendingFor(string $hostId): void
    {
        foreach (DB::table('visitors')->where('host_id', $hostId)->whereNull('withdrawn_at')->where('host_validation', 'en_attente')->pluck('id') as $id) {
            $request = DB::table('audit_logs')->where('action', 'visiteur.non_liste_cree')->whereRaw("details::jsonb->>'visitor_id' = ?", [$id])->orderByDesc('id')->first(['actor_id', 'details']);
            $details = $request ? json_decode($request->details, true) : [];
            $this->send($id, 'non_liste', $request?->actor_id ? User::find($request->actor_id) : null, isset($details['device_id']) ? Device::find($details['device_id']) : null);
        }
    }

    public function escalate(): int
    {
        $minutes = (int) (DB::table('settings')->where('key', 'hote_delai_reponse_minutes')->value('value') ?? 2);
        $count = 0;
        $ids = DB::table('notifications')->whereIn('type', ['non_liste', 'hors_periode'])->whereNull('decided_at')->whereNull('escalated_at')->where('created_at', '<=', now()->subMinutes($minutes))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                $n = DB::table('notifications')->where('id', $id)->lockForUpdate()->first();
                if (! $n || $n->decided_at || $n->escalated_at) {
                    return;
                }
                $v = DB::table('visitors')->where('id', $n->visitor_id)->first();
                if (! $v || $v->withdrawn_at || ($n->type === 'non_liste' && $v->host_validation !== 'en_attente')) {
                    return;
                }
                $this->raiseTimeout($n->event_id, $n->visitor_id, $n->type, 'hote_delai:'.$n->id);
                DB::table('notifications')->where('id', $id)->update(['escalated_at' => now(), 'updated_at' => now()]);
                $count++;
            });
        }

        // L’absence de compte lié ne doit pas empêcher la remontée au superviseur.
        $unlinked = DB::table('visitors as v')->where('v.host_validation', 'en_attente')->whereNull('v.withdrawn_at')->where('v.created_at', '<=', now()->subMinutes($minutes))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notifications as n')->whereColumn('n.visitor_id', 'v.id')->where('n.type', 'non_liste'))->get(['v.id', 'v.event_id']);
        foreach ($unlinked as $v) {
            if ($this->raiseTimeout($v->event_id, $v->id, 'non_liste', 'hote_delai_sans_compte:'.$v->id)) {
                $count++;
            }
        }

        return $count;
    }

    private function raiseTimeout(string $event, string $visitor, string $type, string $key): bool
    {
        $alertType = $type === 'non_liste' ? 'non_liste_attente' : 'hors_periode';
        $existing = DB::table('alerts')->where('subject_id', $visitor)->where('type', $alertType)->whereNull('acknowledged_at')->whereNull('resolved_at')->orderBy('id')->first(['id', 'severity']);
        if ($existing) {
            if ($existing->severity === 'haute') {
                return false;
            }
            DB::table('alerts')->where('id', $existing->id)->update(['severity' => 'haute', 'message' => 'L’hôte n’a pas répondu dans le délai prévu.', 'updated_at' => now()]);

            return true;
        } else {
            $known = DB::table('alerts')->where('dedupe_key', $key)->exists();
            app(Alerts::class)->raise($event, $alertType, 'haute', 'visiteur', $visitor, 'L’hôte n’a pas répondu dans le délai prévu.', $key);

            return ! $known;
        }
    }
}
