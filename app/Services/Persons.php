<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registre des personnes (S03/S04) : identité unique réutilisée d'une activité à l'autre.
 * Règle impérative : deux personnes ne sont JAMAIS fusionnées automatiquement sur la seule correspondance du nom. Un homonyme est signalé et la création d'une
 * nouvelle personne exige une confirmation explicite ; reprendre une personne connue est un choix explicite de l'utilisateur.
 */
final class Persons
{
    public function __construct(private readonly Audit $audit) {}

    /** @return list<object> */
    public function search(string $query, int $limit = 20): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], fn (string $t) => $t !== ''));
        $builder = DB::table('persons as p')->select('p.id', 'p.full_name', 'p.organisation', 'p.status')
            ->selectRaw('(SELECT count(*) FROM visitors v WHERE v.person_id = p.id) AS participations')
            ->selectRaw('EXISTS (SELECT 1 FROM permanent_holders h WHERE h.person_id = p.id) AS permanent');
        foreach (array_slice($tokens, 0, 5) as $token) {
            $builder->whereRaw("public.ikoma_normalize(p.full_name) LIKE '%' || public.ikoma_normalize(?) || '%'", [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token)]);
        }

        return $builder->orderByRaw('public.ikoma_normalize(p.full_name)')->orderBy('p.id')->limit($limit)->get()->all();
    }

    /**
     * Personnes de même nom (accents et casse ignorés) : jamais fusionnées, seulement signalées.
     *
     * @return list<object>
     */
    public function homonyms(string $fullName): array
    {
        return DB::table('persons as p')->select('p.id', 'p.full_name', 'p.organisation')
            ->whereRaw('public.ikoma_normalize(p.full_name) = public.ikoma_normalize(?)', [$fullName])->orderBy('p.id')->limit(10)->get()->all();
    }

    /**
     * @param  array{first_name?: ?string, last_name?: ?string, full_name?: ?string, organisation?: ?string}  $data
     */
    public function create(User $actor, array $data, bool $homonymConfirmed = false): string
    {
        abort_unless($actor->can('listes.importer'), 403);
        $clean = app(Imports\ListAnalysis::class);
        $first = $clean->clean($data['first_name'] ?? '');
        $last = $clean->clean($data['last_name'] ?? '');
        $full = $first !== '' && $last !== '' ? $first.' '.$last : $clean->clean($data['full_name'] ?? '');
        if (($first === '') !== ($last === '')) {
            throw ValidationException::withMessages(['last_name' => 'Indiquez le prénom et le nom ensemble, ou seulement le nom complet.']);
        }
        if ($full === '' || mb_strlen($full) > 160) {
            throw ValidationException::withMessages(['full_name' => 'Indiquez un nom de 1 à 160 caractères.']);
        }
        $org = $clean->clean($data['organisation'] ?? '');

        return DB::transaction(function () use ($actor, $first, $last, $full, $org, $homonymConfirmed): string {
            DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 7))', ['personnes']);
            $homonyms = $this->homonyms($full);
            if ($homonyms !== [] && ! $homonymConfirmed) {
                throw ValidationException::withMessages(['full_name' => 'Une personne de même nom existe déjà ('.count($homonyms).'). Reprenez-la depuis la recherche, ou confirmez qu’il s’agit d’une autre personne.']);
            }
            $id = (string) Str::uuid();
            DB::table('persons')->insert(['id' => $id, 'first_name' => $first !== '' ? $first : null, 'last_name' => $last !== '' ? $last : null, 'full_name' => $full, 'organisation' => $org !== '' ? $org : null, 'status' => 'actif', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->append('personne.creee', $actor->id, ['person_id' => $id, 'homonyme_confirme' => $homonyms !== []]);

            return $id;
        });
    }

    /**
     * Historique complet d'une personne : identité, participations (toutes activités) et attributions de badges.
     *
     * @return array<string, mixed>
     */
    public function history(string $personId): array
    {
        $person = DB::table('persons')->where('id', $personId)->first() ?? abort(404);
        $participations = DB::table('visitors as v')->join('events as e', 'e.id', '=', 'v.event_id')->join('categories as c', 'c.id', '=', 'v.category_id')
            ->where('v.person_id', $personId)->orderByDesc('e.starts_at')
            ->get(['v.id', 'v.event_id', 'e.name as event_name', 'e.kind as event_kind', 'e.status as event_status', 'c.name as category', 'v.status', 'v.valid_from', 'v.valid_until', 'v.withdrawn_at', 'v.closed_at', 'v.closure_reason', 'v.holder_id'])->all();
        $assignments = DB::table('badge_assignments as a')->join('visitors as v', 'v.id', '=', 'a.visitor_id')->join('badges as b', 'b.id', '=', 'a.badge_id')->join('events as e', 'e.id', '=', 'a.event_id')
            ->where('v.person_id', $personId)->orderByDesc('a.assigned_at')
            ->get(['a.id', 'b.code as badge_code', 'b.id as badge_id', 'e.name as event_name', 'a.assigned_at', 'a.returned_at', 'a.cancelled_at', 'a.cancellation_reason'])->all();
        $holder = DB::table('permanent_holders')->where('person_id', $personId)->first(['id', 'identifier', 'status', 'institutional_category']);

        return ['person' => $person, 'participations' => $participations, 'assignments' => $assignments, 'holder' => $holder];
    }
}
