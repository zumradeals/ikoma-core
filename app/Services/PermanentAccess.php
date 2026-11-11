<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Décision d'accès d'un titulaire permanent (A06). Ce n'est PAS un second moteur de contrôle : SiteControl (contrôle et sortie) l'appelle quand le QR présenté
 * est celui d'une carte permanente, et garde ses journaux, ses alertes, sa synchronisation et ses présences.
 *
 * Règles, dans cet ordre (refus par défaut) :
 *  1. la carte est active (une carte perdue, volée ou révoquée est refusée tout de suite) ;
 *  2. le titulaire est actif (ses dates d'engagement sont indicatives : elles n'ouvrent ni ne ferment aucun accès) ;
 *  3. l'événement est ouvert ;
 *  4. une habilitation non retirée et en cours de validité couvre la zone demandée (ou, au poste d'entrée, au moins une zone) ;
 *  5. la zone a une politique qui accepte les titulaires permanents.
 * Ni la catégorie AILCT, ni la possession de la carte, ni un rôle n'ouvrent quoi que ce soit.
 * La sortie n'est jamais soumise à ces règles (voir SiteControl::exit) : on doit toujours pouvoir sortir et être décompté à l'évacuation.
 */
final class PermanentAccess
{
    public const OFFLINE_MAX_MINUTES = 1440;

    public function __construct(private readonly Audit $audit) {}

    /**
     * Retrouve la carte permanente (peu importe son statut) à partir du QR « IKOMA1:<jeton> », du jeton, ou de « PC:<sha256 du jeton> » (rejeu d'une tablette
     * qui ne conserve jamais le jeton). Retourne null si le code n'est pas une carte permanente.
     */
    public function resolveCard(string $code): ?object
    {
        $input = trim($code);
        // Une carte permanente est un badge lié à une fiche carte (holder_cards) : son rattachement à un événement (ancien registre) ou au site est indifférent.
        $query = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id');
        if (preg_match('/^PC:([0-9a-f]{64})$/i', $input, $m) === 1) {
            $query->whereRaw("encode(sha256(convert_to(b.qr_token, 'UTF8')), 'hex') = ?", [strtolower($m[1])]);
        } else {
            $token = str_starts_with($input, QrSvg::PREFIX) ? substr($input, strlen(QrSvg::PREFIX)) : $input;
            if (preg_match('/^[0-9a-f]{64}$/i', $token) !== 1) {
                return null;
            }
            $query->where('b.qr_token', strtolower($token));
        }

        return $query->first(['k.id as card_id', 'k.status as card_status', 'k.activated_at', 'k.revocation_reason', 'k.rank', 'b.id as badge_id', 'b.code as badge_code', 'h.id as holder_id', 'h.identifier', 'h.full_name', 'h.organisation', 'h.status as holder_status', 'h.institutional_category', 'h.engagement_starts_on', 'h.engagement_ends_on']);
    }

    /**
     * @param  array{type:string,id:?string,name:string}  $point
     * @return array{allowed: bool, reason: ?string, message: string, zones: list<string>}
     */
    public function decide(object $card, string $eventId, array $point, CarbonInterface $now): array
    {
        if (($refusal = $this->cardRefusal($card)) !== null) {
            return $refusal;
        }
        $event = DB::table('events')->where('id', $eventId)->first(['status', 'anonymized_at', 'is_permanent']);
        $refuse = fn (string $reason, string $message): array => ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
        if ($event === null || $event->is_permanent || in_array($event->status, ['clos', 'archive'], true) || $event->anonymized_at !== null) {
            return $refuse('evenement_indisponible', 'Cet événement est clôturé, archivé ou anonymisé : aucune entrée n’est autorisée.');
        }
        $entitlements = DB::table('holder_entitlements as e')->join('zones as z', 'z.id', '=', 'e.zone_id')->leftJoin('zone_policies as p', 'p.zone_id', '=', 'z.id')
            ->where('e.holder_id', $card->holder_id)->where('e.event_id', $eventId)->whereNull('e.revoked_at')
            ->when($point['type'] === 'zone', fn ($q) => $q->where('e.zone_id', $point['id']))
            ->get(['e.valid_from', 'e.valid_until', 'z.name', 'p.permanent_allowed']);

        return $this->evaluate($entitlements, $now, $point['type'] === 'zone' ? 'Aucune habilitation valable pour cette zone : accès refusé.' : 'Aucune habilitation valable pour cet événement : accès refusé.');
    }

    /**
     * Décision d'accès au SITE (tablette de site, lot 4) : mêmes refus de carte et de titulaire, habilitation = accès du titulaire à l'une des zones du site
     * gardées par le poste (`$zoneIds`), zone ouverte aux permanents par sa politique. Aucun événement n'intervient.
     *
     * @param  list<string>  $zoneIds
     * @return array{allowed: bool, reason: ?string, message: string, zones: list<string>}
     */
    public function decideSite(object $card, array $zoneIds, CarbonInterface $now): array
    {
        if (($refusal = $this->cardRefusal($card)) !== null) {
            return $refusal;
        }
        $entitlements = DB::table('holder_site_entitlements as e')->join('zones as z', 'z.id', '=', 'e.zone_id')->leftJoin('site_zone_policies as p', 'p.zone_id', '=', 'z.id')
            ->where('e.holder_id', $card->holder_id)->whereIn('e.zone_id', $zoneIds)->whereNull('e.revoked_at')
            ->get(['e.valid_from', 'e.valid_until', 'z.name', 'p.permanent_allowed']);

        return $this->evaluate($entitlements, $now, 'Aucune habilitation valable pour le site : accès refusé.');
    }

    /** @return array{allowed: bool, reason: ?string, message: string, zones: list<string>}|null */
    private function cardRefusal(object $card): ?array
    {
        $refuse = fn (string $reason, string $message): array => ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
        if ($card->card_status !== 'active') {
            return match ($card->card_status) {
                'perdue' => $refuse('carte_perdue', 'Carte déclarée perdue : accès refusé. Prévenez le superviseur.'),
                'volee' => $refuse('carte_volee', 'Carte déclarée volée : accès refusé. Prévenez le superviseur.'),
                default => $refuse('carte_revoquee', 'Carte révoquée : accès refusé. Prévenez le superviseur.'),
            };
        }
        if ($card->activated_at === null) {
            return $refuse('carte_non_activee', 'Carte pas encore remise : activez-la dans la fiche du titulaire.');
        }
        if ($card->holder_status !== 'actif') {
            return $refuse('titulaire_clos', 'Titulaire clos : accès refusé.');
        }

        return null;
    }

    /**
     * @param  Collection<int, \stdClass>  $entitlements
     * @return array{allowed: bool, reason: ?string, message: string, zones: list<string>}
     */
    private function evaluate(Collection $entitlements, CarbonInterface $now, string $absent): array
    {
        $refuse = fn (string $reason, string $message): array => ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
        $current = $entitlements->filter(fn ($e) => strtotime((string) $e->valid_from) <= $now->getTimestamp())
            ->filter(fn ($e) => strtotime((string) $e->valid_until) > $now->getTimestamp());
        if ($current->isEmpty()) {
            // Seules les habilitations décident (les dates d'engagement de la fiche sont indicatives) : le refus dit laquelle manque et à quelle date.
            $day = fn (string $date): string => Carbon::parse($date)->utc()->format('d/m/Y');
            $upcoming = $entitlements->filter(fn ($e) => strtotime((string) $e->valid_from) > $now->getTimestamp())->sortBy('valid_from')->first();
            if ($upcoming !== null) {
                return $refuse('habilitation_non_commencee', 'Habilitation pas encore valable : à partir du '.$day((string) $upcoming->valid_from).'.');
            }
            $lastEnd = $entitlements->filter(fn ($e) => strtotime((string) $e->valid_until) <= $now->getTimestamp())->sortByDesc('valid_until')->first();

            return $lastEnd !== null
                ? $refuse('habilitation_expiree', 'Habilitation expirée le '.$day((string) $lastEnd->valid_until).' : accès refusé.')
                : $refuse('habilitation_absente', $absent);
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
        if (($refusal = $this->offlineTrustRefusal($declaredAt, $knownAt)) !== null) {
            return $refusal;
        }
        $allowed = DB::table('holder_entitlements as e')->join('zone_policies as p', 'p.zone_id', '=', 'e.zone_id')
            ->where('e.holder_id', $card->holder_id)->where('e.event_id', $eventId)->whereNull('e.revoked_at')->where('p.permanent_allowed', true)->where('p.offline_entry_allowed', true)
            ->when($point['type'] === 'zone', fn ($q) => $q->where('e.zone_id', $point['id']))->exists();

        return $allowed ? null : $this->offlineRefuse('hors_ligne_non_autorise', 'Cette zone n’accepte pas l’entrée des titulaires permanents hors ligne.');
    }

    /**
     * Mêmes garde-fous hors ligne pour une décision rejouée au titre du SITE : la zone du site gardée par le poste doit accepter l'entrée hors ligne.
     *
     * @param  list<string>  $zoneIds
     * @return array{allowed: false, reason: string, message: string, zones: list<string>}|null
     */
    public function offlineRefusalSite(object $card, array $zoneIds, string $declaredAt, ?string $knownAt): ?array
    {
        if (($refusal = $this->offlineTrustRefusal($declaredAt, $knownAt)) !== null) {
            return $refusal;
        }
        $allowed = DB::table('holder_site_entitlements as e')->join('site_zone_policies as p', 'p.zone_id', '=', 'e.zone_id')
            ->where('e.holder_id', $card->holder_id)->whereIn('e.zone_id', $zoneIds)->whereNull('e.revoked_at')->where('p.permanent_allowed', true)->where('p.offline_entry_allowed', true)->exists();

        return $allowed ? null : $this->offlineRefuse('hors_ligne_non_autorise', 'Cette zone du site n’accepte pas l’entrée des titulaires permanents hors ligne.');
    }

    /** @return array{allowed: false, reason: string, message: string, zones: list<string>} */
    private function offlineRefuse(string $reason, string $message): array
    {
        return ['allowed' => false, 'reason' => $reason, 'message' => $message, 'zones' => []];
    }

    /** @return array{allowed: false, reason: string, message: string, zones: list<string>}|null */
    private function offlineTrustRefusal(string $declaredAt, ?string $knownAt): ?array
    {
        $trust = $this->offlineTrust()['effective'];
        if ($trust < 1) {
            return $this->offlineRefuse('hors_ligne_non_autorise', 'Entrée hors ligne non autorisée : aucune durée de confiance approuvée par l’institution.');
        }
        try {
            $declared = Carbon::parse($declaredAt);
            $known = $knownAt === null ? null : Carbon::parse($knownAt);
        } catch (\Throwable) {
            return $this->offlineRefuse('confiance_expiree', 'Confiance hors ligne expirée : heure de dernière synchronisation illisible.');
        }
        if ($known === null || $known->greaterThan($declared) || $known->diffInMinutes($declared) > $trust) {
            return $this->offlineRefuse('confiance_expiree', 'Confiance hors ligne expirée : la tablette n’avait pas été synchronisée assez récemment pour autoriser cette entrée.');
        }

        return null;
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

    /**
     * Annuaire en lecture seule pour la recherche à l'accueil : les personnes permanentes actives avec l'état de leur carte.
     * Aucune photo, aucun jeton, aucune date de naissance ou coordonnée : nom, identifiant, organisation, catégorie et état de la carte, rien d'autre.
     * Il ne sert qu'à renseigner l'agent ; il n'ouvre jamais d'accès et n'est jamais lu par la décision d'entrée.
     *
     * @return list<array<string, mixed>>
     */
    private function directory(): array
    {
        $cards = DB::table('holder_cards')->orderByDesc('rank')->get(['holder_id', 'status', 'activated_at'])->groupBy('holder_id');
        $state = function (string $holderId) use ($cards): string {
            $own = $cards[$holderId] ?? collect();
            $active = $own->firstWhere('status', 'active');
            if ($active !== null) {
                return $active->activated_at === null ? 'non_activee' : 'active';
            }

            return $own->isEmpty() ? 'aucune' : (string) $own->first()->status;
        };

        /** @var array<string, array<string, mixed>|null> $categories */
        $categories = [];

        return DB::table('permanent_holders')->where('status', 'actif')->orderByRaw('public.ikoma_normalize(full_name)')->limit(5000)
            ->get(['id', 'identifier', 'full_name', 'organisation', 'institutional_category'])
            ->map(fn ($h) => ['id' => $h->id, 'identifier' => $h->identifier, 'name' => $h->full_name, 'organisation' => $h->organisation,
                'category' => $h->institutional_category === null ? null : ($categories[$h->institutional_category] ??= $this->categoryOf($h->institutional_category)), 'card' => $state($h->id)])->all();
    }

    /**
     * Catégorie affichée au poste de contrôle (nom et couleur du modèle de carte). Informative : elle n'ouvre aucun accès.
     *
     * @return array{id: string, name: string, color: string, priority_circuit: false}|null
     */
    public function categoryOf(?string $code): ?array
    {
        if ($code === null) {
            return null;
        }
        $model = app(CardModels::class)->forCategory($code);

        return ['id' => 'carte-'.$code, 'name' => $model['label'], 'color' => $model['color'], 'priority_circuit' => false];
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
    public function snapshot(?string $eventId, ?string $checkpointId = null): array
    {
        $trust = $this->offlineTrust();
        $iso = fn (?string $v): ?string => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $cards = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->get(['b.qr_token', 'k.status', 'k.activated_at', 'h.id as holder_id', 'h.identifier'])
            // Carte pas encore remise : la tablette la refuse (statut « non_activee »), comme le serveur.
            ->map(fn ($c) => ['hash' => hash('sha256', $c->qr_token), 'status' => $c->status === 'active' && $c->activated_at === null ? 'non_activee' : $c->status, 'holder_id' => $c->holder_id, 'identifier' => $c->identifier])->all();
        $site = $this->siteSnapshot($checkpointId, $iso);
        $holderIds = ($eventId === null ? collect() : DB::table('holder_entitlements')->where('event_id', $eventId)->whereNull('revoked_at')->distinct()->pluck('holder_id'))->merge($site['holder_ids'])->unique()->values();
        $holders = DB::table('permanent_holders')->whereIn('id', $holderIds)->get(['id', 'identifier', 'full_name', 'organisation', 'status', 'engagement_starts_on', 'engagement_ends_on', 'institutional_category']);
        // Sans activité (mode site) : aucune habilitation ni politique d'activité, seulement celles du site.
        $entitlements = $eventId === null ? collect() : DB::table('holder_entitlements')->where('event_id', $eventId)->whereNull('revoked_at')->get(['holder_id', 'zone_id', 'valid_from', 'valid_until'])->groupBy('holder_id');
        $policies = $eventId === null ? [] : DB::table('zone_policies')->where('event_id', $eventId)->get(['zone_id', 'permanent_allowed', 'offline_entry_allowed'])->map(fn ($p) => ['zone_id' => $p->zone_id, 'permanent_allowed' => (bool) $p->permanent_allowed, 'offline_entry_allowed' => (bool) $p->offline_entry_allowed])->all();

        return [
            'directory' => $this->directory(),
            'trust_minutes' => $trust['effective'], 'cards' => $cards, 'policies' => $policies, 'site' => $site['data'],
            'holders' => $holders->map(fn ($h) => ['id' => $h->id, 'category' => $this->categoryOf($h->institutional_category), 'identifier' => $h->identifier, 'name' => $h->full_name, 'organisation' => $h->organisation, 'status' => $h->status, 'starts_on' => $h->engagement_starts_on, 'ends_on' => $h->engagement_ends_on,
                'entitlements' => ($entitlements[$h->id] ?? collect())->map(fn ($e) => ['zone_id' => $e->zone_id, 'valid_from' => $iso($e->valid_from), 'valid_until' => $iso($e->valid_until)])->values()->all()])->all(),
        ];
    }

    /**
     * Copie du SITE pour la tablette (contrôle hors ligne) : zones du site gardées par le poste, leurs politiques et les habilitations du site non retirées.
     * Les titulaires concernés rejoignent la liste `holders` de la copie (sans habilitation d'activité s'ils n'en ont pas).
     *
     * @return array{data: array<string, mixed>|null, holder_ids: list<string>}
     */
    private function siteSnapshot(?string $checkpointId, Closure $iso): array
    {
        $zoneIds = $checkpointId === null ? [] : DB::table('checkpoint_site_zones')->where('checkpoint_id', $checkpointId)->pluck('zone_id')->all();
        if ($zoneIds === []) {
            return ['data' => null, 'holder_ids' => []];
        }
        $zones = DB::table('zones')->whereIn('id', $zoneIds)->orderBy('name')->get(['id', 'name'])->map(fn ($z) => ['id' => $z->id, 'name' => $z->name])->all();
        $policies = DB::table('site_zone_policies')->whereIn('zone_id', $zoneIds)->orderBy('zone_id')->get(['zone_id', 'permanent_allowed', 'offline_entry_allowed'])
            ->map(fn ($p) => ['zone_id' => $p->zone_id, 'permanent_allowed' => (bool) $p->permanent_allowed, 'offline_entry_allowed' => (bool) $p->offline_entry_allowed])->all();
        $rows = DB::table('holder_site_entitlements')->whereIn('zone_id', $zoneIds)->whereNull('revoked_at')->orderBy('holder_id')->orderBy('zone_id')->orderBy('valid_from')->get(['holder_id', 'zone_id', 'valid_from', 'valid_until']);

        return [
            'data' => ['zones' => $zones, 'policies' => $policies, 'entitlements' => $rows->map(fn ($e) => ['holder_id' => $e->holder_id, 'zone_id' => $e->zone_id, 'valid_from' => $iso($e->valid_from), 'valid_until' => $iso($e->valid_until)])->all()],
            'holder_ids' => $rows->pluck('holder_id')->unique()->values()->all(),
        ];
    }
}
