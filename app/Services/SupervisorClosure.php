<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Clôture réservée au SUPERVISEUR (S09) : parcours des personnes dont la catégorie porte l'indicateur categories.closure_supervisor_only (par exemple les VIP).
 * L'agent d'accueil reste refusé (SiteControl::exit, raison « cloture_reservee_superviseur ») ; seul ce parcours, réservé à qui détient le droit propre du superviseur
 * (cloture.superviseur), enregistre en une seule opération atomique : la sortie (si la personne est présente), la restitution du badge attribué, l'annulation des véhicules
 * seulement annoncés et la clôture de la participation. Motif obligatoire, confirmation explicite, journal sans nom de personne.
 *
 * Refus : catégorie ordinaire (elle suit le parcours de l'accueil), participation déjà clôturée ou retirée, titulaire permanent, véhicule encore présent
 * (sa sortie se fait d'abord à un poste, comme pour la personne). La clôture n'efface rien : ni la personne, ni ses autres participations, ni ses mouvements.
 */
final class SupervisorClosure
{
    public function __construct(private readonly Audit $audit, private readonly BadgeService $badges) {}

    /**
     * Clôture proposée au superviseur : seulement si la catégorie de la personne porte l'indicateur (jamais son libellé). « possible » est faux quand la participation
     * est déjà clôturée ou retirée ; « vehicles_present » compte les véhicules à faire sortir d'abord.
     *
     * @return array{possible: bool, vehicles_present: int}|null
     */
    public function offer(string $participationId): ?array
    {
        $v = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->where('v.id', $participationId)->first(['v.closed_at', 'v.withdrawn_at', 'v.holder_id', 'c.closure_supervisor_only']);
        if ($v === null || ! $v->closure_supervisor_only || $v->holder_id !== null) {
            return null;
        }

        return ['possible' => $v->closed_at === null && $v->withdrawn_at === null, 'vehicles_present' => DB::table('vehicles')->where('visitor_id', $participationId)->where('status', 'present')->count()];
    }

    /**
     * @return array{sortie: bool, badge_restitue: bool, vehicules_annules: int}
     *
     * @throws ValidationException
     */
    public function close(User $superviseur, string $participationId, string $reason): array
    {
        abort_unless($superviseur->can('cloture.superviseur'), 403);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Le motif de clôture est obligatoire (200 caractères au plus).']);
        }

        return DB::transaction(function () use ($superviseur, $participationId, $reason): array {
            $v = DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['participation' => 'Participation introuvable.']);
            if ($v->holder_id !== null) {
                throw ValidationException::withMessages(['participation' => 'Un titulaire permanent se clôt par la clôture du titulaire.']);
            }
            if ($v->closed_at !== null || $v->withdrawn_at !== null) {
                throw ValidationException::withMessages(['participation' => 'Cette participation est déjà clôturée ou retirée de la liste.']);
            }
            if (! DB::table('categories')->where('id', $v->category_id)->value('closure_supervisor_only')) {
                throw ValidationException::withMessages(['participation' => 'La catégorie de cette personne suit la clôture ordinaire : sortie et restitution se font à l’accueil.']);
            }
            if (DB::table('vehicles')->where('visitor_id', $v->id)->where('status', 'present')->exists()) {
                throw ValidationException::withMessages(['participation' => 'Un véhicule de cette personne est encore présent sur le site : enregistrez d’abord sa sortie.']);
            }
            $assignment = DB::table('badge_assignments')->where('visitor_id', $v->id)->whereNull('returned_at')->whereNull('cancelled_at')->lockForUpdate()->first();
            $now = now();
            $exited = false;
            if ($v->status === 'present') {
                DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $v->event_id, 'visitor_id' => $v->id, 'badge_id' => $assignment?->badge_id, 'actor_id' => $superviseur->id, 'type' => 'sortie', 'checkpoint' => 'Clôture du superviseur', 'occurred_at' => $now,
                    'details' => json_encode(['cloture_superviseur' => true], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
                DB::table('visitors')->where('id', $v->id)->update(['status' => 'sorti', 'updated_at' => $now->format('Y-m-d H:i:s.uP')]);
                $exited = true;
            }
            $returned = false;
            if ($assignment !== null) {
                $this->badges->restituerPourCloture($assignment->badge_id, $superviseur);
                $returned = true;
            }
            $cancelled = DB::table('vehicles')->where('visitor_id', $v->id)->where('status', 'annonce')->update(['status' => 'annule', 'updated_at' => $now]);
            DB::table('visitors')->where('id', $v->id)->update(['closed_at' => $now, 'closed_by' => $superviseur->id, 'closure_reason' => $reason, 'updated_at' => $now->format('Y-m-d H:i:s.uP')]);
            DB::table('events')->where('id', $v->event_id)->increment('list_revision');
            $this->audit->append('participation.cloturee_superviseur', $superviseur->id, ['participation_id' => $v->id, 'person_id' => $v->person_id, 'event_id' => $v->event_id, 'sortie_enregistree' => $exited, 'badge_restitue' => $returned, 'vehicules_annules' => $cancelled]);

            return ['sortie' => $exited, 'badge_restitue' => $returned, 'vehicules_annules' => $cancelled];
        });
    }
}
