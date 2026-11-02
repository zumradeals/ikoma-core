<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Référentiel des catégories institutionnelles (AILCT-01 à AILCT-08).
 *
 * Une catégorie institutionnelle est une étiquette d'identité et de présentation : elle ne confère JAMAIS une autorisation d'accès.
 * Les droits d'accès viennent uniquement de la catégorie d'événement (table category_zone). Aucun service de contrôle ne lit ce référentiel.
 * Les libellés viennent des contrats AILCT validés ; couleurs et repères sont NEUTRES tant que l'institution n'a pas fourni les siens.
 */
final class InstitutionalCategories
{
    public const NEUTRAL_COLOR = '#64748B';

    /** @var array<string, string> */
    public const LABELS = [
        'AILCT-01' => 'Personnel AILCT', 'AILCT-02' => 'Partenaires permanents', 'AILCT-03' => 'Intervenants occasionnels', 'AILCT-04' => 'Délégations officielles',
        'AILCT-05' => 'Visiteurs', 'AILCT-06' => 'Accompagnateurs', 'AILCT-07' => 'Stagiaires', 'AILCT-08' => 'Experts',
    ];

    /** @return list<object> */
    public function all(): array
    {
        return DB::table('institutional_categories')->orderBy('sort_order')->get()->all();
    }

    public function initialized(): bool
    {
        return DB::table('institutional_categories')->exists();
    }

    /** Crée les huit catégories manquantes ; n'écrase jamais une catégorie existante. */
    public function initialize(User $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $created = 0;
            $order = 0;
            foreach (self::LABELS as $code => $label) {
                $order++;
                $created += DB::table('institutional_categories')->insertOrIgnore([
                    'code' => $code, 'label' => $label, 'color' => self::NEUTRAL_COLOR, 'symbol' => substr($code, -2), 'sort_order' => $order,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($created > 0) {
                app(Audit::class)->append('categorie_institutionnelle.initialisee', $actor->id, ['creees' => $created]);
            }

            return $created;
        });
    }

    /** @param array{label: string, color: string, symbol: string} $data */
    public function update(string $code, array $data, User $actor): void
    {
        DB::transaction(function () use ($code, $data, $actor): void {
            $row = DB::table('institutional_categories')->where('code', $code)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            if (preg_match('/\A#[0-9A-Fa-f]{6}\z/', $data['color']) !== 1) {
                throw ValidationException::withMessages(['color' => 'Couleur au format #RRGGBB.']);
            }
            $new = ['label' => trim($data['label']), 'color' => strtoupper($data['color']), 'symbol' => trim($data['symbol'])];
            $changed = array_keys(array_filter($new, fn ($value, $key) => (string) $row->{$key} !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return;
            }
            DB::table('institutional_categories')->where('code', $code)->update([...$new, 'updated_at' => now()]);
            app(Audit::class)->append('categorie_institutionnelle.modifiee', $actor->id, ['code' => $code, 'champs' => $changed]);
        });
    }

    /** Associe (ou dissocie, avec null) une catégorie d'événement à une catégorie institutionnelle. N'accorde aucun droit. */
    public function link(string $eventId, string $categoryId, ?string $code, User $actor): void
    {
        DB::transaction(function () use ($eventId, $categoryId, $code, $actor): void {
            EventAvailability::requireOpen($eventId);
            $category = DB::table('categories')->where('id', $categoryId)->where('event_id', $eventId)->lockForUpdate()->first();
            abort_unless($category !== null, 404);
            if ($code !== null && ! DB::table('institutional_categories')->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'Catégorie institutionnelle inconnue.']);
            }
            if ($code !== null && DB::table('categories')->where('event_id', $eventId)->where('institutional_code', $code)->where('id', '<>', $categoryId)->exists()) {
                throw ValidationException::withMessages(['code' => 'Une autre catégorie de cet événement porte déjà cette catégorie institutionnelle.']);
            }
            if ($category->institutional_code === $code) {
                return;
            }
            DB::table('categories')->where('id', $categoryId)->update(['institutional_code' => $code, 'updated_at' => now()]);
            app(Audit::class)->append('categorie_institutionnelle.liee', $actor->id, ['event_id' => $eventId, 'category_id' => $categoryId, 'de' => $category->institutional_code, 'vers' => $code]);
        });
    }
}
