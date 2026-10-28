<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure()) {
            config(['session.secure' => true]);
        }

        return self::apply($request, $next($request));
    }

    public static function apply(Request $request, Response $response): Response
    {
        $camera = in_array($request->path(), ['poste', 'poste/shell', 'controle', 'controle/shell', 'groupe', 'groupe/shell'], true);
        // Les attributs de style Vue servent aux couleurs des catégories et à la progression. Aucun script en ligne n'est permis.
        $styleNonce = (string) $request->attributes->get('csp_style_nonce', '');
        $styleSrc = $styleNonce !== '' ? "'self' 'nonce-{$styleNonce}'" : "'self'";
        $scriptSrc = $styleNonce !== '' ? "'self' 'nonce-{$styleNonce}'" : "'self'";
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src {$scriptSrc}; style-src {$styleSrc}; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera='.($camera ? '(self)' : '()').', microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
