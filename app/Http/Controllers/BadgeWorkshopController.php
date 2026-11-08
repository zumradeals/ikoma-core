<?php

namespace App\Http\Controllers;

use App\Services\Atelier\BadgePrinting;
use App\Services\Audit;
use App\Services\BadgeArtwork;
use App\Services\BadgePreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class BadgeWorkshopController
{
    /** Clés du modèle visuel d'un événement (identiques à celles de l'enregistrement : badge_atelier_{événement}_{clé}). */
    private const MODEL_KEYS = ['design', 'title', 'primary', 'accent', 'orientation'];

    /** Ancien lien conservé : l'atelier d'impression est devenu l'Atelier des badges. */
    public function index(Request $request): RedirectResponse
    {
        Gate::authorize('badges.gerer');

        return redirect('/administration/atelier'.($request->query() === [] ? '' : '?'.http_build_query($request->query())));
    }

    public function preview(Request $request, BadgeArtwork $artwork): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $options = $artwork->validate($request);
        $badges = $artwork->select($options);

        return response()->json([
            'html' => $artwork->card($badges->firstOrFail(), $options), 'count' => $badges->count(),
            'first' => $badges->firstOrFail()->code, 'last' => $badges->reverse()->firstOrFail()->code,
            'inactive' => $badges->whereNotIn('status', ['Disponible', 'Associé'])->count(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function people(Request $request): JsonResponse
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
        $input = $request->validate(['event' => 'required|uuid|exists:events,id', 'batch' => 'required|uuid|exists:badge_batches,id', 'q' => 'nullable|string|max:160']);
        $category = DB::table('badge_batches')->where('id', $input['batch'])->where('event_id', $input['event'])->value('category_id');
        $query = DB::table('visitors as v')->leftJoin('hosts as h', 'h.id', '=', 'v.host_id')->where('v.event_id', $input['event'])->where('v.category_id', $category)->whereNull('v.withdrawn_at');
        if (! empty($input['q'])) {
            $query->where('v.full_name', 'ilike', '%'.$input['q'].'%');
        }

        return response()->json(['people' => $query->orderByRaw('v.full_name COLLATE "C" ASC')->orderBy('v.id')->limit(200)->get(['v.id', 'v.full_name', 'v.organisation', 'v.valid_from', 'v.valid_until', 'h.name as host'])])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, BadgePreparation $preparation): JsonResponse
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
        $input = $request->validate(['event' => 'required|uuid|exists:events,id', 'batch' => 'required|uuid|exists:badge_batches,id', 'from' => 'required|integer|min:1', 'to' => 'required|integer|gte:from', 'visitor_ids' => 'required|array|min:1|max:200', 'visitor_ids.*' => 'required|uuid|distinct', 'personal_allowed' => 'accepted']);

        $prepared = $preparation->prepare($input['event'], $input['batch'], (int) $input['from'], (int) $input['to'], $input['visitor_ids'], $request->user());
        $posteIds = collect($prepared)->pluck('poste_id')->filter()->unique()->values();
        $postes = $posteIds->isEmpty() ? [] : DB::table('devices')->where('event_id', $input['event'])
            ->whereIn('id', $posteIds)->orderBy('name')
            ->selectRaw("COALESCE(NULLIF(station, ''), name) AS libelle")->pluck('libelle')->all();

        return response()->json([
            'prepared' => collect($prepared)->map(fn (array $item) => ['visitor_id' => $item['visitor_id'], 'code' => $item['code']])->all(),
            'postes_a_synchroniser' => $postes,
            'sync_required' => count($postes) > 0,
        ]);
    }

    public function save(Request $request, BadgeArtwork $artwork): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $data = $artwork->validate($request, false);
        $data = array_intersect_key($data, array_flip(['event', 'design', 'title', 'primary', 'accent', 'orientation']));
        $event = $data['event'];
        unset($data['event']);
        DB::transaction(function () use ($event, $data): void {
            foreach ($data as $key => $value) {
                DB::table('settings')->updateOrInsert(['key' => 'badge_atelier_'.$event.'_'.$key], ['value' => $value]);
            }
            app(Audit::class)->append('badge.modele_enregistre', auth()->id(), ['event_id' => $event, 'modele' => $data['design']]);
        });

        return back()->with('status', 'Le modèle est enregistré pour cet événement.');
    }

    /**
     * Revient au modèle par défaut d'un événement (S10) : efface les valeurs enregistrées, les défauts de l'installation s'appliquent de nouveau.
     * Ne touche ni aux lots, ni aux badges, ni aux tirages déjà faits : seul l'aspect proposé à la prochaine impression change.
     */
    public function resetModel(Request $request): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $data = $request->validate(['event' => ['required', 'uuid', Rule::exists('events', 'id')->where('is_permanent', false)]]);
        DB::transaction(function () use ($data): void {
            foreach (self::MODEL_KEYS as $key) {
                DB::table('settings')->where('key', 'badge_atelier_'.$data['event'].'_'.$key)->delete();
            }
            app(Audit::class)->append('badge.modele_reinitialise', auth()->id(), ['event_id' => $data['event']]);
        });

        return response()->json(['model' => BadgeArtwork::defaults()])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Reprend le modèle d'un autre événement (S10) : recopie son aspect (modèle, titre, couleurs, orientation) vers l'événement choisi.
     * Un événement sans modèle enregistré transmet les défauts. L'événement source n'est jamais modifié.
     */
    public function copyModel(Request $request): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $exists = Rule::exists('events', 'id')->where('is_permanent', false);
        $data = $request->validate(['event' => ['required', 'uuid', $exists], 'source' => ['required', 'uuid', 'different:event', $exists]]);
        $stored = DB::table('settings')->where('key', 'like', 'badge_atelier\_'.$data['source'].'\_%')->pluck('value', 'key');
        $model = [];
        foreach (BadgeArtwork::defaults() as $key => $default) {
            if (in_array($key, self::MODEL_KEYS, true)) {
                $model[$key] = $stored->get('badge_atelier_'.$data['source'].'_'.$key, $default);
            }
        }
        DB::transaction(function () use ($data, $model): void {
            foreach ($model as $key => $value) {
                DB::table('settings')->updateOrInsert(['key' => 'badge_atelier_'.$data['event'].'_'.$key], ['value' => $value]);
            }
            app(Audit::class)->append('badge.modele_repris', auth()->id(), ['event_id' => $data['event'], 'source_event_id' => $data['source'], 'modele' => $model['design'] ?? null]);
        });

        return response()->json(['model' => $model])->header('Cache-Control', 'no-store, private');
    }

    /** Ancien lien conservé : génère un tirage tracé puis affiche le même document que l'Atelier. */
    public function print(Request $request, BadgePrinting $printing): Response
    {
        Gate::authorize('badges.gerer');
        $reason = $request->input('reason');
        $run = $printing->planBadges($request->user(), $request->all(), is_string($reason) ? $reason : null);

        return $printing->document($request, $run);
    }
}
