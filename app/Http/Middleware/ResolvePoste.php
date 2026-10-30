<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\EventAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie la tablette par son jeton d'appareil (en-tête X-Device-Token), conservé haché côté serveur (docs/AUTH_HORS_LIGNE.md).
 * Sans jeton valide, le poste ne peut ni lire la liste ni écrire ni synchroniser. Le jeton n'est jamais journalisé.
 */
class ResolvePoste
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header('X-Device-Token');
        if ($token === '') {
            return response()->json(['code' => 'poste_requis', 'message' => 'Enrôlez cette tablette avant de continuer.', 'action' => null], 422);
        }
        $device = preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? Device::query()->where('token_hash', hash('sha256', $token))->first() : null;
        if ($device === null) {
            return response()->json(['code' => 'appareil_non_enrole', 'message' => 'Cette tablette n’est pas enrôlée : demandez un code d’enrôlement à l’administrateur.', 'action' => null], 401);
        }
        $isWipeReceipt = $request->is('poste/api/efface') && $request->isMethod('POST');
        if ($device->revoked_at !== null && ! $isWipeReceipt) {
            return response()->json(['code' => 'appareil_revoque', 'message' => 'Cette tablette a été révoquée : ses données locales vont être effacées.', 'action' => null], 401);
        }
        if (! $isWipeReceipt) {
            EventAvailability::requireOpen($device->event_id);
        }
        $request->attributes->set('poste', $device);

        return $next($request);
    }
}
