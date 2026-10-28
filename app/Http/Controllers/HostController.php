<?php

namespace App\Http\Controllers;

use App\Services\HostGuests;
use App\Services\HostNotifications;
use App\Services\Poste\DeskRefused;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class HostController
{
    public function __construct(private readonly HostGuests $guests, private readonly HostNotifications $notifications) {}

    public function index(Request $r): Response
    {
        Gate::authorize('listes.mettre_a_jour');
        $hosts = $this->hosts($r);

        return Inertia::render('Hote/Guests', ['hosts' => $hosts, 'guests' => fn () => DB::table('visitors as v')->join('hosts as h', 'h.id', '=', 'v.host_id')->join('categories as c', 'c.id', '=', 'v.category_id')->where('h.user_id', $r->user()->id)->whereNull('v.withdrawn_at')->orderBy('v.full_name')->paginate(30, ['v.id', 'v.full_name', 'v.organisation', 'v.category_id', 'v.host_id', 'v.valid_from', 'v.valid_until', 'v.status', 'c.name as category_name', 'h.event_id'])->withQueryString(), 'categories' => DB::table('categories')->whereIn('event_id', $hosts->pluck('event_id'))->whereRaw("public.ikoma_normalize(name)='visiteur'")->get(['id', 'name', 'event_id']), 'feedback' => $r->session()->get('host_feedback')]);
    }

    public function show(Request $r, string $visitor): Response
    {
        Gate::authorize('listes.mettre_a_jour');
        $v = $this->guests->owned($r->user(), $visitor);

        return Inertia::render('Hote/Guest', ['visitor' => $v->only(['id', 'full_name', 'organisation', 'category_id', 'host_id', 'valid_from', 'valid_until', 'status']), 'hosts' => $this->hosts($r), 'categories' => DB::table('categories')->where('event_id', $v->event_id)->where(fn ($q) => $q->whereRaw("public.ikoma_normalize(name)='visiteur'")->orWhere('id', $v->category_id))->get(['id', 'name', 'event_id']), 'feedback' => $r->session()->get('host_feedback'), 'arrived' => DB::table('access_events')->where('visitor_id', $v->id)->where('type', 'entree')->exists()]);
    }

    public function store(Request $r): RedirectResponse
    {
        Gate::authorize('listes.mettre_a_jour');

        return $this->perform($r, function () use ($r) {
            $out = $this->guests->save($r->user(), $this->input($r, true));

            return redirect('/hote/invites')->with('host_feedback', $out['homonym'] ? '⚠ Invité ajouté. Homonyme dans l’événement : l’accueil demandera un second critère.' : '✓ Invité ajouté.');
        });
    }

    public function update(Request $r, string $visitor): RedirectResponse
    {
        Gate::authorize('listes.mettre_a_jour');

        return $this->perform($r, function () use ($r, $visitor) {
            $this->guests->owned($r->user(), $visitor);
            $out = $this->guests->save($r->user(), $this->input($r, false), $visitor);

            return back()->with('host_feedback', $out['homonym'] ? '⚠ Homonyme : l’accueil demandera un second critère.' : '✓ Modification enregistrée.');
        });
    }

    public function destroy(Request $r, string $visitor): RedirectResponse
    {
        Gate::authorize('listes.mettre_a_jour');

        return $this->perform($r, function () use ($r, $visitor) {
            $this->guests->withdraw($r->user(), $visitor);

            return redirect('/hote/invites')->with('host_feedback', '✓ Invité retiré.');
        });
    }

    public function notifications(Request $r): Response
    {
        Gate::authorize('arrivees.recevoir_notification');

        return Inertia::render('Hote/Notifications', ['linked' => $this->hosts($r)->isNotEmpty(), 'items' => fn () => $this->mine($r)->leftJoin('users as a', 'a.id', '=', 'n.requester_id')->select('n.id', 'n.type', 'n.message', 'n.read_at', 'n.created_at', 'n.decided_at', 'n.decision', 'v.full_name', 'v.organisation', 'v.host_validation', 'a.name as agent')->orderByDesc('n.created_at')->paginate(30)->withQueryString(), 'unread' => fn () => $this->mine($r)->whereNull('n.read_at')->count()]);
    }

    public function read(Request $r, string $notification): RedirectResponse
    {
        Gate::authorize('arrivees.recevoir_notification');
        $n = $this->mine($r)->where('n.id', $notification)->first(['n.id']);
        abort_unless($n !== null, 403);
        DB::table('notifications')->where('id', $n->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function decide(Request $r, string $notification): RedirectResponse
    {
        Gate::authorize('visiteurs.valider_non_liste');

        return $this->perform($r, function () use ($r, $notification) {
            abort_unless($this->mine($r)->where('n.id', $notification)->exists(), 403);
            $data = $r->validate(['accepted' => 'required|boolean', 'reason' => 'nullable|string|max:500']);
            $this->notifications->answer($notification, $r->user(), $r->boolean('accepted'), $data['reason'] ?? null);

            return back();
        });
    }

    /** Aucune exception technique ne recopie les valeurs nominatives des paramètres SQL dans les logs. */
    private function perform(Request $r, \Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (DeskRefused $e) {
            if ($r->header('X-Inertia') && $e->status !== 403) {
                return back()->withErrors(['request' => $e->getMessage()]);
            }throw $e;
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return back()->withErrors(['request' => 'L’opération n’a pas abouti. Rechargez l’écran puis réessayez.']);
        }
    }

    /** @return Collection<int,\stdClass> */
    private function hosts(Request $r): Collection
    {
        return DB::table('hosts as h')->join('events as e', 'e.id', '=', 'h.event_id')->where('h.user_id', $r->user()->id)->get(['h.id', 'h.name', 'h.event_id', 'e.name as event_name', 'e.starts_at', 'e.ends_at']);
    }

    private function mine(Request $r): Builder
    {
        return DB::table('notifications as n')->join('visitors as v', 'v.id', '=', 'n.visitor_id')->join('hosts as h', 'h.id', '=', 'v.host_id')->where('n.recipient_id', $r->user()->id)->where('h.user_id', $r->user()->id)->whereNull('v.withdrawn_at');
    }

    /** @return array<string,mixed> */
    private function input(Request $r, bool $create): array
    {
        return $r->validate(['host_id' => $create ? 'required|uuid' : 'sometimes|uuid', 'full_name' => 'required|string|max:160', 'organisation' => 'nullable|string|max:160', 'category_id' => 'required|uuid', 'valid_from' => 'required|date', 'valid_until' => 'required|date|after_or_equal:valid_from']);
    }
}
