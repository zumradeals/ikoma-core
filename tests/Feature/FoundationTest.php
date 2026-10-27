<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('affiche la page publique sans données authentifiées', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Home')->where('auth', null));
});
it('active les extensions sans tables visiteurs et badges', function () {
    expect(DB::select("SELECT extname FROM pg_extension WHERE extname IN ('pg_trgm','unaccent')"))->toHaveCount(2);
    expect(DB::getSchemaBuilder()->hasTable('visitors'))->toBeFalse();
    expect(DB::getSchemaBuilder()->hasTable('badges'))->toBeFalse();
});
