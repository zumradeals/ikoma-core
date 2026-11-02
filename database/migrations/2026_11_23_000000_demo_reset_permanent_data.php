<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase d'essais : la remise à zéro de démonstration efface aussi les personnes permanentes, leurs cartes, leurs habilitations et l'historique des tirages.
 * Les protections « ajout seul » restent actives en permanence : elles ne sont suspendues QUE le temps de cette fonction (dans sa transaction, rétablies avant la fin),
 * et seul un administrateur actif peut l'appeler. La double authentification n'est plus exigée (désactivée pour tous les comptes).
 * Avant la livraison au client : retirer le droit d'exécution de cette fonction au compte applicatif (REVOKE EXECUTE ... FROM ikoma_app).
 */
return new class extends Migration
{
    private const GUARDED = ['events', 'permanent_holders', 'holder_cards', 'holder_card_prints', 'holder_entitlements', 'badge_print_runs', 'badge_print_events'];

    public function up(): void
    {
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $updated = $definition;
        $steps = [
            ["            AND u.two_factor_confirmed_at IS NOT NULL\n", ''],
            ['public.holder_card_prints, public.holder_cards,', 'public.badge_print_events, public.badge_print_runs, public.permanent_holders, public.holder_card_prints, public.holder_cards,'],
            ['    TRUNCATE TABLE', $this->toggle('DISABLE')."\n    TRUNCATE TABLE"],
            ['    RESTART IDENTITY;', "    RESTART IDENTITY;\n".$this->toggle('ENABLE')],
        ];
        foreach ($steps as [$search, $replace]) {
            $updated = str_replace($search, $replace, $updated, $count);
            if ($count !== 1) {
                throw new RuntimeException('Fonction de remise à zéro inattendue : revue du contrat requise ('.trim($search).').');
            }
        }
        DB::unprepared($updated);
        // Le compte applicatif peut l'exécuter (y compris sur le serveur d'essai) ; l'appel reste réservé à l'administrateur actif.
        DB::unprepared("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ikoma_app') THEN GRANT EXECUTE ON FUNCTION public.ikoma_reset_demo_business(uuid, boolean) TO ikoma_app; END IF; END \$\$;");
    }

    public function down(): void
    {
        if (DB::table('permanent_holders')->exists() || DB::table('badge_print_runs')->exists()) {
            throw new RuntimeException('Migration non réversible : des personnes permanentes ou des tirages existent.');
        }
        $definition = (string) DB::selectOne("SELECT pg_get_functiondef('public.ikoma_reset_demo_business(uuid,boolean)'::regprocedure) AS d")->d;
        $restored = str_replace(
            ['public.badge_print_events, public.badge_print_runs, public.permanent_holders, ', $this->toggle('DISABLE')."\n", "\n".$this->toggle('ENABLE')],
            '',
            $definition,
        );
        $restored = str_replace("u.id = p_actor AND u.active IS TRUE\n", "u.id = p_actor AND u.active IS TRUE\n            AND u.two_factor_confirmed_at IS NOT NULL\n", $restored);
        DB::unprepared($restored);
    }

    private function toggle(string $action): string
    {
        return implode("\n", array_map(fn (string $table) => "    ALTER TABLE public.$table $action TRIGGER USER;", self::GUARDED));
    }
};
