<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Contrat d'ÉMISSION canonique des supports (badges temporaires nominatifs et cartes permanentes). Un badge est un support d'identification : il ne crée aucun droit.
 *
 * Une émission est une OPÉRATION IDENTIFIÉE : (operation_id, personne canonique, contexte, type de support).
 *  - même operation_id rejoué → le même résultat, sans doublon (idempotence, reprise après interruption) ;
 *  - un support « en cours » pour le même contexte refuse une émission DISTINCTE (voies légitimes : conserver, réimprimer, remplacer) ;
 *  - une nouvelle émission est possible quand le cycle précédent est clos (annulation, révocation) : c'est alors un REMPLACEMENT explicite ;
 *  - la RÉAFFECTATION d'un badge temporaire restitué n'est pas une émission : le support garde son identité technique et une NOUVELLE affectation est créée ;
 *  - l'unicité (opération, support, support nominatif en cours, affectation active) est garantie par des contraintes de la base, pas seulement par ce code.
 *
 * Fabrication : BadgeLots::issueSingle (lot technique d'un exemplaire = structure de compatibilité interne, jamais exposée) ; cartes : HolderCards::issue.
 * Ce service ne crée ni personne, ni habilitation, ni validation de participation, ni activation de carte, ni réservation à un poste.
 */
final class BadgeIssuance
{
    public const TEMPORARY = 'badge_temporaire';

    public const CARD = 'carte_permanente';

    public const MAX_PER_CALL = 200;

    public function __construct(private readonly BadgeLots $lots, private readonly HolderCards $cards, private readonly BadgeService $badges, private readonly Audit $audit) {}

    private function authorize(User $actor): void
    {
        abort_unless($actor->can('badges.gerer') && $actor->can('listes.importer'), 403);
    }

    private function requireOperation(string $operationId): void
    {
        if (! Uuid::isValid($operationId)) {
            throw new BadgeRefused('operation_invalide', 'Identifiant d’opération invalide.');
        }
    }

    /** Verrou d'opération puis de contexte, toujours dans cet ordre (pas d'interblocage). */
    private function lock(string $operationId, string $contextId): void
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('badge-operation:'||?, 6))", [$operationId]);
        DB::statement("SELECT pg_advisory_xact_lock(hashtextextended('badge-emission:'||?, 5))", [$contextId]);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    private function replay(string $operationId, array $expected): ?array
    {
        $found = IssuanceLedger::find($operationId);
        if ($found === null) {
            return null;
        }
        foreach ($expected as $key => $value) {
            if ($found->{$key} !== $value) {
                throw new BadgeRefused('operation_reutilisee', 'Cet identifiant d’opération a déjà servi pour une autre personne ou un autre contexte.');
            }
        }

        return $this->result($found, false);
    }

    /** @return array<string, mixed> */
    private function result(object $row, bool $created): array
    {
        return ['operation_id' => $row->operation_id, 'purpose' => $row->purpose, 'support_type' => $row->support_type, 'person_id' => $row->person_id, 'visitor_id' => $row->visitor_id,
            'holder_id' => $row->holder_id, 'event_id' => $row->event_id, 'badge_id' => $row->badge_id, 'code' => $row->code, 'assignment_id' => $row->assignment_id, 'created' => $created];
    }

    // --- Éligibilité (une seule source pour la préparation, le récapitulatif et la génération groupée) ---------------------------------------------------

    /**
     * Raison pour laquelle une participation ne peut pas recevoir de badge temporaire nominatif, ou null.
     *
     * @return array{0: string, 1: string}|null [code, message]
     */
    private function temporaryRefusal(?object $visitor): ?array
    {
        if ($visitor === null) {
            return ['participation_inconnue', 'Participation introuvable.'];
        }
        if ($visitor->holder_id !== null) {
            return ['participation_permanente', 'Cette fiche relève d’un titulaire permanent : préparez sa carte permanente.'];
        }
        if ($visitor->person_id === null) {
            return ['identite_non_rattachee', 'Cette participation n’est pas rattachée à une personne : rattachez d’abord l’identité.'];
        }
        if (DB::table('persons')->where('id', $visitor->person_id)->value('status') !== 'actif') {
            return ['personne_archivee', 'Cette personne est archivée.'];
        }
        if ($visitor->withdrawn_at !== null) {
            return ['invite_retire', 'Cette personne a été retirée de la liste.'];
        }
        if ($visitor->closed_at !== null) {
            return ['participation_cloturee', 'La participation de cette personne est clôturée : aucun badge ne peut lui être préparé.'];
        }
        if ($visitor->status === 'refuse') {
            return ['visiteur_refuse', 'Accès refusé à cette personne : aucun badge ne peut lui être préparé.'];
        }
        if ($visitor->category_id === null) {
            return ['sans_categorie', 'Participation sans catégorie : aucun badge ne peut être préparé.'];
        }
        $event = DB::table('events')->where('id', $visitor->event_id)->first(['status', 'anonymized_at']);
        if ($event === null || in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
            return ['evenement_indisponible', 'Cette activité est clôturée, archivée, anonymisée ou permanente : aucun badge temporaire ne peut être préparé.'];
        }

        return null;
    }

    private function openNominative(string $visitorId): ?object
    {
        return DB::table('badges')->where('printed_for_visitor_id', $visitorId)->whereIn('status', ['Disponible', 'Associé'])->first(['id', 'code', 'status']);
    }

    /**
     * Récapitulatif avant génération (lecture seule) : à générer, déjà pourvu, non éligible. Un identifiant répété dans la sélection n'est compté qu'une fois.
     *
     * @param  list<string>  $participationIds
     * @return array{a_generer: list<string>, existants: list<array{id: string, code: string}>, non_eligibles: list<array{id: string, code: string, message: string}>, doublons_ignores: int}
     */
    public function planTemporary(array $participationIds): array
    {
        $unique = array_values(array_unique($participationIds));
        $out = ['a_generer' => [], 'existants' => [], 'non_eligibles' => [], 'doublons_ignores' => count($participationIds) - count($unique)];
        foreach ($unique as $id) {
            $visitor = Uuid::isValid($id) ? DB::table('visitors')->where('id', $id)->first() : null;
            if ($refusal = $this->temporaryRefusal($visitor)) {
                $out['non_eligibles'][] = ['id' => $id, 'code' => $refusal[0], 'message' => $refusal[1]];
            } elseif ($open = $this->openNominative($id)) {
                $out['existants'][] = ['id' => $id, 'code' => $open->code];
            } else {
                $out['a_generer'][] = $id;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $holderIds
     * @return array{a_generer: list<string>, existants: list<array{id: string, code: string}>, non_eligibles: list<array{id: string, code: string, message: string}>, doublons_ignores: int}
     */
    public function planCards(array $holderIds): array
    {
        $unique = array_values(array_unique($holderIds));
        $out = ['a_generer' => [], 'existants' => [], 'non_eligibles' => [], 'doublons_ignores' => count($holderIds) - count($unique)];
        foreach ($unique as $id) {
            $holder = Uuid::isValid($id) ? DB::table('permanent_holders')->where('id', $id)->first(['id', 'status', 'institutional_category', 'photo_file']) : null;
            $card = $holder === null ? null : DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $id)->where('k.status', 'active')->first(['b.code']);
            if ($holder === null) {
                $out['non_eligibles'][] = ['id' => $id, 'code' => 'titulaire_inconnu', 'message' => 'Titulaire introuvable.'];
            } elseif ($holder->status !== 'actif') {
                $out['non_eligibles'][] = ['id' => $id, 'code' => 'titulaire_clos', 'message' => 'Ce titulaire est clos : aucune carte ne peut lui être préparée.'];
            } elseif ($card !== null) {
                $out['existants'][] = ['id' => $id, 'code' => $card->code];
            } elseif ($holder->institutional_category === null) {
                $out['non_eligibles'][] = ['id' => $id, 'code' => 'sans_categorie_institutionnelle', 'message' => 'Catégorie institutionnelle manquante.'];
            } elseif ($holder->photo_file === null) {
                $out['non_eligibles'][] = ['id' => $id, 'code' => 'photo_manquante', 'message' => 'Photo manquante : une carte permanente ne se prépare pas sans photo.'];
            } else {
                $out['a_generer'][] = $id;
            }
        }

        return $out;
    }

    // --- Émission --------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Badge temporaire nominatif pour UNE participation. `$replaces` : identifiant d'un support nominatif ANNULÉ de la même participation (remplacement explicite).
     *
     * @return array<string, mixed>
     */
    public function issueTemporary(string $participationId, string $operationId, User $actor, ?string $replaces = null): array
    {
        $this->authorize($actor);
        $this->requireOperation($operationId);
        $purpose = $replaces === null ? 'emission' : 'remplacement';
        $expected = ['support_type' => self::TEMPORARY, 'visitor_id' => $participationId, 'purpose' => $purpose];
        try {
            return DB::transaction(function () use ($participationId, $operationId, $actor, $replaces, $purpose, $expected): array {
                $this->lock($operationId, $participationId);
                if ($replayed = $this->replay($operationId, $expected)) {
                    return $replayed;
                }
                $visitor = Uuid::isValid($participationId) ? DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() : null;
                if ($refusal = $this->temporaryRefusal($visitor)) {
                    throw new BadgeRefused($refusal[0], $refusal[1]);
                }
                if ($open = $this->openNominative($participationId)) {
                    throw new BadgeRefused('cycle_ouvert', "Cette personne a déjà le support {$open->code} en cours : conservez-le, réimprimez-le ou engagez un remplacement (annulation explicite).");
                }
                if ($replaces !== null) {
                    $old = DB::table('badges')->where('id', $replaces)->where('printed_for_visitor_id', $participationId)->first(['id', 'status']);
                    if ($old === null || $old->status !== 'Annulé') {
                        throw new BadgeRefused('remplacement_invalide', 'Un remplacement porte sur un support annulé de cette même participation.');
                    }
                    if (DB::table('badge_issuances')->where('replaces_badge_id', $replaces)->exists()) {
                        throw new BadgeRefused('deja_remplace', 'Ce support a déjà été remplacé.');
                    }
                }
                $badge = $this->lots->issueSingle($visitor->event_id, $visitor->category_id, $visitor->id, 'Émission unitaire', $actor);
                IssuanceLedger::record(['operation_id' => $operationId, 'purpose' => $purpose, 'support_type' => self::TEMPORARY, 'person_id' => $visitor->person_id, 'visitor_id' => $visitor->id,
                    'event_id' => $visitor->event_id, 'badge_id' => $badge['id'], 'replaces_badge_id' => $replaces, 'actor_id' => $actor->id]);
                // La destination nominative est transmise aux tablettes : nouvelle révision de la liste.
                DB::table('events')->where('id', $visitor->event_id)->increment('list_revision');
                $this->audit->append('badge.emis', $actor->id, ['operation_id' => $operationId, 'objet' => self::TEMPORARY, 'purpose' => $purpose, 'badge_id' => $badge['id'], 'code' => $badge['code'],
                    'person_id' => $visitor->person_id, 'visitor_id' => $visitor->id, 'event_id' => $visitor->event_id]);

                return $this->result(IssuanceLedger::find($operationId) ?? throw new BadgeRefused('registre', 'Registre des émissions indisponible.'), true);
            });
        } catch (QueryException $e) {
            return $this->afterConflict($e, $operationId, $expected);
        }
    }

    /**
     * Carte permanente pour UN titulaire actif (jamais activée ici : la remise reste explicite).
     *
     * @return array<string, mixed>
     */
    public function issueCard(string $holderId, string $operationId, User $actor): array
    {
        $this->authorize($actor);
        $this->requireOperation($operationId);
        $expected = ['support_type' => self::CARD, 'holder_id' => $holderId, 'purpose' => 'emission'];
        try {
            return DB::transaction(function () use ($holderId, $operationId, $actor, $expected): array {
                $this->lock($operationId, $holderId);
                if ($replayed = $this->replay($operationId, $expected)) {
                    return $replayed;
                }
                $plan = $this->planCards([$holderId]);
                if ($plan['existants'] !== []) {
                    throw new BadgeRefused('cycle_ouvert', "Ce titulaire a déjà la carte {$plan['existants'][0]['code']} : réimprimez-la ou engagez un remplacement (révocation explicite).");
                }
                if ($plan['non_eligibles'] !== []) {
                    throw new BadgeRefused($plan['non_eligibles'][0]['code'], $plan['non_eligibles'][0]['message']);
                }
                $this->cards->issue($holderId, $actor, false, $operationId);

                return $this->result(IssuanceLedger::find($operationId) ?? throw new BadgeRefused('registre', 'Registre des émissions indisponible.'), true);
            });
        } catch (QueryException $e) {
            return $this->afterConflict($e, $operationId, $expected);
        } catch (ValidationException $e) {
            throw new BadgeRefused('refus', implode(' ', array_map(fn ($m) => (string) $m, array_merge(...array_values($e->errors())))));
        }
    }

    /**
     * Conflit d'unicité en concurrence : soit la même opération vient d'aboutir (on la rejoue), soit un support en cours existe déjà (refus explicite).
     *
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function afterConflict(QueryException $e, string $operationId, array $expected): array
    {
        if ($e->getCode() !== '23505') {
            throw $e;
        }
        if ($replayed = $this->replay($operationId, $expected)) {
            return $replayed;
        }
        throw new BadgeRefused('cycle_ouvert', 'Un support est déjà en cours pour cette personne : conservez-le, réimprimez-le ou engagez un remplacement.');
    }

    /**
     * Génération GROUPÉE : orchestre des émissions individuelles par le même contrat, jamais un second moteur. L'opération de lot est identifiée ; chaque personne reçoit une
     * opération dérivée stable, donc rejouer le même lot après une interruption reprend sans duplication. Réussites et échecs sont distingués.
     *
     * @param  list<string>  $contextIds  participations (badges temporaires) ou titulaires (cartes)
     * @return array{complete: bool, created: list<array<string, mixed>>, existing: list<array<string, mixed>>, failed: list<array{id: string, code: string, message: string}>}
     */
    public function issueMany(string $supportType, array $contextIds, string $batchOperationId, User $actor): array
    {
        $this->authorize($actor);
        $this->requireOperation($batchOperationId);
        if (! in_array($supportType, [self::TEMPORARY, self::CARD], true)) {
            throw new BadgeRefused('support_inconnu', 'Type de support inconnu.');
        }
        $ids = array_values(array_unique($contextIds));
        if ($ids === [] || count($ids) > self::MAX_PER_CALL) {
            throw new BadgeRefused('selection', 'Sélectionnez entre 1 et '.self::MAX_PER_CALL.' personnes.');
        }
        $out = ['complete' => true, 'created' => [], 'existing' => [], 'failed' => []];
        foreach ($ids as $id) {
            $operation = Uuid::uuid5($batchOperationId, $supportType.':'.$id)->toString();
            try {
                $r = $supportType === self::TEMPORARY ? $this->issueTemporary($id, $operation, $actor) : $this->issueCard($id, $operation, $actor);
                $out[$r['created'] ? 'created' : 'existing'][] = $r;
            } catch (BadgeRefused $e) {
                // Un support déjà en cours n'est pas une anomalie : il est conservé et signalé comme existant.
                if ($e->reason === 'cycle_ouvert') {
                    $out['existing'][] = ['id' => $id, 'message' => $e->getMessage(), 'created' => false];
                } else {
                    $out['failed'][] = ['id' => $id, 'code' => $e->reason, 'message' => $e->getMessage()];
                    $out['complete'] = false;
                }
            }
        }

        return $out;
    }

    // --- Réaffectation d'un badge temporaire restitué -------------------------------------------------------------------------------------------------

    /**
     * RÉAFFECTATION : le support garde son identité technique (numéro, jeton) ; une NOUVELLE affectation est créée à une autre participation. Distincte d'une émission.
     * Refusée pour un support nominatif d'une autre personne, jamais restitué, déjà affecté, d'une autre activité ou non disponible : les règles sont celles de BadgeService::associer
     * (permission « badges.associer », verrous, index d'affectation unique), appelé tel quel. Les anciennes habilitations ne sont jamais reprises : elles dépendent de la participation.
     *
     * @return array<string, mixed>
     */
    public function reassign(string $badgeId, string $participationId, string $operationId, User $actor): array
    {
        abort_unless($actor->can('badges.associer'), 403);
        $this->requireOperation($operationId);
        $expected = ['support_type' => self::TEMPORARY, 'visitor_id' => $participationId, 'purpose' => 'reaffectation', 'badge_id' => $badgeId];
        try {
            return DB::transaction(function () use ($badgeId, $participationId, $operationId, $actor, $expected): array {
                $this->lock($operationId, $badgeId);
                if ($replayed = $this->replay($operationId, $expected)) {
                    return $replayed;
                }
                $badge = Uuid::isValid($badgeId) ? DB::table('badges')->where('id', $badgeId)->lockForUpdate()->first() : null;
                $visitor = Uuid::isValid($participationId) ? DB::table('visitors')->where('id', $participationId)->lockForUpdate()->first() : null;
                if ($badge === null || $visitor === null) {
                    throw new BadgeRefused('introuvable', 'Support ou participation introuvable.');
                }
                if ($refusal = $this->temporaryRefusal($visitor)) {
                    throw new BadgeRefused($refusal[0], $refusal[1]);
                }
                if ($badge->printed_for_visitor_id !== null && $badge->printed_for_visitor_id !== $visitor->id) {
                    throw new BadgeRefused('destination_nominative', 'Ce support nominatif appartient à une autre personne : il ne se réaffecte pas.');
                }
                if (! DB::table('badge_assignments')->where('badge_id', $badge->id)->whereNotNull('returned_at')->exists()) {
                    throw new BadgeRefused('jamais_restitue', 'Ce support n’a jamais été restitué : utilisez une nouvelle émission ou l’association habituelle.');
                }
                $assignment = $this->badges->associer($badge->id, $visitor->id, $actor);
                IssuanceLedger::record(['operation_id' => $operationId, 'purpose' => 'reaffectation', 'support_type' => self::TEMPORARY, 'person_id' => $visitor->person_id, 'visitor_id' => $visitor->id,
                    'event_id' => $visitor->event_id, 'badge_id' => $badge->id, 'assignment_id' => $assignment->id, 'actor_id' => $actor->id]);
                $this->audit->append('badge.reaffecte', $actor->id, ['operation_id' => $operationId, 'badge_id' => $badge->id, 'code' => $badge->code, 'person_id' => $visitor->person_id,
                    'visitor_id' => $visitor->id, 'assignment_id' => $assignment->id, 'event_id' => $visitor->event_id]);

                return $this->result(IssuanceLedger::find($operationId) ?? throw new BadgeRefused('registre', 'Registre des émissions indisponible.'), true);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && ($replayed = $this->replay($operationId, $expected))) {
                return $replayed;
            }
            if ($e->getCode() === '23505') {
                throw new BadgeRefused('deja_affecte', 'Ce support ou cette personne a déjà une affectation active.');
            }
            throw $e;
        }
    }
}
