<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Institution;
use App\Services\SupportPrinting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Impression unique des supports émis (badges temporaires nominatifs et cartes permanentes) : récapitulatif, aperçu et impression par le même contrat (SupportPrinting). */
final class SupportPrintController
{
    /** @return array{badges: list<string>, reason: ?string} */
    private function input(Request $request): array
    {
        /** @var array{badges: list<string>, reason?: ?string} $data */
        $data = $request->validate(['badges' => 'required|array|min:1|max:'.SupportPrinting::MAX_PER_CALL, 'badges.*' => 'required|uuid|distinct', 'reason' => 'nullable|string|max:200']);

        return ['badges' => array_values($data['badges']), 'reason' => $data['reason'] ?? null];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    public function plan(Request $request, SupportPrinting $printing): JsonResponse
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');

        return response()->json(['supports' => $printing->plan($this->user($request), $this->input($request)['badges'])])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, SupportPrinting $printing): Response
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');

        return $this->document($request, $printing->preview($this->user($request), $this->input($request)['badges']), 'apercu');
    }

    public function print(Request $request, SupportPrinting $printing): Response
    {
        Gate::authorize('badges.gerer');
        Gate::authorize('listes.importer');
        $input = $this->input($request);
        $result = $printing->print($this->user($request), $input['badges'], $input['reason']);

        return $this->document($request, $result['views'], 'emission');
    }

    /** @param list<array<string, mixed>> $views */
    private function document(Request $request, array $views, string $kind): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_style_nonce', $nonce);

        return response()->view('badges.holder-card-print', [
            'card' => null, 'cards' => $views, 'kind' => $kind, 'alignment' => false, 'nonce' => $nonce, 'institution' => app(Institution::class)->profile()['name'], 'back' => '/administration/badges',
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
