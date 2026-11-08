<?php

namespace App\Services;

use App\Models\User;
use App\Services\Poste\DeskRefused;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Visites annoncées (S09). Une visite annoncée est une PARTICIPATION (fiche `visitors`) portant une heure d'arrivée attendue, l'annonceur et l'horodatage de l'annonce.
 * Annoncer n'est jamais une présence : le statut reste « attendu » jusqu'à l'entrée enregistrée par l'accueil ou le contrôle.
 * Un retard est calculé (heure attendue + délai de tolérance du réglage visite_retard_minutes) ; une absence est CONSTATÉE explicitement, avec motif, par le superviseur ou l'administrateur.
 * Aucun nom de personne n'est écrit dans le journal : seulement des identifiants.
 */
final class Visits
{
    public function __construct(private readonly Audit $audit) {}

    /** Délai (minutes) après l'heure attendue au-delà duquel une visite non arrivée est « en retard » : réglage d'Administration, 15 minutes au départ. */
    public static function lateAfterMinutes(): int
    {
        return max(1, (int) (DB::table('settings')->where('key', 'visite_retard_minutes')->value('value') ?? 15));
    }

    /**
     * Annonce (ou modifie l'heure attendue d') une visite. L'administrateur annonce pour toute participation ; un hôte uniquement pour ses propres invités.
     *
     * @throws ValidationException
     */
    public function announce(User $actor, string $participationId, string $expectedAt): void
    {
        $isAdmin = $actor->can('listes.importer');
        if (! $isAdmin && ! $actor->can('listes.mettre_a_jour')) {
            abort(403);
        }
        $expected = $this->parse($expectedAt);
        DB::transaction(function () use ($actor, $participationId, $expected, $isAdmin): void {
            if (! $isAdmin) {
                try {
                    app(HostGuests::class)->owned($actor, $participationId, true);
                } catch (DeskRefused) {
                    abort(403);
                }
            }
            $v = DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['participation' => 'Participation introuvable.']);
            $this->assertOpen($v);
            if (($v->status ?? 'attendu') !== 'attendu') {
                throw ValidationException::withMessages(['participation' => 'Cette personne est déjà arrivée ou a été traitée : sa visite ne peut plus être annoncée.']);
            }
            $event = DB::table('events')->where('id', $v->event_id)->first();
            if ($event === null || $event->is_permanent || in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
                throw ValidationException::withMessages(['participation' => 'Cette activité est clôturée, archivée ou permanente : aucune visite ne peut y être annoncée.']);
            }
            if ($expected->lt(Carbon::parse($event->starts_at)) || $expected->gt(Carbon::parse($event->ends_at))) {
                throw ValidationException::withMessages(['expected_at' => 'L’heure attendue doit tomber pendant l’activité.']);
            }
            $changed = $v->expected_at !== null;
            $now = now()->format('Y-m-d H:i:s.uP');
            DB::table('visitors')->where('id', $v->id)->update(['expected_at' => $expected->format('Y-m-d H:i:s.uP'), 'announced_by' => $actor->id, 'announced_at' => $now, 'no_show_at' => null, 'no_show_by' => null, 'no_show_reason' => null, 'updated_at' => $now]);
            $this->audit->append($changed ? 'visite.annonce_modifiee' : 'visite.annoncee', $actor->id, ['participation_id' => $v->id, 'event_id' => $v->event_id, 'attendu_a' => $expected->utc()->format('Y-m-d\TH:i:s\Z')]);
        });
    }

    /**
     * Constate qu'une personne attendue ne s'est pas présentée : motif obligatoire, auteur et heure tracés. N'efface ni la participation ni l'annonce, et n'interdit rien :
     * si la personne arrive ensuite, l'accueil l'admet normalement et l'absence reste dans l'historique.
     *
     * @throws ValidationException
     */
    public function markNoShow(User $actor, string $participationId, string $reason): void
    {
        abort_unless($actor->can('pilotage.consulter'), 403);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Le motif de l’absence est obligatoire (200 caractères au plus).']);
        }
        DB::transaction(function () use ($actor, $participationId, $reason): void {
            $v = DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() ?? throw ValidationException::withMessages(['participation' => 'Participation introuvable.']);
            $this->assertOpen($v);
            if ($v->expected_at === null) {
                throw ValidationException::withMessages(['participation' => 'Cette visite n’a pas d’heure attendue : il n’y a pas d’absence à constater.']);
            }
            if (($v->status ?? 'attendu') !== 'attendu') {
                throw ValidationException::withMessages(['participation' => 'Cette personne est déjà arrivée ou a été traitée : on ne peut plus constater son absence.']);
            }
            if ($v->no_show_at !== null) {
                throw ValidationException::withMessages(['participation' => 'L’absence de cette personne est déjà constatée.']);
            }
            if (Carbon::parse($v->expected_at)->isFuture()) {
                throw ValidationException::withMessages(['participation' => 'L’heure attendue n’est pas encore passée.']);
            }
            $now = now()->format('Y-m-d H:i:s.uP');
            DB::table('visitors')->where('id', $v->id)->update(['no_show_at' => $now, 'no_show_by' => $actor->id, 'no_show_reason' => $reason, 'updated_at' => $now]);
            $this->audit->append('visite.absence_constatee', $actor->id, ['participation_id' => $v->id, 'event_id' => $v->event_id]);
        });
    }

    /**
     * Visites annoncées d'une activité avec leur situation : « attendu » (à venir), « en_retard », « absent » (constaté), « present », « sorti », « refuse ».
     * Lecture seule ; les noms ne sont affichés qu'ici, à la consultation.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string,int>, late_after_minutes: int}
     */
    public function expected(string $eventId, ?string $state = null): array
    {
        $late = self::lateAfterMinutes();
        $rows = DB::select(
            "SELECT * FROM (
                SELECT v.id, v.full_name, v.organisation, v.expected_at, v.announced_at, v.no_show_at, v.no_show_reason, c.name AS category_name, c.color, c.priority_circuit, h.name AS host_name,
                    CASE WHEN v.status = 'present' THEN 'present' WHEN v.status = 'sorti' THEN 'sorti' WHEN v.status = 'refuse' THEN 'refuse' WHEN v.no_show_at IS NOT NULL THEN 'absent'
                         WHEN v.expected_at < ? THEN 'en_retard' ELSE 'attendu' END AS state,
                    (SELECT string_agg(x.plate, ', ' ORDER BY x.plate) FROM vehicles x WHERE x.visitor_id = v.id AND x.status IN ('annonce','present')) AS plates
                FROM visitors v JOIN categories c ON c.id = v.category_id LEFT JOIN hosts h ON h.id = v.host_id
                WHERE v.event_id = ? AND v.expected_at IS NOT NULL AND v.withdrawn_at IS NULL AND v.closed_at IS NULL
            ) t ORDER BY expected_at ASC, full_name ASC LIMIT 500",
            [Carbon::now()->subMinutes($late)->format('Y-m-d H:i:s.uP'), $eventId],
        );
        $counts = ['attendu' => 0, 'en_retard' => 0, 'absent' => 0, 'present' => 0, 'sorti' => 0, 'refuse' => 0];
        foreach ($rows as $row) {
            $counts[$row->state]++;
        }
        $list = collect($rows)->when(isset($counts[(string) $state]), fn ($c) => $c->where('state', $state))->values()
            ->map(fn ($r) => ['id' => $r->id, 'full_name' => $r->full_name, 'organisation' => $r->organisation, 'expected_at' => $r->expected_at, 'announced_at' => $r->announced_at, 'state' => $r->state, 'no_show_reason' => $r->no_show_reason,
                'category' => ['name' => $r->category_name, 'color' => $r->color, 'priority_circuit' => (bool) $r->priority_circuit], 'host' => $r->host_name, 'plates' => $r->plates])->all();

        return ['rows' => $list, 'counts' => $counts, 'late_after_minutes' => $late];
    }

    private function parse(string $value): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['expected_at' => 'Heure attendue invalide.']);
        }
    }

    private function assertOpen(\stdClass $v): void
    {
        if ($v->holder_id !== null) {
            throw ValidationException::withMessages(['participation' => 'Un titulaire permanent n’a pas de visite à annoncer.']);
        }
        if ($v->closed_at !== null || $v->withdrawn_at !== null) {
            throw ValidationException::withMessages(['participation' => 'Cette participation est clôturée ou retirée de la liste.']);
        }
    }
}
