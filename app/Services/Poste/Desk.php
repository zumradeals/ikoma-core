<?php

namespace App\Services\Poste;

use App\Models\Device;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Alerts;
use App\Services\Audit;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use App\Services\HostNotifications;
use App\Services\PrivatePhoto;
use App\Services\QrSvg;
use App\Services\VisitorSearch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Règles métier du poste d'accueil (document de fonctionnement, sections 6 et 7). Chaque méthode est appelée dans l'opération
 * idempotente de la route ; les permissions sont contrôlées par les routes, l'événement est toujours celui du poste.
 */
final class Desk
{
    public const PHOTO_MAX_BYTES = 1_000_000;

    public const DOCUMENT_MAX_BYTES = 1_500_000;

    public const DOCUMENT_MAX_SIDE = 1280;

    /** Types de pièce acceptés (libellés dans resources/js/poste/types.ts). */
    public const DOCUMENT_TYPES = ['cni', 'passeport', 'permis', 'carte_militaire', 'carte_professionnelle', 'autre'];

    public const PHOTO_MAX_SIDE = 640;

    public function __construct(private readonly Audit $audit, private readonly BadgeService $badges, private readonly VisitorSearch $search) {}

    /** @return array<string, mixed> */
    public function context(Device $poste): array
    {
        return [
            'poste' => ['id' => $poste->id, 'name' => $poste->name, 'station' => $poste->station, 'event_id' => $poste->event_id],
            'event' => DB::table('events')->where('id', $poste->event_id)->first(['id', 'name', 'starts_at', 'ends_at']),
            'hosts' => DB::table('hosts')->where('event_id', $poste->event_id)->orderBy('name')->get(['id', 'name', 'service']),
            'categories' => DB::table('categories')->where('event_id', $poste->event_id)->orderBy('name')->get(['id', 'name', 'color', 'priority_circuit']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function search(Device $poste, string $query): array
    {
        return $this->search->searchDetailed($poste->event_id, $query);
    }

    /** @return array<string, mixed> */
    public function fiche(Device $poste, string $visitorId): array
    {
        $visitor = $this->visitor($poste, $visitorId);
        $category = DB::table('categories')->where('id', $visitor->category_id)->first(['id', 'name', 'color', 'priority_circuit']);
        $unlisted = $visitor->source === 'non listé';
        $zones = $unlisted
            ? DB::table('zones')->where('event_id', $visitor->event_id)->where('est_accueil', true)->orderBy('name')->pluck('name')
            : DB::table('category_zone as cz')->join('zones as z', 'z.id', '=', 'cz.zone_id')->where('cz.category_id', $visitor->category_id)->orderBy('z.name')->pluck('z.name');
        $badge = DB::table('badge_assignments as a')->join('badges as b', 'b.id', '=', 'a.badge_id')->where('a.visitor_id', $visitor->id)->whereNull('a.returned_at')->whereNull('a.cancelled_at')->value('b.code');

        return [
            'id' => $visitor->id, 'full_name' => $visitor->full_name, 'organisation' => $visitor->organisation,
            'host' => DB::table('hosts')->where('id', $visitor->host_id)->value('name'),
            'category' => ['id' => $category->id, 'name' => $category->name, 'color' => $category->color, 'priority_circuit' => (bool) $category->priority_circuit],
            'zones' => $zones->all(), 'accueil_limite' => $unlisted, 'source' => $visitor->source, 'status' => $visitor->status ?? 'attendu', 'host_validation' => $visitor->host_validation,
            'valid_from' => Carbon::parse($visitor->valid_from)->toIso8601String(), 'valid_until' => Carbon::parse($visitor->valid_until)->toIso8601String(), 'hors_periode' => $this->outsidePeriod($visitor),
            'identity_done' => DB::table('identity_checks')->where('visitor_id', $visitor->id)->exists(), 'photo_done' => DB::table('photos')->where('visitor_id', $visitor->id)->exists(),
            'validated' => $this->validation($visitor) !== null, 'badge_code' => $badge, 'access_note' => $visitor->access_note,
            'identity_document' => self::documentRequired(), 'host_advice' => app(HostNotifications::class)->advice($visitor->id),
            'a_rebadger' => $badge === null && DB::table('badge_assignments')->where('visitor_id', $visitor->id)->whereNotNull('cancelled_at')->exists() && ! in_array($visitor->status, ['sorti', 'refuse'], true),
        ];
    }

    /**
     * Contrôle d'identité (case cochée) et photo, enregistrés ensemble : identity_checks est en ajout seul, la photo est chiffrée.
     *
     * @return array<string, mixed>
     */
    public function saveIdentity(Device $poste, User $agent, string $visitorId, bool $identityChecked, string $jpeg, ?string $documentType = null, ?string $documentNumber = null, ?string $documentJpeg = null): array
    {
        $visitor = $this->openVisitor($poste, $visitorId);
        if (! $identityChecked) {
            throw new DeskRefused('identite_non_cochee', 'Cochez « pièce d’identité contrôlée » après avoir vérifié la pièce.');
        }
        // Option de l'administrateur (désactivée par défaut) : type de pièce et photo de la pièce obligatoires, numéro facultatif.
        $documentFile = null;
        $number = null;
        if (self::documentRequired()) {
            $docInfo = $documentJpeg !== null && strlen($documentJpeg) <= self::DOCUMENT_MAX_BYTES ? @getimagesizefromstring($documentJpeg) : false;
            if (! in_array($documentType, self::DOCUMENT_TYPES, true) || $docInfo === false || $docInfo[2] !== IMAGETYPE_JPEG || $docInfo[0] > self::DOCUMENT_MAX_SIDE || $docInfo[1] > self::DOCUMENT_MAX_SIDE) {
                throw new DeskRefused('piece_requise', 'Indiquez le type de la pièce d’identité et photographiez-la (JPEG, 1 280 pixels au plus).');
            }
            $documentJpeg = app(PrivatePhoto::class)->clean((string) $documentJpeg);
            $documentFile = 'identity-documents/'.Str::uuid().'.enc';
            $number = strtoupper(preg_replace('/[\s.]+/', '', (string) $documentNumber) ?? '');
            if ($number !== '' && preg_match('/^[A-Z0-9\/-]{4,30}$/', $number) !== 1) {
                throw new DeskRefused('piece_numero_invalide', 'Le numéro de la pièce n’est pas valable : 4 à 30 lettres ou chiffres, ou laissez-le vide.');
            }
        } else {
            $documentType = null;
        }
        $info = strlen($jpeg) <= self::PHOTO_MAX_BYTES ? @getimagesizefromstring($jpeg) : false;
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] > self::PHOTO_MAX_SIDE || $info[1] > self::PHOTO_MAX_SIDE || $info[0] < 1) {
            throw new DeskRefused('photo_invalide', 'La photo n’est pas acceptée : reprenez-la (JPEG, 640 pixels au plus).');
        }
        $jpeg = app(PrivatePhoto::class)->clean($jpeg);
        $check = (string) Str::uuid();
        $photo = (string) Str::uuid();
        $path = "photos/{$photo}.enc";
        $now = now();
        DB::table('identity_checks')->insert(['id' => $check, 'visitor_id' => $visitor->id, 'actor_id' => $agent->id, 'checked_at' => $now, 'document_type' => $documentType, 'document_number' => $number ? Crypt::encryptString($number) : null, 'document_file' => $documentFile, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('photos')->insert(['id' => $photo, 'visitor_id' => $visitor->id, 'actor_id' => $agent->id, 'file_reference' => $path, 'taken_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        Storage::disk('local')->put($path, Crypt::encryptString($jpeg)); // jamais en clair sur le disque, jamais servi par une adresse
        if ($documentFile !== null) {
            Storage::disk('local')->put($documentFile, Crypt::encryptString((string) $documentJpeg));
        }
        $this->audit->append('visiteur.identite_verifiee', $agent->id, ['visitor_id' => $visitor->id, 'identity_check_id' => $check, 'photo_id' => $photo, 'device_id' => $poste->id, 'document_type' => $documentType]);

        return ['identity_done' => true, 'photo_done' => true];
    }

    /** Option « photographier la pièce d'identité » (réglage de l'administrateur, désactivée par défaut). */
    public static function documentRequired(): bool
    {
        return DB::table('settings')->where('key', 'identite_piece_active')->value('value') === '1';
    }

    /** @return array<string, mixed> */
    public function validate(Device $poste, User $agent, string $visitorId, ?string $avis, ?string $declaredAt = null): array
    {
        $visitor = $this->openVisitor($poste, $visitorId, true);
        if ($visitor->source === 'non listé' && $visitor->host_validation !== 'accepte') {
            throw new DeskRefused('hote_en_attente', $visitor->host_validation === 'refuse' ? 'L’hôte a refusé cette visite : l’entrée est impossible.' : 'En attente de l’accord de l’hôte : la validation et l’entrée sont impossibles pour le moment.', 422, 'Attendez la réponse de l’hôte ou appelez-le.');
        }
        if (! DB::table('identity_checks')->where('visitor_id', $visitor->id)->exists() || ! DB::table('photos')->where('visitor_id', $visitor->id)->exists()) {
            throw new DeskRefused('identite_requise', 'La pièce d’identité et la photo sont obligatoires avant de valider.');
        }
        $outside = $this->outsidePeriod($visitor);
        $hostAdvice = app(HostNotifications::class)->advice($visitor->id)['state'];
        if ($outside && $hostAdvice === 'refuse' && $avis !== 'superviseur') {
            throw new DeskRefused('avis_hote_refuse', 'L’hôte n’autorise pas cette visite hors période. Appelez le superviseur.');
        }
        if ($outside && $hostAdvice === 'autorise') {
            $avis ??= 'hote';
        }
        if ($outside && ! in_array($avis, ['hote', 'superviseur'], true)) {
            throw new DeskRefused('hors_periode', "Visiteur hors période : avis de l'hôte ou du superviseur requis", 422, 'Demandez l’avis de l’hôte ou du superviseur, puis indiquez-le.');
        }
        if ($this->validation($visitor) === null) {
            $this->insertEvent($poste, $agent, $visitor, 'validation', null, ['hors_periode' => $outside, 'avis' => $outside ? $avis : null, ...($declaredAt !== null ? ['heure_declaree' => $declaredAt] : [])]);
            $this->audit->append('visiteur.valide', $agent->id, ['visitor_id' => $visitor->id, 'device_id' => $poste->id, 'hors_periode' => $outside, 'avis' => $outside ? $avis : null]);
            if ($outside) {
                app(Alerts::class)->raise($visitor->event_id, 'hors_periode', 'normale', 'visiteur', $visitor->id, 'Visiteur validé hors de sa période, sur avis '.($avis === 'hote' ? 'de l’hôte' : 'du superviseur').'.', 'hors_periode:'.$visitor->id);
            }
        }

        return ['validated' => true, 'hors_periode' => $outside];
    }

    /** @return array<string, mixed> */
    public function refuse(Device $poste, User $agent, string $visitorId, string $motif): array
    {
        $visitor = $this->openVisitor($poste, $visitorId, true);
        $motif = trim($motif);
        if ($motif === '') {
            throw new DeskRefused('motif_obligatoire', 'Le motif du refus est obligatoire.');
        }
        if (DB::table('badge_assignments')->where('visitor_id', $visitor->id)->whereNull('returned_at')->whereNull('cancelled_at')->exists()) {
            throw new DeskRefused('badge_deja_remis', 'Un badge est déjà associé à ce visiteur : le refus doit passer par le superviseur.');
        }
        $id = (string) Str::uuid();
        $now = now();
        DB::table('refusals')->insert(['id' => $id, 'visitor_id' => $visitor->id, 'actor_id' => $agent->id, 'reason' => mb_substr($motif, 0, 500), 'refused_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('visitors')->where('id', $visitor->id)->update(['status' => 'refuse', 'updated_at' => $now]);
        $this->audit->append('visiteur.refuse', $agent->id, ['visitor_id' => $visitor->id, 'refusal_id' => $id, 'device_id' => $poste->id]);
        app(Alerts::class)->raise($visitor->event_id, 'refus', 'haute', 'visiteur', $visitor->id, 'Accès refusé à un visiteur : décision du superviseur attendue.', 'refus:'.$id);

        return ['refused' => true];
    }

    /**
     * Association du badge : scan du QR (« IKOMA1:… ») ou code imprimé. Passe uniquement par BadgeService.
     *
     * @return array<string, mixed>
     */
    public function assignBadge(Device $poste, User $agent, string $visitorId, string $code): array
    {
        $visitor = $this->openVisitor($poste, $visitorId, true);
        $validation = $this->validation($visitor) ?? throw new DeskRefused('validation_requise', 'Validez d’abord le visiteur avant de lui remettre un badge.');
        $input = trim($code);
        if (str_starts_with($input, QrSvg::PREFIX)) {
            $input = substr($input, strlen(QrSvg::PREFIX));
        }
        $query = DB::table('badges')->select('id', 'code');
        if (preg_match('/^[0-9a-f]{64}$/i', $input) === 1) {
            $query->where('qr_token', strtolower($input));
        } else {
            $query->where('event_id', $poste->event_id)->whereRaw('upper(code) = ?', [mb_strtoupper($input)]);
        }
        $badge = $query->first() ?? throw new DeskRefused('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 422, 'Saisissez le code imprimé ou prenez un autre badge.');
        $held = DB::table('badge_assignments')->where('visitor_id', $visitor->id)->whereNull('returned_at')->whereNull('cancelled_at')->value('badge_id');
        if ($held === $badge->id) {
            return ['badge_code' => $badge->code];
        }
        $avis = json_decode((string) $validation->details, true)['avis'] ?? null;
        try {
            $this->badges->associer($badge->id, $visitor->id, $agent, $poste, $avis);
        } catch (BadgeRefused $e) {
            throw new DeskRefused($e->reason, $e->getMessage(), 422, $this->nextAction($e->reason, $visitor));
        }

        return ['badge_code' => $badge->code];
    }

    /**
     * Entrée : le visiteur est présent, le compteur de présents (lot L7) s'appuiera sur ce statut.
     *
     * @return array<string, mixed>
     */
    public function enter(Device $poste, User $agent, string $visitorId, int $durationMs, bool $strict = false, ?string $declaredAt = null): array
    {
        $visitor = $this->openVisitor($poste, $visitorId, true, true);
        $badgeId = DB::table('badge_assignments')->where('visitor_id', $visitor->id)->whereNull('returned_at')->whereNull('cancelled_at')->value('badge_id');
        if ($this->validation($visitor) === null || $badgeId === null) {
            throw new DeskRefused('badge_requis', 'Le visiteur doit être validé et avoir un badge avant d’entrer.');
        }
        if ($visitor->status === 'present' && $strict) {
            throw new DeskRefused('deja_present', 'Ce visiteur est déjà entré : l’entrée enregistrée ici est en conflit.', 409);
        }
        if ($visitor->status !== 'present') {
            $this->insertEvent($poste, $agent, $visitor, 'entree', $badgeId, ['duree_ms' => max(0, min($durationMs, 86_400_000)), ...($declaredAt !== null ? ['heure_declaree' => $declaredAt] : [])]);
            DB::table('visitors')->where('id', $visitor->id)->update(['status' => 'present', 'updated_at' => now()]);
            app(HostNotifications::class)->send($visitor->id, 'arrivee', $agent, $poste);
            $this->audit->append('visiteur.entree', $agent->id, ['visitor_id' => $visitor->id, 'badge_id' => $badgeId, 'device_id' => $poste->id, 'duree_ms' => max(0, min($durationMs, 86_400_000))]);
        }

        return ['entered' => true, 'zones' => $this->fiche($poste, $visitor->id)['zones']];
    }

    /**
     * Fiche rapide d'un visiteur non listé : l'accès reste limité à la zone d'accueil tant que l'hôte n'a pas accepté (et après, avec accompagnement).
     *
     * @return array<string, mixed>
     */
    public function createUnlisted(Device $poste, User $agent, string $name, ?string $organisation, string $hostId, string $categoryId, ?string $id = null): array
    {
        $name = trim($name);
        if ($name === '' || ! DB::table('hosts')->where('id', $hostId)->where('event_id', $poste->event_id)->exists() || ! DB::table('categories')->where('id', $categoryId)->where('event_id', $poste->event_id)->exists()) {
            throw new DeskRefused('fiche_invalide', 'Fiche incomplète : indiquez le nom, la personne à voir et la catégorie.');
        }
        $event = DB::table('events')->where('id', $poste->event_id)->first(['starts_at', 'ends_at']);
        $now = now();
        $id ??= (string) Str::uuid();
        if (DB::table('visitors')->where('id', $id)->exists()) {
            throw new DeskRefused('fiche_existante', 'Cette fiche existe déjà.', 409);
        }
        DB::table('visitors')->insert(['id' => $id, 'event_id' => $poste->event_id, 'full_name' => mb_substr($name, 0, 160), 'organisation' => $organisation !== null && trim($organisation) !== '' ? mb_substr(trim($organisation), 0, 160) : null, 'host_id' => $hostId, 'category_id' => $categoryId, 'valid_from' => min($now, new \DateTimeImmutable($event->starts_at)), 'valid_until' => $event->ends_at, 'source' => 'non listé', 'status' => 'attendu', 'host_validation' => 'en_attente', 'created_at' => $now, 'updated_at' => $now]);
        app(HostNotifications::class)->send($id, 'non_liste', $agent, $poste);
        $this->audit->append('visiteur.non_liste_cree', $agent->id, ['visitor_id' => $id, 'event_id' => $poste->event_id, 'host_id' => $hostId, 'device_id' => $poste->id]);
        app(Alerts::class)->raise($poste->event_id, 'non_liste_attente', 'normale', 'visiteur', $id, 'Visiteur non listé en attente de l’accord de l’hôte.', 'non_liste:'.$id);

        return ['visitor_id' => $id];
    }

    /** Décision de l'hôte sur un visiteur non listé. L'écran de l'hôte et les notifications viennent au lot L10. */
    public function decideHost(string $visitorId, bool $accepted, ?User $actor = null): void
    {
        DB::transaction(function () use ($visitorId, $accepted, $actor): void {
            $visitor = Visitor::query()->lockForUpdate()->find($visitorId) ?? throw new DeskRefused('visiteur_inconnu', 'Visiteur introuvable.', 404);
            if ($visitor->source !== 'non listé' || $visitor->host_validation !== 'en_attente') {
                throw new DeskRefused('decision_impossible', 'Aucune décision de l’hôte n’est attendue pour ce visiteur.', 409);
            }
            DB::table('visitors')->where('id', $visitor->id)->update(['host_validation' => $accepted ? 'accepte' : 'refuse', 'updated_at' => now()]);
            $this->audit->append('visiteur.hote_decision', $actor?->id, ['visitor_id' => $visitor->id, 'decision' => $accepted ? 'accepte' : 'refuse']);
            app(Alerts::class)->resolve('visiteur', $visitor->id, 'non_liste_attente');
        });
    }

    private function nextAction(string $reason, Visitor $visitor): string
    {
        $category = DB::table('categories')->where('id', $visitor->category_id)->value('name');

        return match ($reason) {
            'deja_associe', 'annule', 'indisponible' => 'Prenez un autre badge.',
            'categorie' => "Prenez un badge de catégorie {$category}.",
            'autre_poste' => 'Prenez un badge réservé à ce poste ou non réservé.',
            'sans_categorie' => 'Prenez un badge d’un lot par catégorie.',
            'visiteur_deja_badge' => 'Ce visiteur a déjà son badge : passez à la remise.',
            'autre_evenement', 'poste_inconnu' => 'Prenez un badge de cet événement.',
            default => 'Prenez un autre badge ou appelez le superviseur.',
        };
    }

    private function visitor(Device $poste, string $id, bool $lock = false): Visitor
    {
        $query = Visitor::query()->where('event_id', $poste->event_id)->whereNull('withdrawn_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($id) ?? throw new DeskRefused('visiteur_inconnu', 'Visiteur introuvable pour ce poste.', 404);
    }

    /** Visiteur encore traitable (ni refusé ni déjà présent), sauf $allowPresent pour rejouer une entrée. */
    private function openVisitor(Device $poste, string $id, bool $lock = false, bool $allowPresent = false): Visitor
    {
        $visitor = $this->visitor($poste, $id, $lock);
        if ($visitor->withdrawn_at !== null) {
            throw new DeskRefused('invite_retire', 'Cet invité a été retiré de la liste.');
        }
        if ($visitor->status === 'refuse') {
            throw new DeskRefused('visiteur_refuse', 'L’accès a été refusé à ce visiteur.');
        }
        if (in_array($visitor->status, ['present', 'sorti'], true) && ! $allowPresent) {
            throw new DeskRefused('deja_present', 'Ce visiteur est déjà entré.');
        }

        return $visitor;
    }

    private function outsidePeriod(Visitor $visitor): bool
    {
        $now = now();

        return $now->lt($visitor->valid_from) || $now->gt($visitor->valid_until);
    }

    private function validation(Visitor $visitor): ?object
    {
        return DB::table('access_events')->where('visitor_id', $visitor->id)->where('type', 'validation')->orderByDesc('occurred_at')->first();
    }

    /** @param array<string, mixed> $details */
    private function insertEvent(Device $poste, User $agent, Visitor $visitor, string $type, ?string $badgeId, array $details): void
    {
        $now = now();
        DB::table('access_events')->insert(['id' => (string) Str::uuid(), 'event_id' => $visitor->event_id, 'visitor_id' => $visitor->id, 'badge_id' => $badgeId, 'actor_id' => $agent->id, 'type' => $type, 'checkpoint' => $poste->station ?? $poste->name, 'occurred_at' => $now, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
    }
}
