<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cartes institutionnelles des titulaires permanents (A05).
 *
 * Trois notions restent distinctes :
 *  - le TITULAIRE (registre A04) et son identifiant imprimable, immuable et jamais réattribué ;
 *  - la CARTE physique (holder_cards), une seule active par titulaire ;
 *  - les identifiants TECHNIQUES du badge : code B-nnn et jeton QR (contrat IKOMA1: inchangé), jamais imprimés en clair hors du QR et du code de badge.
 *
 * Posséder une carte ne donne AUCUN droit d'accès : le badge reste « Disponible » (non associé) et le contrôle le refuse.
 * Le premier tirage est l'émission ; chaque tirage suivant est une réimpression motivée du MÊME badge, du même QR et du même identifiant.
 */
final class HolderCards
{
    public const SETTINGS = ['carte_verso_ligne1' => 80, 'carte_verso_ligne2' => 80, 'carte_verso_contact' => 80, 'carte_verso_avis' => 160];

    public const DEFAULT_NOTICE = 'Cette carte ne donne pas, à elle seule, droit d’accès.';

    public function __construct(private readonly BadgeLots $lots, private readonly PermanentHolders $holders, private readonly Audit $audit) {}

    // --- Réglages du verso et validation du modèle ----------------------------------------------------------------------------

    /** @return array{ligne1: string, ligne2: string, contact: string, avis: string} */
    public function settings(): array
    {
        $raw = DB::table('settings')->where('key', 'like', 'carte\\_%')->pluck('value', 'key');
        $institution = app(Institution::class)->profile();

        return [
            'ligne1' => trim((string) ($raw['carte_verso_ligne1'] ?? '')) ?: $institution['fullName'],
            'ligne2' => trim((string) ($raw['carte_verso_ligne2'] ?? '')),
            'contact' => trim((string) ($raw['carte_verso_contact'] ?? '')),
            'avis' => trim((string) ($raw['carte_verso_avis'] ?? '')) ?: self::DEFAULT_NOTICE,
        ];
    }

    /** @param array<string, mixed> $data */
    public function saveSettings(array $data, User $actor): void
    {
        $values = [];
        foreach (self::SETTINGS as $key => $max) {
            $value = trim((string) ($data[str_replace('carte_verso_', '', $key)] ?? ''));
            if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
                throw ValidationException::withMessages([str_replace('carte_verso_', '', $key) => 'Texte trop long ou contenant des caractères non acceptés.']);
            }
            $values[$key] = $value;
        }
        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $key => $value) {
                DB::table('settings')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
            }
            // Les textes ne sont pas recopiés dans le journal.
            $this->audit->append('carte.reglages_modifies', $actor->id, ['champs' => array_keys($values)]);
        });
    }

    // --- Portrait ---------------------------------------------------------------------------------------------------------------

    public function savePortrait(string $holderId, ?UploadedFile $photo, bool $remove, bool $printAllowed, User $actor): void
    {
        $jpeg = $photo ? app(ProfilePortrait::class)->clean((string) $photo->getContent()) : null;
        $newPath = null;
        $old = null;
        try {
            DB::transaction(function () use ($holderId, $jpeg, $remove, $printAllowed, $actor, &$newPath, &$old): void {
                $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
                abort_unless($holder !== null, 404);
                if ($holder->status === 'clos') {
                    throw ValidationException::withMessages(['photo' => 'Ce titulaire est clos : sa fiche ne se modifie plus.']);
                }
                $old = $holder->photo_file;
                $path = $old;
                if ($jpeg !== null) {
                    $newPath = 'holder-portraits/'.Str::uuid().'.enc';
                    if (! Storage::disk('local')->put($newPath, Crypt::encryptString($jpeg))) {
                        throw new \RuntimeException('Enregistrement du portrait impossible.');
                    }
                    $path = $newPath;
                } elseif ($remove) {
                    $path = null;
                }
                DB::table('permanent_holders')->where('id', $holderId)->update(['photo_file' => $path, 'photo_print_allowed' => $path !== null && $printAllowed, 'updated_by' => $actor->id, 'updated_at' => now()]);
                $this->audit->append('titulaire.portrait_modifie', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'photo_presente' => $path !== null, 'impression_autorisee' => $path !== null && $printAllowed]);
            });
        } catch (\Throwable $e) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            throw $e;
        }
        if ($old !== null && $old !== $newPath && ($jpeg !== null || $remove) && preg_match('~^holder-portraits/[0-9a-f-]{36}\.enc$~', $old) === 1) {
            Storage::disk('local')->delete($old);
        }
    }

    /** Portrait (JPEG nettoyé) d'une fiche pour son affichage dans l'administration ; null s'il n'y en a pas. Ne sert jamais pour l'impression. */
    public function portraitBytes(string $holderId): ?string
    {
        $path = DB::table('permanent_holders')->where('id', $holderId)->value('photo_file');
        if ($path === null || preg_match('~^holder-portraits/[0-9a-f-]{36}\.enc$~', $path) !== 1) {
            return null;
        }
        $encrypted = Storage::disk('local')->get($path);

        return $encrypted === null ? null : Crypt::decryptString($encrypted);
    }

    private function portraitDataUri(object $holder): ?string
    {
        if ($holder->photo_file === null || ! $holder->photo_print_allowed || preg_match('~^holder-portraits/[0-9a-f-]{36}\.enc$~', $holder->photo_file) !== 1) {
            return null;
        }
        $encrypted = Storage::disk('local')->get($holder->photo_file);

        return $encrypted === null ? null : 'data:image/jpeg;base64,'.base64_encode(Crypt::decryptString($encrypted));
    }

    // --- Carte : état, émission -------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function activeCard(string $holderId): ?array
    {
        $card = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')
            ->where('k.holder_id', $holderId)->where('k.status', 'active')->first(['k.id', 'k.issued_at', 'k.activated_at', 'k.activation_source', 'b.id as badge_id', 'b.code', 'b.status as badge_status']);
        if ($card === null) {
            return null;
        }
        $prints = DB::table('holder_card_prints as p')->leftJoin('users as u', 'u.id', '=', 'p.printed_by')->where('p.card_id', $card->id)
            ->orderByRaw("p.kind = 'emission' DESC")->orderBy('p.created_at')->orderBy('p.id')->get(['p.id', 'p.kind', 'p.reason', 'p.created_at', 'u.name as by'])->all();

        return ['id' => $card->id, 'issued_at' => $card->issued_at, 'activated_at' => $card->activated_at, 'activation_source' => $card->activation_source, 'badge_code' => $card->code, 'badge_status' => $card->badge_status, 'associated' => $card->badge_status === 'Associé', 'prints' => $prints];
    }

    /** Une nouvelle carte exige la photographie du titulaire (jamais pour enregistrer la personne). */
    /**
     * Où en sont les cartes des personnes en cours : sans carte (avec ou sans photo), à imprimer, à remettre, actives.
     * Les compteurs sont des nombres de personnes, rien de nominatif.
     *
     * @return array{sans_carte: int, sans_photo: int, a_imprimer: int, a_remettre: int, actives: int, prochaine_remise: ?string}
     */
    public function pipeline(): array
    {
        $active = DB::table('holder_cards as k')->join('permanent_holders as h', 'h.id', '=', 'k.holder_id')->where('h.status', 'actif')->where('k.status', 'active');
        $printed = fn ($q) => $q->selectRaw('1')->from('holder_card_prints as p')->whereColumn('p.card_id', 'k.id');
        $withoutCard = fn () => DB::table('permanent_holders as h')->where('h.status', 'actif')->whereNotNull('h.institutional_category')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('holder_cards as k')->whereColumn('k.holder_id', 'h.id')->where('k.status', 'active'));
        $next = (clone $active)->whereNull('k.activated_at')->whereExists($printed)->orderBy('k.issued_at')->value('h.id');

        return [
            'sans_carte' => $withoutCard()->whereNotNull('h.photo_file')->count(),
            'sans_photo' => $withoutCard()->whereNull('h.photo_file')->count(),
            'a_imprimer' => (clone $active)->whereNull('k.activated_at')->whereNotExists($printed)->count(),
            'a_remettre' => (clone $active)->whereNull('k.activated_at')->whereExists($printed)->count(),
            'actives' => (clone $active)->whereNotNull('k.activated_at')->count(),
            'prochaine_remise' => $next === null ? null : (string) $next,
        ];
    }

    /**
     * « Tout préparer » : prépare (sans activer) la carte de chaque personne en cours qui a une catégorie et une photo et pas encore de carte.
     * Même règle que la préparation unitaire ; 200 personnes au plus par demande.
     *
     * @return array{preparees: int, restantes: int}
     */
    public function prepareAll(User $actor, int $max = 200): array
    {
        $ids = DB::table('permanent_holders as h')->where('h.status', 'actif')->whereNotNull('h.institutional_category')->whereNotNull('h.photo_file')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('holder_cards as k')->whereColumn('k.holder_id', 'h.id')->where('k.status', 'active'))
            ->orderBy('h.identifier_seq')->limit($max + 1)->pluck('h.id')->all();
        $done = 0;
        foreach (array_slice($ids, 0, $max) as $id) {
            try {
                $this->issue((string) $id, $actor, false);
                $done++;
            } catch (ValidationException) {
                // Une personne qui ne peut pas recevoir de carte (ex. état changé entre-temps) n'arrête pas les autres.
            }
        }

        return ['preparees' => $done, 'restantes' => max(0, count($ids) - $max)];
    }

    public function requirePhoto(string $holderId): void
    {
        $photo = DB::table('permanent_holders')->where('id', $holderId)->value('photo_file');
        if ($photo === null) {
            throw ValidationException::withMessages(['holder' => 'Ajoutez d’abord la photo de cette personne dans le registre : une carte ne se prépare pas sans photo.']);
        }
    }

    /**
     * Remise de la carte au titulaire : elle devient utilisable au contrôle (toujours soumise aux habilitations). Action explicite, une seule fois.
     */
    public function activate(string $holderId, User $actor): void
    {
        DB::transaction(function () use ($holderId, $actor): void {
            $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['holder' => 'Cette personne est clôturée : sa carte ne peut plus être remise.']);
            }
            $card = DB::table('holder_cards')->where('holder_id', $holderId)->where('status', 'active')->lockForUpdate()->first();
            if ($card === null) {
                throw ValidationException::withMessages(['holder' => 'Aucune carte à remettre : préparez d’abord la carte.']);
            }
            if ($card->activated_at !== null) {
                throw ValidationException::withMessages(['holder' => 'Cette carte est déjà remise et active.']);
            }
            DB::table('holder_cards')->where('id', $card->id)->update(['activated_at' => now(), 'activated_by' => $actor->id, 'activation_source' => 'remise', 'updated_at' => now()]);
            $this->audit->append('carte.activee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'carte_rang' => $card->rank]);
        });
    }

    /**
     * Émet la carte d'un titulaire actif : badge du site (sans événement), non associé.
     * $activate = false (parcours de l'administration) : la carte n'ouvre rien tant qu'elle n'a pas été remise et activée ; true (données de démonstration, essais) : carte utilisable.
     *
     * @return string identifiant de la carte
     */
    public function issue(string $holderId, User $actor, bool $activate = true, ?string $operationId = null, string $purpose = 'emission', ?string $replacesBadgeId = null): string
    {
        try {
            return DB::transaction(function () use ($holderId, $actor, $activate, $operationId, $purpose, $replacesBadgeId): string {
                $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
                abort_unless($holder !== null, 404);
                if ($holder->status === 'clos') {
                    throw ValidationException::withMessages(['holder' => 'Ce titulaire est clos : aucune carte ne peut lui être émise.']);
                }
                if ($holder->institutional_category === null) {
                    throw ValidationException::withMessages(['holder' => 'Choisissez d’abord la catégorie institutionnelle du titulaire.']);
                }
                if (DB::table('holder_cards')->where('holder_id', $holderId)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['holder' => 'Ce titulaire a déjà une carte active : réimprimez-la au lieu d’en émettre une nouvelle.']);
                }
                // La carte appartient au site : badge sans événement, sans lot, sans catégorie d'événement et sans participation.
                $badge = $this->lots->issueSiteCard('Carte '.$holder->identifier, $actor);
                $id = (string) Str::uuid();
                DB::table('holder_cards')->insert([
                    'id' => $id, 'holder_id' => $holderId, 'badge_id' => $badge['id'], 'status' => 'active',
                    'issued_by' => $actor->id, 'issued_at' => now(), 'activated_at' => $activate ? now() : null, 'activation_source' => $activate ? 'ancienne' : null, 'rank' => (int) DB::table('holder_cards')->where('holder_id', $holderId)->max('rank') + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                // Toute émission de carte entre dans le registre canonique des émissions (opération identifiée, personne canonique, site).
                IssuanceLedger::record(['operation_id' => $operationId ?? (string) Str::uuid(), 'purpose' => $purpose, 'support_type' => 'carte_permanente', 'person_id' => $holder->person_id, 'holder_id' => $holderId,
                    'event_id' => null, 'badge_id' => $badge['id'], 'replaces_badge_id' => $replacesBadgeId, 'actor_id' => $actor->id]);
                $this->audit->append('carte.emise', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier, 'code_badge' => $badge['code']]);

                return $id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['holder' => 'Ce titulaire a déjà une carte active : réimprimez-la au lieu d’en émettre une nouvelle.']);
            }
            throw $e;
        }
    }

    // --- Rendu ------------------------------------------------------------------------------------------------------------------

    /**
     * Données de présentation d'une carte. Le modèle est celui de la catégorie institutionnelle du titulaire (huit modèles, un par catégorie).
     *
     * @return array<string, mixed>
     */
    public function cardData(string $holderId, bool $withPortrait = true): array
    {
        $holder = DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')
            ->where('h.id', $holderId)->first(['h.*', 'c.label as category_label', 'c.color as category_color', 'c.symbol as category_symbol']);
        abort_unless($holder !== null, 404);
        $card = DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $holderId)->where('k.status', 'active')->first(['k.id', 'k.rank', 'b.code', 'b.qr_token']);
        abort_if($card === null, 404);

        return $this->compose([
            'name' => $holder->full_name, 'role' => implode(' · ', array_filter([$holder->position, $holder->quality])), 'identifier' => $holder->identifier, 'code' => $holder->institutional_category,
            'label' => $holder->category_label, 'color' => $holder->category_color, 'symbol' => $holder->category_symbol,
            'from' => $holder->engagement_starts_on, 'until' => $holder->engagement_ends_on,
            'portrait' => $withPortrait ? $this->portraitDataUri($holder) : null,
            'qr' => app(QrSvg::class)->render(QrSvg::payload($card->qr_token), 'QR de la carte '.$holder->identifier),
            'badge_code' => $card->code, 'card_id' => $card->id, 'rank' => $card->rank, 'specimen' => false,
        ]);
    }

    /**
     * Données de présentation du badge TEMPORAIRE nominatif d'une participation : MÊME composeur et MÊME modèle institutionnel (CardModels) que les cartes permanentes.
     * Le modèle est celui de la catégorie institutionnelle liée à la catégorie de participation (categories.institutional_code) ; sans lien, modèle neutre et mention provisoire.
     * Le portrait n'est lu que si l'impression en est autorisée (refus respecté).
     *
     * @return array<string, mixed>
     */
    public function temporaryData(string $badgeId, bool $withPortrait = true): array
    {
        $row = DB::table('badges as b')->join('visitors as v', 'v.id', '=', 'b.printed_for_visitor_id')->join('categories as c', 'c.id', '=', 'v.category_id')
            ->leftJoin('institutional_categories as ic', 'ic.code', '=', 'c.institutional_code')->where('b.id', $badgeId)
            ->first(['b.code as badge_code', 'b.qr_token', 'v.id as visitor_id', 'v.full_name', 'v.organisation', 'v.position', 'v.valid_from', 'v.valid_until', 'c.name as event_category',
                'c.color as event_color', 'ic.code as inst_code', 'ic.label as inst_label', 'ic.color as inst_color', 'ic.symbol as inst_symbol']);
        abort_unless($row !== null, 404);
        $portrait = null;
        if ($withPortrait) {
            $visitor = Visitor::find($row->visitor_id);
            $bytes = $visitor === null ? null : app(ProfilePortrait::class)->read($visitor, true);
            $portrait = $bytes === null ? null : 'data:image/jpeg;base64,'.base64_encode($bytes);
        }

        return $this->compose([
            'name' => $row->full_name, 'role' => implode(' · ', array_filter([$row->position, $row->organisation])), 'identifier' => $row->badge_code, 'code' => $row->inst_code,
            'label' => $row->inst_label ?? $row->event_category, 'color' => $row->inst_color ?? $row->event_color, 'symbol' => $row->inst_symbol,
            'from' => $row->valid_from, 'until' => $row->valid_until, 'portrait' => $portrait,
            'qr' => app(QrSvg::class)->render(QrSvg::payload($row->qr_token), 'QR du badge '.$row->badge_code),
            'badge_code' => $row->badge_code, 'card_id' => null, 'rank' => 1, 'specimen' => false,
        ]);
    }

    /**
     * Modèle d'exemple pour une catégorie : données fictives, QR qui ne correspond à aucun badge.
     * $override : valeurs du modèle pas encore enregistrées (aperçu en direct de la page de personnalisation).
     *
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    public function specimen(object $category, ?array $override = null): array
    {
        $format = $this->holders->formatIdentifier(0);

        return $this->compose([
            'name' => 'Prénom NOM (exemple)', 'role' => 'Fonction · Qualité (exemple)', 'identifier' => $format, 'code' => $category->code, 'label' => $category->label, 'color' => $category->color, 'symbol' => $category->symbol,
            'from' => now()->startOfYear()->toDateString(), 'until' => now()->endOfYear()->toDateString(),
            'portrait' => null, 'qr' => app(QrSvg::class)->render(QrSvg::payload(str_repeat('0', 64)), 'QR d’exemple, sans valeur'), 'badge_code' => null, 'card_id' => null, 'rank' => 1, 'specimen' => true,
            'model_override' => $override,
        ]);
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function compose(array $card): array
    {
        $institution = app(Institution::class)->profile();
        $logo = null;
        if ($institution['logoUrl'] !== null && is_file(app(Institution::class)->logoPath())) {
            $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(app(Institution::class)->logoPath()));
        }
        $code = $card['code'] ?? null;
        $model = $code === null
            ? ['color' => InstitutionalCategories::NEUTRAL_COLOR, 'mention' => mb_strtoupper((string) ($card['label'] ?? 'Titulaire permanent')), 'show_validity' => true, 'qr_side' => 'verso', 'version' => 1, 'validated' => false]
            : app(CardModels::class)->forCategory((string) $code);
        foreach ((array) ($card['model_override'] ?? []) as $key => $value) {
            if ($key === 'color' ? $value !== null : true) {
                $model[$key] = $value;
            }
        }
        $color = Institution::validColor((string) $model['color']) ? strtoupper((string) $model['color']) : InstitutionalCategories::NEUTRAL_COLOR;
        $settings = $this->settings();
        $from = ($card['from'] ?? null) ? date('d/m/Y', (int) strtotime((string) $card['from'])) : null;
        $until = ($card['until'] ?? null) ? date('d/m/Y', (int) strtotime((string) $card['until'])) : null;
        $validity = ! $model['show_validity'] || ($from === null && $until === null) ? null : ($from !== null && $until !== null ? 'Valable du '.$from.' au '.$until : ($until !== null ? 'Valable jusqu’au '.$until : 'Valable depuis le '.$from));

        return $card + [
            'institution' => ['name' => $institution['name'], 'fullName' => $institution['fullName'], 'logo' => $logo],
            'tone' => ['color' => $color, 'ink' => self::inkFor($color)],
            'verso' => $settings, 'mention' => (string) $model['mention'], 'validity' => $validity, 'qrSide' => (string) $model['qr_side'], 'modelVersion' => (int) $model['version'],
            'provisional' => ! $model['validated'],
            'nameClass' => mb_strlen((string) $card['name']) > 38 ? 'long' : (mb_strlen((string) $card['name']) > 24 ? 'medium' : ''),
        ];
    }

    /** Couleur de texte (blanc ou noir) la plus lisible sur le fond donné (contraste WCAG). */
    public static function inkFor(string $hex): string
    {
        $channel = fn (int $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $channel(hexdec(substr($hex, 1, 2))) + 0.7152 * $channel(hexdec(substr($hex, 3, 2))) + 0.0722 * $channel(hexdec(substr($hex, 5, 2)));

        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#FFFFFF' : '#000000';
    }

    // --- Tirages ----------------------------------------------------------------------------------------------------------------

    /**
     * Enregistre un tirage : émission au premier, réimpression motivée ensuite. Le badge, son QR et l'identifiant du titulaire ne changent jamais.
     *
     * @return 'emission'|'reimpression'
     */
    public function recordPrint(string $holderId, ?string $reason, User $actor): string
    {
        $reason = $reason === null ? null : trim($reason);

        return DB::transaction(function () use ($holderId, $reason, $actor): string {
            $holder = DB::table('permanent_holders')->where('id', $holderId)->lockForUpdate()->first();
            abort_unless($holder !== null, 404);
            if ($holder->status === 'clos') {
                throw ValidationException::withMessages(['holder' => 'Ce titulaire est clos : sa carte ne s’imprime plus.']);
            }
            $card = DB::table('holder_cards')->where('holder_id', $holderId)->where('status', 'active')->lockForUpdate()->first();
            abort_if($card === null, 404);
            $first = ! DB::table('holder_card_prints')->where('card_id', $card->id)->exists();
            if (! $first && ($reason === null || $reason === '' || mb_strlen($reason) > 200 || preg_match('/[\x00-\x1F\x7F<>]/u', $reason) === 1)) {
                throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la réimpression (200 caractères au plus).']);
            }
            $version = $holder->institutional_category === null ? null : app(CardModels::class)->forCategory($holder->institutional_category)['version'];
            DB::table('holder_card_prints')->insert(['id' => (string) Str::uuid(), 'card_id' => $card->id, 'kind' => $first ? 'emission' : 'reimpression', 'reason' => $first ? null : $reason, 'printed_by' => $actor->id, 'model_version' => $version, 'created_at' => now()]);
            // Le motif peut contenir des informations personnelles : il reste dans l'historique des tirages, pas dans le journal d'audit.
            $this->audit->append($first ? 'carte.imprimee' : 'carte.reimprimee', $actor->id, ['holder_id' => $holderId, 'identifiant' => $holder->identifier]);
            // Le QR est exposé sur un support imprimable : le lot du badge est marqué comme exposé (même protection que l'atelier de badges).
            // Les cartes du site n'ont pas de lot ; les anciennes cartes du registre gardent leur lot d'un exemplaire.
            if (($batchId = DB::table('badges')->where('id', $card->badge_id)->value('badge_batch_id')) !== null) {
                DB::table('badge_batches')->where('id', $batchId)->update(['print_exposed_at' => now()]);
            }

            return $first ? 'emission' : 'reimpression';
        });
    }

    public function recordPreview(string $holderId, User $actor): void
    {
        $identifier = DB::table('permanent_holders')->where('id', $holderId)->value('identifier');
        $this->audit->append('carte.apercu', $actor->id, ['holder_id' => $holderId, 'identifiant' => $identifier]);
    }
}
