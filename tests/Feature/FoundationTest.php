<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('affiche uniquement la page provisoire', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Home'));
});

it('active les extensions sans créer de tables métier', function () {
    $extensions = DB::select("SELECT extname FROM pg_extension WHERE extname IN ('pg_trgm', 'unaccent')");
    expect($extensions)->toHaveCount(2);
    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
    expect(array_column($tables, 'tablename'))->toBe(['migrations']);
});
