<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Participation = rattachement d'UNE personne à UNE activité (fiche `visitors`), avec sa catégorie (son rôle dans l'activité), sa période et son statut.
 * Chaque activité reste indépendante : inscrire ou clôturer une participation ne touche ni la personne ni ses autres participations.
 */
final class Participations
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Inscrit une personne existante à une activité.
     *
     * @param  array{event_id: string, category_id: string, host_id?: ?string, valid_from?: ?string, valid_until?: ?string}  $data
     */
    public function enrol(User $actor, string $personId, array $data): string
    {
        abort_unless($actor->can('listes.importer'), 403);

        return DB::transaction(function () use ($actor, $personId, $data): string {
            $person = DB::table('persons')->where('id', $personId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['person' => 'Personne introuvable.']);
            if ($person->status !== 'actif') {
                throw ValidationException::withMessages(['person' => 'Cette personne est archivée.']);
            }
            $event = DB::table('events')->where('id', $data['event_id'])->lockForUpdate()->first() ?? throw ValidationException::withMessages(['event_id' => 'Activité introuvable.']);
            if ($event->is_permanent) {
                throw ValidationException::withMessages(['event_id' => 'Le contexte permanent n’accepte pas d’inscription ordinaire.']);
            }
            if (in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
                throw ValidationException::withMessages(['event_id' => 'Cette activité est clôturée, archivée ou anonymisée.']);
            }
            $category = DB::table('categories')->where('id', $data['category_id'])->where('event_id', $event->id)->first() ?? throw ValidationException::withMessages(['category_id' => 'Catégorie inconnue pour cette activité.']);
            $hostId = $data['host_id'] ?? null;
            if ($hostId !== null && ! DB::table('hosts')->where('id', $hostId)->where('event_id', $event->id)->exists()) {
                throw ValidationException::withMessages(['host_id' => 'Hôte inconnu pour cette activité.']);
            }
            $from = Carbon::parse($data['valid_from'] ?? $event->starts_at);
            $until = Carbon::parse($data['valid_until'] ?? $event->ends_at);
            if ($until->lt($from)) {
                throw ValidationException::withMessages(['valid_until' => 'La fin de validité précède le début.']);
            }
            if (DB::table('visitors')->where('person_id', $personId)->where('event_id', $event->id)->exists()) {
                throw ValidationException::withMessages(['event_id' => 'Cette personne participe déjà à cette activité.']);
            }
            $id = (string) Str::uuid();
            $at = now()->format('Y-m-d H:i:s.uP');
            DB::table('visitors')->insert(['id' => $id, 'event_id' => $event->id, 'person_id' => $personId, 'full_name' => $person->full_name, 'organisation' => $person->organisation, 'category_id' => $category->id, 'host_id' => $hostId,
                'valid_from' => $from->format('Y-m-d H:i:s.uP'), 'valid_until' => $until->format('Y-m-d H:i:s.uP'), 'source' => 'listé', 'status' => 'attendu', 'created_at' => $at, 'updated_at' => $at]);
            DB::table('events')->where('id', $event->id)->increment('list_revision');
            $this->audit->append('participation.creee', $actor->id, ['participation_id' => $id, 'person_id' => $personId, 'event_id' => $event->id, 'category_id' => $category->id]);

            return $id;
        });
    }

    /**
     * Clôture individuelle d'une participation. N'efface jamais la personne, ses autres participations, ses attributions ni ses mouvements.
     * Refusée tant que la personne est présente ou qu'un badge reste attribué : une clôture ne déclare jamais un badge « restitué » (la restitution est explicite).
     */
    public function close(User $actor, string $participationId, string $reason): void
    {
        abort_unless($actor->can('listes.importer'), 403);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Le motif de clôture est obligatoire (200 caractères au plus).']);
        }
        DB::transaction(function () use ($actor, $participationId, $reason): void {
            $v = DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['participation' => 'Participation introuvable.']);
            if ($v->holder_id !== null) {
                throw ValidationException::withMessages(['participation' => 'Une participation de titulaire permanent se clôt par la clôture du titulaire.']);
            }
            if ($v->closed_at !== null) {
                throw ValidationException::withMessages(['participation' => 'Cette participation est déjà clôturée.']);
            }
            if ($v->withdrawn_at !== null) {
                throw ValidationException::withMessages(['participation' => 'Cette participation a été retirée de la liste.']);
            }
            if ($v->status === 'present') {
                throw ValidationException::withMessages(['participation' => 'La personne est encore présente : enregistrez d’abord sa sortie.']);
            }
            if (DB::table('badge_assignments')->where('visitor_id', $v->id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
                throw ValidationException::withMessages(['participation' => 'Un badge est encore attribué à cette personne : restituez-le d’abord (une clôture ne vaut jamais restitution).']);
            }
            DB::table('visitors')->where('id', $v->id)->update(['closed_at' => now(), 'closed_by' => $actor->id, 'closure_reason' => $reason, 'updated_at' => now()->format('Y-m-d H:i:s.uP')]);
            DB::table('events')->where('id', $v->event_id)->increment('list_revision');
            $this->audit->append('participation.cloturee', $actor->id, ['participation_id' => $v->id, 'person_id' => $v->person_id, 'event_id' => $v->event_id]);
        });
    }
}
