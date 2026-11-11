<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registre commun des badges physiques (S05). Un badge n'appartient pas à une activité : il est mis à disposition d'une activité, attribué à une
 * participation pour un temps, restitué, puis peut être mis à disposition d'une autre activité.
 *
 * Mise à disposition = changement de l'activité de rattachement COURANTE (badges.event_id), uniquement pour un badge Disponible (donc ni attribué, ni
 * restitué non clos, ni annulé), ni carte permanente, ni imprimé pour une personne précise. La catégorie est celle choisie dans la nouvelle activité (la catégorie d'une activité n'existe pas dans l'autre)
 * et les réservations propres à l'ancienne activité sont levées. Le support physique doit porter la nouvelle catégorie (réimpression ou étiquette). Chaque mise à disposition est conservée dans badge_transfers et dans le journal d'audit.
 */
final class BadgeRegistry
{
    public const MAX_PER_CALL = 200;

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<string>  $badgeIds
     */
    public function transfer(array $badgeIds, string $toEventId, string $categoryId, ?string $reserveDeviceId, User $actor): int
    {
        if (! $actor->can('badges.gerer')) {
            throw new BadgeRefused('non_autorise', 'Action non autorisée pour ce compte.');
        }
        $badgeIds = array_values(array_unique($badgeIds));
        if ($badgeIds === [] || count($badgeIds) > self::MAX_PER_CALL) {
            throw new BadgeRefused('quantite', 'Choisissez entre 1 et '.self::MAX_PER_CALL.' badges.');
        }
        sort($badgeIds);

        return DB::transaction(function () use ($badgeIds, $toEventId, $categoryId, $reserveDeviceId, $actor): int {
            EventAvailability::requireOpen($toEventId);
            if (! DB::table('events')->where('id', $toEventId)->exists()) {
                throw new BadgeRefused('activite', 'Choisissez une activité existante.');
            }
            if (! DB::table('categories')->where('id', $categoryId)->where('event_id', $toEventId)->exists()) {
                throw new BadgeRefused('categorie', 'Cette catégorie n’appartient pas à l’activité choisie.');
            }
            if ($reserveDeviceId !== null && ! DB::table('devices')->where('id', $reserveDeviceId)->where('event_id', $toEventId)->whereNull('revoked_at')->exists()) {
                throw new BadgeRefused('poste_inconnu', 'Ce poste n’appartient pas à l’activité choisie ou est révoqué.');
            }
            $sources = [];
            foreach ($badgeIds as $id) {
                $badge = DB::table('badges')->where('id', $id)->lockForUpdate()->first();
                if ($badge === null) {
                    throw new BadgeRefused('inconnu', 'Badge introuvable.');
                }
                if ($badge->status !== 'Disponible') {
                    throw new BadgeRefused('non_disponible', "Le badge {$badge->code} n’est pas disponible : seul un badge disponible peut être mis à disposition d’une autre activité.");
                }
                if (DB::table('badge_assignments')->where('badge_id', $id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
                    throw new BadgeRefused('attribue', "Le badge {$badge->code} est attribué à une personne.");
                }
                if ($badge->event_id === $toEventId) {
                    throw new BadgeRefused('deja_rattache', "Le badge {$badge->code} est déjà rattaché à cette activité.");
                }
                if (DB::table('holder_cards')->where('badge_id', $id)->exists()) {
                    throw new BadgeRefused('carte_permanente', "Le badge {$badge->code} est une carte de titulaire permanent.");
                }
                if ($badge->printed_for_visitor_id !== null) {
                    throw new BadgeRefused('imprime_pour_une_personne', "Le badge {$badge->code} a été imprimé pour une personne précise.");
                }
                if ($badge->event_id === null) {
                    throw new BadgeRefused('carte_permanente', "Le badge {$badge->code} appartient au site : il ne devient pas un badge d’activité.");
                }
                if (DB::table('badges')->where('event_id', $toEventId)->where('code', $badge->code)->exists()) {
                    throw new BadgeRefused('code_deja_utilise', "L’activité choisie possède déjà un badge de code {$badge->code}.");
                }
                $sources[$id] = $badge;
            }
            $now = now();
            $touched = [$toEventId => true];
            foreach ($sources as $id => $badge) {
                $released = $badge->reserved_device_id !== null || $badge->reserved_group_id !== null;
                DB::table('badges')->where('id', $id)->update(['event_id' => $toEventId, 'category_id' => $categoryId, 'reserved_group_id' => null, 'reserved_device_id' => $reserveDeviceId, 'updated_at' => $now]);
                DB::table('badge_transfers')->insert(['id' => (string) Str::uuid(), 'badge_id' => $id, 'from_event_id' => $badge->event_id, 'to_event_id' => $toEventId, 'actor_id' => $actor->id, 'reservation_released' => $released, 'created_at' => $now, 'updated_at' => $now]);
                $touched[$badge->event_id] = true;
            }
            // Les tablettes des deux activités doivent recopier leur liste de badges.
            DB::table('events')->whereIn('id', array_keys($touched))->increment('list_revision');
            $this->audit->append('badge.mis_a_disposition', $actor->id, ['nombre' => count($sources), 'vers_event_id' => $toEventId, 'depuis_event_ids' => array_values(array_unique(array_map(fn ($b) => $b->event_id, $sources))), 'categorie_id' => $categoryId, 'poste_reserve' => $reserveDeviceId]);

            return count($sources);
        });
    }

    /**
     * Badges disponibles (hors cartes permanentes) pouvant être mis à disposition d'une autre activité.
     *
     * @return Collection<int, \stdClass>
     */
    public function available(?string $notInEventId = null, int $limit = 200): Collection
    {
        return DB::table('badges as b')->join('events as e', 'e.id', '=', 'b.event_id')
            ->where('b.status', 'Disponible')->whereNull('b.printed_for_visitor_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('holder_cards as h')->whereColumn('h.badge_id', 'b.id'))
            ->when($notInEventId !== null, fn ($q) => $q->where('b.event_id', '<>', $notInEventId))
            ->orderBy('e.name')->orderBy('b.code')->limit($limit)
            ->get(['b.id', 'b.code', 'b.event_id', 'e.name as event_name']);
    }
}
