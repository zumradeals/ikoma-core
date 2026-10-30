<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\BadgeArtwork;
use App\Services\BadgePreparation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use stdClass;

final class BadgeWorkshopController
{
    public function index(Request $request): \Inertia\Response
    {
        Gate::authorize('badges.gerer');
        $personInput = $request->validate(['person' => 'nullable|uuid|exists:visitors,id']);
        $person = null;
        if (! empty($personInput['person'])) {
            Gate::authorize('listes.importer');
            $person = DB::table('visitors')->where('id', $personInput['person'])->whereNull('withdrawn_at')->first(['id', 'event_id', 'category_id', 'full_name']);
        }
        $events = DB::table('events')->orderBy('name')->get(['id', 'name']);
        $preferences = [];
        $settings = DB::table('settings')->where('key', 'like', 'badge_atelier_%')->pluck('value', 'key');
        foreach ($events as $event) {
            $preferences[$event->id] = BadgeArtwork::defaults();
            foreach ($preferences[$event->id] as $key => $default) {
                $preferences[$event->id][$key] = $settings->get('badge_atelier_'.$event->id.'_'.$key, $default);
            }
        }
        $number = "CASE WHEN code ~ '^B-[0-9]+\$' THEN substring(code from 3)::integer END";

        return Inertia::render('Admin/BadgeWorkshop', [
            'events' => $events, 'preferences' => $preferences, 'person' => $person,
            'batches' => DB::table('badge_batches as batch')->leftJoin('categories as c', 'c.id', '=', 'batch.category_id')
                ->selectRaw("batch.id, batch.event_id, batch.name, c.name as category, (SELECT min($number) FROM badges WHERE badge_batch_id = batch.id) as first_number, (SELECT max($number) FROM badges WHERE badge_batch_id = batch.id) as last_number")
                ->orderBy('batch.name')->get(),
        ]);
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

        return response()->json(['people' => $query->orderBy('v.full_name')->orderBy('v.id')->limit(200)->get(['v.id', 'v.full_name', 'v.organisation', 'v.valid_from', 'v.valid_until', 'h.name as host'])])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $request, BadgePreparation $preparation): JsonResponse
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
        $input = $request->validate(['event' => 'required|uuid|exists:events,id', 'batch' => 'required|uuid|exists:badge_batches,id', 'from' => 'required|integer|min:1', 'to' => 'required|integer|gte:from', 'visitor_ids' => 'required|array|min:1|max:200', 'visitor_ids.*' => 'required|uuid|distinct', 'personal_allowed' => 'accepted']);

        $prepared = $preparation->prepare($input['event'], $input['batch'], (int) $input['from'], (int) $input['to'], $input['visitor_ids'], $request->user());
        $posteIds = collect($prepared)->pluck('poste_id')->filter()->unique()->values();
        $postes = $posteIds->isEmpty() ? [] : DB::table('devices')->where('event_id', $input['event'])
            ->whereIn('id', $posteIds)->orderBy('name')->pluck('name')->all();

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

    public function print(Request $request, BadgeArtwork $artwork): Response
    {
        Gate::authorize('badges.gerer');
        $options = $artwork->validate($request);
        $badges = $artwork->select($options);
        $perSheet = $options['format'] === 'pvc' ? 1 : (int) $options['cols'] * (int) $options['rows'];
        $cards = $badges->map(fn (stdClass $badge) => $artwork->card($badge, $options))->all();
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);
        // Il s’agit d’une préparation : le navigateur ne permet pas de confirmer le tirage physique.
        app(Audit::class)->append('badge.impression_preparee', auth()->id(), [
            'event_id' => $options['event'], 'batch_id' => $options['batch'], 'modele' => $options['design'],
            'format' => $options['format'], 'face' => $options['side'], 'nombre' => $badges->count(),
            'premier' => $badges->firstOrFail()->code, 'dernier' => $badges->reverse()->firstOrFail()->code,
        ]);

        return response()->view('badges.artwork-print', [
            'options' => $options, 'sheets' => array_chunk($cards, $perSheet), 'count' => count($cards),
            'dimensions' => BadgeArtwork::dimensions($options['orientation']), 'nonce' => $nonce,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
