<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\BadgeArtwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class BadgeWorkshopController
{
    public function index(): \Inertia\Response
    {
        Gate::authorize('badges.gerer');
        $events = DB::table('events')->orderBy('name')->get(['id', 'name']);
        $preferences = [];
        foreach ($events as $event) {
            $preferences[$event->id] = BadgeArtwork::defaults();
            foreach ($preferences[$event->id] as $key => $default) {
                $preferences[$event->id][$key] = DB::table('settings')->where('key', 'badge_atelier_'.$event->id.'_'.$key)->value('value') ?? $default;
            }
        }
        $number = "CASE WHEN code ~ '^B-[0-9]+\$' THEN substring(code from 3)::integer END";

        return Inertia::render('Admin/BadgeWorkshop', [
            'events' => $events, 'preferences' => $preferences,
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
            'html' => $artwork->card($badges->first(), $options), 'count' => $badges->count(),
            'first' => $badges->first()->code, 'last' => $badges->last()->code,
            'inactive' => $badges->whereNotIn('status', ['Disponible', 'Associé'])->count(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $request, BadgeArtwork $artwork): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $data = $artwork->validate($request, false);
        $event = $data['event'];
        unset($data['event'], $data['mode']);
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
        $cards = $badges->map(fn (object $badge) => $artwork->card($badge, $options))->all();
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);
        // Il s’agit d’une préparation : le navigateur ne permet pas de confirmer le tirage physique.
        app(Audit::class)->append('badge.impression_preparee', auth()->id(), [
            'event_id' => $options['event'], 'batch_id' => $options['batch'], 'modele' => $options['design'],
            'format' => $options['format'], 'face' => $options['side'], 'nombre' => $badges->count(),
            'premier' => $badges->first()->code, 'dernier' => $badges->last()->code,
        ]);

        return response()->view('badges.artwork-print', [
            'options' => $options, 'sheets' => array_chunk($cards, $perSheet), 'count' => count($cards),
            'dimensions' => BadgeArtwork::dimensions($options['orientation']), 'nonce' => $nonce,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
