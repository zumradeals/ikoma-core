<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use App\Services\Audit;
use App\Services\Groups;
use App\Services\HostGuests;
use App\Services\Poste\DeskRefused;
use App\Services\ProfilePortrait;
use App\Services\VisitorSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AdminVisitorsController
{
    public function index(Request $request): Response
    {
        Gate::authorize('listes.importer');
        $input = $request->validate(['event_id' => 'nullable|uuid|exists:events,id', 'q' => 'nullable|string|max:160', 'withdrawn' => 'nullable|boolean', 'page' => 'nullable|integer|min:1|max:100000', 'visitor' => 'nullable|uuid|exists:visitors,id']);
        $visitor = isset($input['visitor']) ? Visitor::findOrFail($input['visitor']) : null;
        $event = $visitor->event_id ?? ($input['event_id'] ?? DB::table('events')->orderBy('name')->value('id'));
        $editable = $visitor?->withdrawn_at === null;
        if ($visitor) {
            try {
                app(HostGuests::class)->editable($visitor, true);
            } catch (DeskRefused) {
                $editable = false;
            }
        }

        $list = $event ? DB::table('visitors')
            ->where('event_id', $event)->whereNull('withdrawn_at')
            ->orderByRaw('full_name COLLATE "C" ASC')->orderBy('id')
            ->paginate(20, ['id', 'full_name', 'organisation'], 'page', (int) ($input['page'] ?? 1)) : null;

        return Inertia::render('Admin/Visitors', [
            'events' => DB::table('events')->orderBy('name')->get(['id', 'name', 'starts_at', 'ends_at']), 'eventId' => $event,
            'hosts' => DB::table('hosts')->orderBy('name')->get(['id', 'event_id', 'name']), 'categories' => DB::table('categories')->orderBy('name')->get(['id', 'event_id', 'name']),
            'groups' => DB::table('groups')->orderBy('name')->get(['id', 'event_id', 'name']),
            'visitor' => $visitor ? [...$visitor->only(['id', 'event_id', 'full_name', 'organisation', 'position', 'category_id', 'host_id', 'group_id', 'valid_from', 'valid_until', 'profile_photo_print_allowed', 'withdrawn_at']), 'has_photo' => (bool) $visitor->profile_photo_file, 'revision' => (string) $visitor->updated_at] : null,
            'withdrawn' => $request->boolean('withdrawn'), 'withdrawnVisitors' => $event && $request->boolean('withdrawn') ? DB::table('visitors')->where('event_id', $event)->whereNotNull('withdrawn_at')->orderByRaw('full_name COLLATE "C" ASC')->orderBy('id')->limit(200)->get(['id', 'full_name', 'organisation']) : [],
            'list' => $list, 'searchQuery' => $input['q'] ?? '',
            'editable' => $editable, 'feedback' => $request->session()->get('visitor_feedback'),
            'results' => $event && ! empty($input['q']) ? app(VisitorSearch::class)->searchDetailed($event, $input['q']) : [],
        ]);
    }

    public function store(Request $request, HostGuests $guests, ProfilePortrait $portraits, ?string $visitor = null): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $data = $request->validate([
            'event_id' => 'required|uuid|exists:events,id', 'full_name' => 'required|string|max:160', 'organisation' => 'nullable|string|max:160', 'position' => 'nullable|string|max:160',
            'category_id' => ['required', 'uuid', Rule::exists('categories', 'id')->where('event_id', $request->input('event_id'))],
            'host_id' => ['nullable', 'uuid', Rule::exists('hosts', 'id')->where('event_id', $request->input('event_id'))],
            'group_id' => ['nullable', 'uuid', Rule::exists('groups', 'id')->where('event_id', $request->input('event_id'))],
            'valid_from' => 'required|date', 'valid_until' => 'required|date|after_or_equal:valid_from',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png|max:5120|dimensions:max_width=6000,max_height=6000', 'remove_photo' => 'required|boolean', 'photo_print_allowed' => 'required|boolean',
        ]);
        if ($visitor) {
            abort_unless(Visitor::where('id', $visitor)->where('event_id', $data['event_id'])->exists(), 422, 'La fiche appartient à un autre événement.');
        }
        $result = DB::transaction(function () use ($request, $guests, $portraits, $data, $visitor) {
            $result = $guests->save($request->user(), $data, $visitor, true);
            $v = Visitor::lockForUpdate()->findOrFail($result['id']);
            if (($data['group_id'] ?? null) !== $v->group_id) {
                Gate::authorize('groupes.gerer');
                if ($v->group_id) {
                    app(Groups::class)->detach($v->group_id, $v->id, $request->user());
                }
                if (! empty($data['group_id'])) {
                    app(Groups::class)->attach($data['group_id'], $v->id, $request->user());
                }
            }
            $portraits->update($v, $request->file('photo'), $request->boolean('remove_photo'), $request->boolean('photo_print_allowed'));

            return $result;
        });

        return redirect('/administration/invites?visitor='.$result['id'])->with('visitor_feedback', $result['homonym'] ? 'Fiche enregistrée. Homonyme : vérifiez l’organisation ou l’hôte à l’accueil.' : 'Fiche enregistrée. Elle sera visible à l’accueil après synchronisation.');
    }

    public function destroy(Request $request, string $visitor, HostGuests $guests): RedirectResponse
    {
        Gate::authorize('listes.importer');
        $v = Visitor::findOrFail($visitor);
        try {
            $guests->withdraw($request->user(), $visitor, true);
        } catch (DeskRefused $e) {
            app(Audit::class)->append('gestion.retrait_refuse', $request->user()->id, ['visitor_id' => $visitor, 'raison' => $e->code_]);
            if ($request->expectsJson()) {
                throw $e;
            }
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/administration/invites?event_id='.$v->event_id)->with('visitor_feedback', 'Fiche retirée de la liste.');
    }

    public function portrait(string $visitor, ProfilePortrait $portraits): \Illuminate\Http\Response
    {
        Gate::authorize('listes.importer');
        $v = Visitor::findOrFail($visitor);
        $jpeg = $portraits->read($v);
        abort_if($jpeg === null, 404);

        return response($jpeg)->header('Content-Type', 'image/jpeg')->header('Cache-Control', 'no-store, private')->header('Content-Disposition', 'inline');
    }
}
