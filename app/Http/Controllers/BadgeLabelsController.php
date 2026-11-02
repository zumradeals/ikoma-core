<?php

namespace App\Http\Controllers;

use App\Services\Atelier\LabelSheets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Ancien lien conservé : les étiquettes A4 sont un format de l'Atelier des badges (avec traçabilité).
 * La sélection demandée est validée comme avant, puis reprise dans l'Atelier.
 */
final class BadgeLabelsController
{
    public const MAX_LABELS = LabelSheets::MAX_LABELS;

    public function __invoke(Request $r, LabelSheets $labels): RedirectResponse
    {
        Gate::authorize('badges.gerer');
        $q = $labels->validate($r->all());

        return redirect('/administration/atelier?'.http_build_query(['format' => 'etiquettes', ...array_filter($q, fn ($v) => $v !== null)]));
    }
}
