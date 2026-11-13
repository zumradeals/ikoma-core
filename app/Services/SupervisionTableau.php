<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du superviseur : deux lectures de plus pour l'accueil du Pilotage, sans aucune écriture ni table nouvelle.
 * - les postes de contrôle PERMANENTS du site (nom, fonction, état, passages du jour) ;
 * - les activités (stages et événements) en cours, à venir ou récemment terminées, avec leurs effectifs.
 * Aucun nom de personne n'apparaît ici.
 */
final class SupervisionTableau
{
    private const ROWS = 4;

    public function __construct(private readonly ActivityRegistry $activities) {}

    /** @return array{postes: list<array<string, mixed>>, activites: array{en_cours: int, stages: int, evenements: int, rows: list<array<string, mixed>>}} */
    public function build(): array
    {
        return ['postes' => $this->postes(), 'activites' => $this->activites()];
    }

    /**
     * État d'un poste : « inactif » (désactivé par l'administrateur), « silencieux » (des tablettes enrôlées sans nouvelles récentes), sinon « actif ».
     * Passages : mouvements du jour enregistrés à ce poste (entrées, sorties, contrôles), hors mouvements corrigés.
     *
     * @return list<array<string, mixed>>
     */
    private function postes(): array
    {
        $silentBefore = now()->subMinutes(Pilotage::silentAfterMinutes());
        $tablets = DB::table('devices')->whereNotNull('token_hash')->whereNull('revoked_at')->whereNotNull('checkpoint_id')->get(['checkpoint_id', 'last_synced_at'])->groupBy('checkpoint_id');
        $passages = DB::table('access_events as e')->whereIn('e.type', ['entree', 'sortie', 'controle'])->where('e.occurred_at', '>=', now()->startOfDay())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('access_events as c')->whereColumn('c.corrige_id', 'e.id'))
            ->selectRaw('e.checkpoint, count(*) AS n')->groupBy('e.checkpoint')->pluck('n', 'checkpoint');

        return DB::table('checkpoints')->orderBy('code')->get(['id', 'code', 'name', 'function_label', 'active'])->map(function ($p) use ($tablets, $passages, $silentBefore): array {
            $mine = $tablets[$p->id] ?? collect();
            $recent = $mine->contains(fn ($t) => $t->last_synced_at !== null && $t->last_synced_at >= $silentBefore->toDateTimeString());
            $state = ! $p->active ? 'inactif' : ($mine->isNotEmpty() && ! $recent ? 'silencieux' : 'actif');

            return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'function_label' => $p->function_label, 'state' => $state, 'passages' => (int) ($passages[$p->code] ?? 0)];
        })->values()->all();
    }

    /**
     * Activités ouvertes : celles en cours d'abord, puis la prochaine à venir, puis les plus récemment terminées.
     *
     * @return array{en_cours: int, stages: int, evenements: int, rows: list<array<string, mixed>>}
     */
    private function activites(): array
    {
        $all = collect($this->activities->rows(false));
        $current = $all->where('phase', 'en_cours')->sortBy('starts_at')->values();
        $upcoming = $all->where('phase', 'a_venir')->sortBy('starts_at')->values();
        $done = $all->where('phase', 'terminee')->sortByDesc('ends_at')->values();
        $rows = $current->concat($upcoming)->concat($done)->take(self::ROWS)->map(fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'phase' => $r['phase'], 'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'participants' => (int) $r['participants'],
        ])->values()->all();

        return ['en_cours' => $current->count(), 'stages' => $current->where('kind', 'stage')->count(), 'evenements' => $current->where('kind', '!=', 'stage')->count(), 'rows' => $rows];
    }
}
