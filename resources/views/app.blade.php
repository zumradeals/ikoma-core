@php($institution = app(\App\Services\Institution::class)->profile())
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $institution['custom'] ? $institution['primary'] : '#0B3C6B' }}">
    <title>{{ $institution['name'] }}</title>
    <meta name="description" content="{{ $institution['custom'] ? ($institution['homeDescription'] ?: $institution['fullName']) : 'IKOMA ACCESS : contrôlez les accès, maîtrisez les visites. Gestion des visiteurs, badges, zones et supervision.' }}">
    @if ($institution['logoUrl'])
    <link rel="icon" type="image/png" href="{{ $institution['logoUrl'] }}">
    @else
    <link rel="icon" type="image/svg+xml" href="/icon.svg">
    @endif
    <link rel="manifest" href="/build/manifest.webmanifest">
    @vite(['resources/js/app.ts'])
    @if ($institution['custom'])
    <link rel="stylesheet" href="/institution/theme.css?v={{ $institution['version'] }}">
    @endif
    @inertiaHead
</head>
<body>
@inertia</body>
</html>
