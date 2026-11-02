<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Décision d'accès d'un titulaire permanent (A06). Ce n'est PAS un second moteur de contrôle : SiteControl (contrôle et sortie) l'appelle quand le QR présenté
 * est celui d'une carte permanente, et garde ses journaux, ses alertes, sa synchronisation et ses présences.
 *
 * Règles, dans cet ordre (refus par défaut) :
 *  1. la carte est active (une carte perdue, volée ou révoquée est refusée tout de suite) ;
 *  2. le titulaire est actif et sa période d'engagement couvre aujourd'hui ;
 *  3. l'événement est ouvert ;
 *  4. une habilitation non retirée et en cours de validité couvre la zone demandée (ou, au poste d'entrée, au moins une zone) ;
 *  5. la zone a une politique qui accepte les titulaires permanents.
 * Ni la catégorie AILCT, ni la possession de la carte, ni un rôle n'ouvrent quoi que ce soit.
 * La sortie n'est jamais soumise à ces règles (voir SiteControl::exit) : on doit toujours pouvoir sortir et être décompté à l'évacuation.
 */
final class PermanentAccess
{
    public const OFFLINE_MAX_MINUTES = 1440;

    public function __construct(private readonly PermanentHolders $holders, private readonly Audit $audit) {}

    /**
     * Retrouve la carte permanente (peu importe son statut) à partir du QR « IKOMA1:<jeton> », du jeton, ou de « PC:<sha256 du jeton> » (rejeu d'une tablette
     * qui ne conserve jamais le jeton). Retourne null si le code n'est pas une carte permanente.
     */
    public function resolveCard(string $code): ?object
    {
        $input = trim($code);
        $context = $this->holders->permanentContext();
        if ($context === null) {
            return null;
        }
        $query = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->where('b.event_id', $context->id);
        if (preg_match('/^PC:([0-9a-f]{64})$/i', $input, $m) === 1) {
            $query->whereRaw("encode(sha256(convert_to(b.qr_token, 'UTF8')), 'hex') = ?", [strtolower($m[1])]);
        } else {
            $token = str_starts_with($input, QrSvg::PREFIX) ? substr($input, strlen(QrSvg::PREFIX)) : $input;
            if (preg_match('/^[0-9a-f]{64}$/i', $token) !== 1) {
                return null;
            }
            $query->where('b.qr_token', strtolower($token));
        }

        return $query->first(['k.id as card_id', 'k.status as card_status', 'k.revocation_reason', 'k.rank', 'b.id as badge_id', 'b.code as badge_code', 'h.id as holder_id', 'h.identifier', 'h.full_name', 'h.organisation', 'h.status as holder_status', 'h.institutional_category', 'h.engagement_starts_on', 'h.engagement_ends_on']);
    }

    /**
     * @param  array{type:string,id:?string,name:string}  $point
     * @return array{allowed: bool, reason: ?string, message: string, zones: list<string>}
     */
    public function decide(object $card, string $eventId, array $point, CarbonInterface $now): array
    {
        $refuse = fn (string $reason, string $message): array => ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
        if ($card->card_status !== 'active') {
            return match ($card->card_status) {
                'perdue' => $refuse('carte_perdue', 'Carte déclarée perdue : accès refusé. Prévenez le superviseur.'),
                'volee' => $refuse('carte_volee', 'Carte déclarée volée : accès refusé. Prévenez le superviseur.'),
                default => $refuse('carte_revoquee', 'Carte révoquée : accès refusé. Prévenez le superviseur.'),
            };
        }
        if ($card->holder_status !== 'actif') {
            return $refuse('titulaire_clos', 'Titulaire clos : accès refusé.');
        }
        $today = $now->copy()->utc()->toDateString();
        if ($card->engagement_starts_on !== null && $today < substr((string) $card->engagement_starts_on, 0, 10)) {
            return $refuse('engagement_non_commence', 'L’engagement de ce titulaire n’a pas encore commencé.');
        }
        if ($card->engagement_ends_on !== null && $today > substr((string) $card->engagement_ends_on, 0, 10)) {
            return $refuse('engagement_termine', 'L’engagement de ce titulaire est terminé.');
        }
        $event = DB::table('events')->where('id', $eventId)->first(['status', 'anonymized_at', 'is_permanent']);
        if ($event === null || $event->is_permanent || in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
            return $refuse('evenement_indisponible', 'Cet événement est clôturé, archivé ou anonymisé : aucune entrée n’est autorisée.');
        }
        $entitlements = DB::table('holder_entitlements as e')->join('zones as z', 'z.id', '=', 'e.zone_id')->leftJoin('zone_policies as p', 'p.zone_id', '=', 'z.id')
            ->where('e.holder_id', $card->holder_id)->where('e.event_id', $eventId)->whereNull('e.revoked_at')
            ->when($point['type'] === 'zone', fn ($q) => $q->where('e.zone_id', $point['id']))
            ->get(['e.valid_from', 'e.valid_until', 'z.name', 'p.permanent_allowed']);
        $current = $entitlements->filter(fn ($e) => strtotime((string) $e->valid_from) <= $now->getTimestamp())
            ->filter(fn ($e) => strtotime((string) $e->valid_until) > $now->getTimestamp());
        if ($current->isEmpty()) {
            $expired = $entitlements->contains(fn ($e) => strtotime((string) $e->valid_until) <= $now->getTimestamp());

            return $expired
                ? $refuse('habilitation_expiree', 'Habilitation expirée : accès refusé.')
                : $refuse('habilitation_absente', $point['type'] === 'zone' ? 'Aucune habilitation valable pour cette zone : accès refusé.' : 'Aucune habilitation valable pour cet événement : accès refusé.');
        }
        $permitted = $current->filter(fn ($e) => (bool) $e->permanent_allowed);
        if ($permitted->isEmpty()) {
            return $refuse('zone_sans_politique', 'Zone sans politique d’accès pour les titulaires permanents : entrée refusée par défaut.');
        }

        return ['allowed' => true, 'reason' => null, 'message' => 'Carte permanente valide.', 'zones' => $permitted->pluck('name')->unique()->sort()->values()->all()];
    }

    /**
     * Garde supplémentaire pour une décision rejouée après un fonctionnement hors ligne (heure déclarée par la tablette). Le serveur ne fait jamais confiance
     * à la tablette seule : il refuse la nouvelle entrée sans politique approuvée, au-delà de la durée de confiance, ou dans une zone qui n'accepte pas l'entrée hors ligne.
     *
     * @param  array{type:string,id:?string,name:string}  $point
     * @return array{allowed: false, reason: string, message: string, zones: list<string>}|null
     */
    public function offlineRefusal(object $card, string $eventId, array $point, string $declaredAt, ?string $knownAt, CarbonInterface $now): ?array
    {
        $refuse = fn (string $reason, string $message): array => ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
        $trust = $this->offlineTrust()['effective'];
        if ($trust < 1) {
            return $refuse('hors_ligne_non_autorise', 'Entrée hors ligne non autorisée : aucune durée de confiance approuvée par l’institution.');
        }
        try {
            $declared = Carbon::parse($declaredAt);
            $known = $knownAt === null ? null : Carbon::parse($knownAt);
        } catch (\Throwable) {
            return $refuse('confiance_expiree', 'Confiance hors ligne expirée : heure de dernière synchronisation illisible.');
        }
        if ($known === null || $known->greaterThan($declared) || $known->diffInMinutes($declared) > $trust) {
            return $refuse('confiance_expiree', 'Confiance hors ligne expirée : la tablette n’avait pas été synchronisée assez récemment pour autoriser cette entrée.');
        }
        $allowed = DB::table('holder_entitlements as e')->join('zone_policies as p', 'p.zone_id', '=', 'e.zone_id')
            ->where('e.holder_id', $card->holder_id)->where('e.event_id', $eventId)->whereNull('e.revoked_at')->where('p.permanent_allowed', true)->where('p.offline_entry_allowed', true)
            ->when($point['type'] === 'zone', fn ($q) => $q->where('e.zone_id', $point['id']))->exists();

        return $allowed ? null : $refuse('hors_ligne_non_autorise', 'Cette zone n’accepte pas l’entrée des titulaires permanents hors ligne.');
    }

    /** Fiche de participation du titulaire à l'événement (créée à la première entrée autorisée ; une seule par titulaire et par événement). */
    public function participation(object $card, string $eventId): string
    {
        $existing = DB::table('visitors')->where('holder_id', $card->holder_id)->where('event_id', $eventId)->value('id');
        if ($existing !== null) {
            return $existing;
        }
        $code = $card->institutional_category;
        $category = $code === null ? null : DB::table('categories')->where('event_id', $eventId)->where('institutional_code', $code)->value('id');
        if ($category === null) {
            $label = $code === null ? 'Titulaire permanent' : (string) DB::table('institutional_categories')->where('code', $code)->value('label');
            $color = $code === null ? InstitutionalCategories::NEUTRAL_COLOR : (string) DB::table('institutional_categories')->where('code', $code)->value('color');
            $name = $label;
            if (DB::table('categories')->where('event_id', $eventId)->where('name', $name)->exists()) {
                $name .= ' (permanent)';
            }
            $category = (string) Str::uuid();
            // Catégorie SANS zone : elle ne donne aucun accès ; les habilitations seules ouvrent les zones.
            DB::table('categories')->insert(['id' => $category, 'event_id' => $eventId, 'name' => $name, 'color' => $color, 'priority_circuit' => false, 'institutional_code' => $code, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::uuid();
        $event = DB::table('events')->where('id', $eventId)->first(['starts_at', 'ends_at']);
        DB::table('visitors')->insert(['id' => $id, 'event_id' => $eventId, 'full_name' => $card->full_name, 'organisation' => $card->organisation, 'category_id' => $category, 'valid_from' => $event->starts_at, 'valid_until' => $event->ends_at, 'source' => 'listé', 'status' => 'attendu', 'holder_id' => $card->holder_id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    // --- Confiance hors ligne ---------------------------------------------------------------------------------------------------

    /**
     * Durée de confiance hors ligne EFFECTIVE pour les nouvelles entrées des titulaires permanents. Elle n'est active que si l'institution a fixé une durée ET
     * confirmé son approbation ; sinon 0 : toute nouvelle entrée hors ligne est refusée.
     *
     * @return array{configured: int, approved: bool, effective: int}
     */
    public function offlineTrust(): array
    {
        $minutes = (int) (DB::table('settings')->where('key', 'titulaire_hors_ligne_minutes')->value('value') ?? 0);
        $approved = (string) (DB::table('settings')->where('key', 'titulaire_hors_ligne_approuve')->value('value') ?? '0') === '1';
        $minutes = max(0, min(self::OFFLINE_MAX_MINUTES, $minutes));

        return ['configured' => $minutes, 'approved' => $approved, 'effective' => $approved ? $minutes : 0];
    }

    public function saveOfflineTrust(int $minutes, bool $approved, User $actor): void
    {
        if ($minutes < 0 || $minutes > self::OFFLINE_MAX_MINUTES) {
            throw ValidationException::withMessages(['minutes' => 'Durée comprise entre 0 et '.self::OFFLINE_MAX_MINUTES.' minutes.']);
        }
        if ($approved && $minutes < 1) {
            throw ValidationException::withMessages(['approved' => 'Une durée est nécessaire pour confirmer l’approbation.']);
        }
        DB::transaction(function () use ($minutes, $approved, $actor): void {
            foreach (['titulaire_hors_ligne_minutes' => (string) $minutes, 'titulaire_hors_ligne_approuve' => $approved ? '1' : '0'] as $key => $value) {
                DB::table('settings')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
            }
            $this->audit->append('titulaire.confiance_hors_ligne_modifiee', $actor->id, ['minutes' => $minutes, 'approuvee' => $approved]);
        });
    }

    /**
     * Données remises à la tablette d'un événement : uniquement des hachages de jetons, jamais de jeton ni de donnée superflue.
     * - `cards` : les cartes connues (actives ET révoquées, pour que la révocation soit connue localement) ;
     * - `holders` : titulaires ayant au moins une habilitation non retirée dans cet événement, avec leurs habilitations et la politique de chaque zone.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $eventId): array
    {
        $context = $this->holders->permanentContext();
        $trust = $this->offlineTrust();
        if ($context === null) {
            return ['trust_minutes' => $trust['effective'], 'cards' => [], 'holders' => []];
        }
        $iso = fn (?string $v): ?string => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $cards = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->get(['b.qr_token', 'k.status', 'h.id as holder_id', 'h.identifier'])
            ->map(fn ($c) => ['hash' => hash('sha256', $c->qr_token), 'status' => $c->status, 'holder_id' => $c->holder_id, 'identifier' => $c->identifier])->all();
        $holderIds = DB::table('holder_entitlements')->where('event_id', $eventId)->whereNull('revoked_at')->distinct()->pluck('holder_id');
        $holders = DB::table('permanent_holders')->whereIn('id', $holderIds)->get(['id', 'identifier', 'full_name', 'organisation', 'status', 'engagement_starts_on', 'engagement_ends_on']);
        $entitlements = DB::table('holder_entitlements')->where('event_id', $eventId)->whereNull('revoked_at')->get(['holder_id', 'zone_id', 'valid_from', 'valid_until'])->groupBy('holder_id');
        $policies = DB::table('zone_policies')->where('event_id', $eventId)->get(['zone_id', 'permanent_allowed', 'offline_entry_allowed'])->map(fn ($p) => ['zone_id' => $p->zone_id, 'permanent_allowed' => (bool) $p->permanent_allowed, 'offline_entry_allowed' => (bool) $p->offline_entry_allowed])->all();

        return [
            'trust_minutes' => $trust['effective'], 'cards' => $cards, 'policies' => $policies,
            'holders' => $holders->map(fn ($h) => ['id' => $h->id, 'identifier' => $h->identifier, 'name' => $h->full_name, 'organisation' => $h->organisation, 'status' => $h->status, 'starts_on' => $h->engagement_starts_on, 'ends_on' => $h->engagement_ends_on,
                'entitlements' => ($entitlements[$h->id] ?? collect())->map(fn ($e) => ['zone_id' => $e->zone_id, 'valid_from' => $iso($e->valid_from), 'valid_until' => $iso($e->valid_until)])->values()->all()])->all(),
        ];
    }
}
