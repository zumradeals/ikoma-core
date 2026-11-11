<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;

/**
 * Reprise de l'ancien registre technique des cartes permanentes (retrait du conteneur, étape 4).
 *
 * Les cartes créées avant le retrait vivaient dans un événement protégé (`is_permanent`). detach() les rattache au SITE (badge sans événement, lot,
 * catégorie, participation ni réservation ; fiche carte, émissions, tirages et identifiants retirés sans événement) puis efface tout ce que le registre
 * contenait d'autre (participations, passages, lots, catégories, puis l'événement lui-même). Décision du dirigeant : installation d'essai, aucune donnée réelle.
 *
 * Réservé à la migration (compte propriétaire) : les protections « ajout seul » sont suspendues table par table le temps de la reprise, puis rétablies
 * avant la fin de la transaction de l'appelant. Aucun identifiant de titulaire, code de badge ni jeton QR n'est modifié.
 */
final class LegacyCardRegistry
{
    /** @var array<string, true> */
    private array $suspended = [];

    /** Garde-fou : une reprise qui dépasse ce nombre d'étapes est interrompue (et annulée par l'appelant) plutôt que de tourner sans fin. */
    private const MAX_STEPS = 20000;

    private int $steps = 0;

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return array{cartes: int, supprimes: int} */
    public function detach(string $registryId): array
    {
        $cards = (int) $this->db->table('holder_cards')->where('event_id', $registryId)->count();

        // 1. Les cartes passent au site.
        foreach (['holder_cards', 'badges', 'badge_issuances', 'badge_print_runs', 'retired_badge_identifiers'] as $table) {
            $this->suspend($table);
        }
        $this->db->table('holder_cards')->where('event_id', $registryId)->update(['event_id' => null]);
        $badgeIds = $this->db->table('holder_cards')->pluck('badge_id')->all();
        foreach (array_chunk($badgeIds, 500) as $chunk) {
            $this->db->table('badges')->where('event_id', $registryId)->whereIn('id', $chunk)
                ->update(['event_id' => null, 'badge_batch_id' => null, 'category_id' => null, 'printed_for_visitor_id' => null, 'reserved_device_id' => null, 'reserved_group_id' => null]);
        }
        $this->db->table('badge_issuances')->where('event_id', $registryId)->whereNotNull('holder_id')->update(['event_id' => null]);
        $this->db->table('badge_print_runs')->where('event_id', $registryId)->update(['event_id' => null, 'batch_id' => null]);
        $this->db->table('retired_badge_identifiers')->where('event_id', $registryId)->update(['event_id' => null]);

        // 2. Tout ce qui reste rattaché au registre est effacé (dépendants d'abord).
        $deleted = 0;
        $tables = $this->db->select("SELECT c.table_name FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name WHERE c.table_schema = 'public' AND c.column_name = 'event_id' AND t.table_type = 'BASE TABLE' ORDER BY c.table_name");
        foreach ($tables as $row) {
            $table = (string) $row->table_name;
            if ($table === 'events') {
                continue;
            }
            $deleted += $this->purge($table, 'event_id = ?', [$registryId]);
        }
        $deleted += $this->purge('events', 'id = ?', [$registryId]);

        foreach (array_keys($this->suspended) as $table) {
            $this->db->statement('ALTER TABLE public.'.$this->quote($table).' ENABLE TRIGGER USER');
        }
        $this->suspended = [];

        return ['cartes' => $cards, 'supprimes' => $deleted];
    }

    /**
     * Efface les lignes de $table qui répondent à $where, après avoir effacé celles qui les référencent (clés étrangères lues dans le catalogue).
     *
     * @param  list<mixed>  $bindings
     * @param  list<string>  $path  tables déjà en cours de traitement sur ce chemin : coupe les cycles du schéma
     */
    private function purge(string $table, string $where, array $bindings, array $path = []): int
    {
        if (in_array($table, $path, true)) {
            return 0;
        }
        if (++$this->steps > self::MAX_STEPS) {
            throw new \RuntimeException('Reprise de l\'ancien registre interrompue : le schéma est trop imbriqué pour une suppression automatique.');
        }
        $path[] = $table;
        $this->suspend($table);
        $deleted = 0;
        $children = $this->db->select(
            "SELECT c.conrelid::regclass::text AS child, ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(n, o) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.n ORDER BY k.o) AS child_cols,
                    ARRAY(SELECT a.attname FROM unnest(c.confkey) WITH ORDINALITY k(n, o) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.n ORDER BY k.o) AS parent_cols
             FROM pg_constraint c WHERE c.contype = 'f' AND c.confrelid = ?::regclass AND c.conrelid <> c.confrelid",
            ['public.'.$this->quote($table)],
        );
        foreach ($children as $fk) {
            $child = trim((string) preg_replace('/^public\./', '', (string) $fk->child), '"');
            $childCols = $this->pgArray($fk->child_cols);
            $parentCols = $this->pgArray($fk->parent_cols);
            $left = '('.implode(', ', array_map(fn (string $c): string => $this->quote($c), $childCols)).')';
            $right = '(SELECT '.implode(', ', array_map(fn (string $c): string => $this->quote($c), $parentCols)).' FROM public.'.$this->quote($table).' WHERE '.$where.')';
            $deleted += $this->purge($child, $left.' IN '.$right, $bindings, $path);
        }
        $deleted += $this->db->delete('DELETE FROM public.'.$this->quote($table).' WHERE '.$where, $bindings);

        return $deleted;
    }

    private function suspend(string $table): void
    {
        if (! isset($this->suspended[$table])) {
            $this->db->statement('ALTER TABLE public.'.$this->quote($table).' DISABLE TRIGGER USER');
            $this->suspended[$table] = true;
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** @return list<string> */
    private function pgArray(mixed $value): array
    {
        return array_values(array_filter(explode(',', trim((string) $value, '{}')), fn (string $v): bool => $v !== ''));
    }
}
