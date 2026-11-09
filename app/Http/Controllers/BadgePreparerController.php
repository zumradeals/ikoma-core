<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BadgeIssuance;
use App\Services\BadgeRefused;
use App\Services\CardModels;
use App\Services\SupportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Parcours unique « Préparer un badge » : choisir des personnes (identités canoniques), vérifier, générer, puis imprimer (SupportPrintController).
 * Ce contrôleur ne porte aucune règle : éligibilité, idempotence et génération sont celles de BadgeIssuance ; l'impression est celle de SupportPrinting.
 */
final class BadgePreparerController
{
    private function authorize(): void
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    public function page(Request $request): Response|RedirectResponse
    {
        $this->authorize();

        // Ancienne adresse du parcours : même espace de travail (registre, préparation, aperçu), présélection d'une fiche d'activité comprise.
        return app(BadgesController::class)($request, app(SupportRegistry::class), $this);
    }

    /**
     * Données de la colonne « Préparer un badge ». Présélection venant d'une fiche d'activité : seuls des identifiants valides sont retenus ; l'éligibilité reste vérifiée côté serveur à chaque étape.
     *
     * @return array<string, mixed>
     */
    public function workspaceProps(Request $request): array
    {
        // Le registre est ouvert à qui gère les badges ; la préparation exige en plus l'import des listes (le serveur le refuse de toute façon sur chaque appel).
        $can = Gate::allows('listes.importer');
        $input = $request->validate(['activite' => 'nullable|uuid', 'ids' => 'nullable|string|max:4000']);
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) ($input['ids'] ?? ''))), fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1));
        $preset = ! empty($input['activite']) && DB::table('events')->where('id', $input['activite'])->where('is_permanent', false)->exists() ? ['event' => $input['activite'], 'ids' => array_slice($ids, 0, BadgeIssuance::MAX_PER_CALL)] : null;

        return [
            'canPrepare' => $can,
            'events' => ! $can ? [] : DB::table('events')->where('is_permanent', false)->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['clos', 'archive']))->whereNull('anonymized_at')->orderByDesc('starts_at')->get(['id', 'name', 'kind', 'starts_at', 'ends_at']),
            'max' => BadgeIssuance::MAX_PER_CALL,
            'preset' => $can ? $preset : null,
        ];
    }

    /**
     * Personnes sélectionnables (participants d'une activité, ou titulaires permanents) avec leur situation : à générer, support déjà existant, non éligible (raison).
     */
    public function people(Request $request, BadgeIssuance $issuance): JsonResponse
    {
        $this->authorize();
        $data = $request->validate(['support' => 'required|in:badge_temporaire,carte_permanente', 'event' => 'nullable|uuid', 'q' => 'nullable|string|max:160']);
        $term = trim((string) ($data['q'] ?? ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
        if ($data['support'] === BadgeIssuance::TEMPORARY) {
            if (empty($data['event'])) {
                throw ValidationException::withMessages(['event' => 'Choisissez une activité.']);
            }
            $rows = DB::table('visitors as v')->join('categories as c', 'c.id', '=', 'v.category_id')->where('v.event_id', $data['event'])->whereNull('v.holder_id')->whereNull('v.withdrawn_at')
                ->when($term !== '', fn ($q) => $q->whereRaw('lower(v.full_name) LIKE ?', [$like]))->orderByRaw('v.full_name COLLATE "C" ASC')->orderBy('v.id')->limit(100)
                ->get(['v.id', 'v.full_name', 'v.organisation', 'c.name as category', 'c.institutional_code as inst_code']);
            $ids = [];
            foreach ($rows as $r) {
                $ids[] = (string) $r->id;
            }
            $plan = $issuance->planTemporary($ids);
        } else {
            $rows = DB::table('permanent_holders as h')->leftJoin('institutional_categories as c', 'c.code', '=', 'h.institutional_category')->where('h.status', 'actif')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(h.full_name) LIKE ?', [$like])->orWhereRaw('lower(h.identifier) LIKE ?', [$like])))->orderBy('h.full_name')->limit(100)
                ->get(['h.id', 'h.full_name', DB::raw('NULL as organisation'), 'c.label as category', 'h.institutional_category as inst_code']);
            $ids = [];
            foreach ($rows as $r) {
                $ids[] = (string) $r->id;
            }
            $plan = $issuance->planCards($ids);
        }
        $existing = array_column($plan['existants'], 'code', 'id');
        $refused = [];
        foreach ($plan['non_eligibles'] as $r) {
            $refused[$r['id']] = $r;
        }
        $people = [];
        $models = [];
        foreach ($rows as $r) {
            $state = isset($existing[$r->id]) ? 'existant' : (isset($refused[$r->id]) ? 'non_eligible' : 'a_generer');
            // Modèle institutionnel de la catégorie de la personne (référentiel unique CardModels) : indicatif avant génération ; sans catégorie institutionnelle, modèle neutre provisoire.
            $code = $r->inst_code === null ? null : (string) $r->inst_code;
            if ($code !== null && ! isset($models[$code])) {
                $m = app(CardModels::class)->forCategory($code);
                $models[$code] = ['code' => $code, 'label' => $m['label'], 'color' => $m['color'], 'mention' => $m['mention'], 'version' => $m['version'], 'validated' => $m['validated']];
            }
            $people[] = ['id' => $r->id, 'name' => $r->full_name, 'organisation' => $r->organisation, 'category' => $r->category, 'state' => $state, 'code' => $existing[$r->id] ?? null,
                'reason' => $refused[$r->id]['message'] ?? null, 'model' => $code === null ? null : $models[$code]];
        }

        return response()->json(['people' => $people])->header('Cache-Control', 'no-store, private');
    }

    /** Récapitulatif avant génération (lecture seule). */
    public function summary(Request $request, BadgeIssuance $issuance): JsonResponse
    {
        $this->authorize();
        $data = $request->validate(['support' => 'required|in:badge_temporaire,carte_permanente', 'ids' => 'required|array|min:1|max:'.BadgeIssuance::MAX_PER_CALL, 'ids.*' => 'required|string|max:64']);
        $plan = $data['support'] === BadgeIssuance::TEMPORARY ? $issuance->planTemporary($data['ids']) : $issuance->planCards($data['ids']);

        return response()->json(['plan' => $plan])->header('Cache-Control', 'no-store, private');
    }

    /** Génération confirmée : même contrat que l'unitaire ; réussites et échecs distincts ; rejouable sans doublon avec la même opération. */
    public function generate(Request $request, BadgeIssuance $issuance): JsonResponse
    {
        $this->authorize();
        $data = $request->validate(['support' => 'required|in:badge_temporaire,carte_permanente', 'ids' => 'required|array|min:1|max:'.BadgeIssuance::MAX_PER_CALL, 'ids.*' => 'required|string|max:64', 'operation' => 'required|uuid',
            'confirm' => 'required|accepted']);
        try {
            $result = $issuance->issueMany($data['support'], $data['ids'], $data['operation'], $this->user($request));
        } catch (BadgeRefused $e) {
            throw ValidationException::withMessages([$e->reason => $e->getMessage()]);
        }
        $badgeIds = array_merge(array_column($result['created'], 'badge_id'), array_column(array_filter($result['existing'], fn (array $r): bool => isset($r['badge_id'])), 'badge_id'));

        return response()->json([...$result, 'complete' => $result['complete'], 'printable' => array_values(array_unique($badgeIds))])->header('Cache-Control', 'no-store, private');
    }
}
