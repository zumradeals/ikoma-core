<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F5AA6">
    <title>IKOMA Accueil</title>
    <meta name="description" content="IKOMA Accueil : accueil, contrôle des badges et suivi en direct des visiteurs, avec ou sans réseau.">
    <link rel="manifest" href="/build/manifest.webmanifest">
    @vite(['resources/js/app.ts'])
    @inertiaHead
</head>
<body>
@if (app()->environment('demo'))<p role="status" data-testid="bandeau-demo" style="margin:0;padding:8px 16px;background:#FDE68A;color:#451A03;text-align:center;font:700 16px system-ui,sans-serif">Démonstration : données fictives uniquement. Ce n’est pas la production.</p>@endif
@inertia</body>
</html>
