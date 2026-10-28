<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\LegacyL1Schema;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

// Le test FoundationTest garde le contrat de fin de L1 ; les autres suites utilisent le schéma courant.
pest()->beforeEach(fn () => LegacyL1Schema::start())
    ->afterEach(fn () => LegacyL1Schema::finish())
    ->in('Feature/FoundationTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/BusinessModelTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/ListImportTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/BadgeLifecycleTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/PosteTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/SyncTest.php');
