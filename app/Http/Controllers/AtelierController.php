<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Atelier\BadgePrinting;
use App\Services\Atelier\PrintTrail;
use App\Services\BadgeArtwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Atelier des badges (R5a, parcours visiteurs) : un seul point d'entrée pour choisir un modèle, sélectionner, prévisualiser, imprimer et tracer.
 * Il n'écrit que de la traçabilité d'impression : jamais un statut, une association, une réservation, une habilitation ou une carte.
 */
final class AtelierController
{
    public function index(Request $request): \Inertia\Response
    {
        Gate::authorize('badges.gerer');
        $input = $request->validate([
            'person' => 'nullable|uuid|exists:visitors,id', 'batch' => 'nullable|uuid|exists:badge_batches,id', 'format' => 'nullable|in:pvc,a4,etiquettes',
            'from' => 'nullable|integer|min:1', 'to' => 'nullable|integer|min:1', 'cols' => 'nullable|integer|min:1|max:4', 'rows' => 'nullable|integer|min:1|max:10',
        ]);
        $person = null;
        if (! empty($input['person'])) {
            Gate::authorize('listes.importer');
            $person = DB::table('visitors')->where('id', $input['person'])->whereNull('withdrawn_at')->whereNull('holder_id')->first(['id', 'event_id', 'category_id', 'full_name']);
        }
        $events = DB::table('events')->where('is_permanent', false)->orderBy('name')->get(['id', 'name']);
        $preferences = [];
        $settings = DB::table('settings')->where('key', 'like', 'badge_atelier_%')->pluck('value', 'key');
        foreach ($events as $event) {
            $preferences[$event->id] = BadgeArtwork::defaults();
            foreach ($preferences[$event->id] as $key => $default) {
                $preferences[$event->id][$key] = $settings->get('badge_atelier_'.$event->id.'_'.$key, $default);
            }
        }
        $number = "CASE WHEN code ~ '^B-[0-9]+\$' THEN substring(code from 3)::integer END";
        $batchEvent = ! empty($input['batch']) ? DB::table('badge_batches')->where('id', $input['batch'])->value('event_id') : null;

        return Inertia::render('Admin/Atelier', [
            'events' => $events, 'preferences' => $preferences, 'person' => $person,
            'batches' => DB::table('badge_batches as batch')->leftJoin('categories as c', 'c.id', '=', 'batch.category_id')
                ->selectRaw("batch.id, batch.event_id, batch.name, c.name as category, (SELECT min($number) FROM badges WHERE badge_batch_id = batch.id) as first_number, (SELECT max($number) FROM badges WHERE badge_batch_id = batch.id) as last_number")
                ->orderBy('batch.name')->get(),
            'runs' => app(PrintTrail::class)->recent(null, 30),
            'initial' => ['event' => $batchEvent, 'batch' => $input['batch'] ?? null, 'format' => $input['format'] ?? null, 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null, 'cols' => $input['cols'] ?? null, 'rows' => $input['rows'] ?? null],
            'canPrepare' => $request->user()->can('listes.importer'),
        ]);
    }

    /** Aperçu d'un modèle : recto et verso d'exemple. Aucun vrai QR, aucun lot lu, aucune exposition. */
    public function specimen(Request $request, BadgePrinting $printing): JsonResponse
    {
        Gate::authorize('badges.gerer');

        return response()->json($printing->specimenPreview($request->all()))->header('Cache-Control', 'no-store, private');
    }

    /** Génère un tirage (document généré) : officiel (badges réels ou étiquettes) ou essai marqué. */
    public function plan(Request $request, BadgePrinting $printing): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $trial = $request->boolean('essai');
        $reason = $request->input('reason');
        $reason = is_string($reason) ? $reason : null;
        $format = (string) $request->input('format');
        $run = match (true) {
            $trial => $printing->planSpecimen($request->user(), $request->all()),
            $format === 'etiquettes' => $printing->planLabels($request->user(), $request->all(), $reason),
            default => $printing->planBadges($request->user(), $request->all(), $reason),
        };

        return response()->json(['run' => $run, 'state' => 'genere', 'document_url' => '/administration/atelier/tirages/'.$run.'/document'])->header('Cache-Control', 'no-store, private');
    }

    public function document(Request $request, string $run, BadgePrinting $printing): Response
    {
        Gate::authorize('badges.gerer');

        return $printing->document($request, $run);
    }

    public function requested(Request $request, string $run, PrintTrail $trail): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $trail->requested($this->user($request), $run);

        return response()->json(['state' => $trail->state($run)])->header('Cache-Control', 'no-store, private');
    }

    public function confirmed(Request $request, string $run, PrintTrail $trail): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $data = $request->validate(['printed' => 'required|integer|min:0|max:100000', 'spoiled' => 'required|integer|min:0|max:100000', 'note' => 'nullable|string|max:200']);
        $trail->confirmed($this->user($request), $run, (int) $data['printed'], (int) $data['spoiled'], $data['note'] ?? null);

        return response()->json(['state' => $trail->state($run)])->header('Cache-Control', 'no-store, private');
    }

    public function runs(Request $request, PrintTrail $trail): JsonResponse
    {
        Gate::authorize('badges.gerer');
        $data = $request->validate(['event' => 'nullable|uuid|exists:events,id']);

        return response()->json(['runs' => $trail->recent($data['event'] ?? null, 30)])->header('Cache-Control', 'no-store, private');
    }

    public function alignment(Request $request, BadgePrinting $printing): Response
    {
        Gate::authorize('badges.gerer');

        return $printing->alignment($request);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
