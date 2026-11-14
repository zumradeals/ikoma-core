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
pest()->use(DatabaseTransactions::class)->in('Feature/SiteControlTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/SupervisorTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/ScanCinqPostesTest.php'); // lot A : les postes sont des données permanentes du site, ce fichier les modifie
pest()->use(DatabaseTransactions::class)->in('Feature/GroupsTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/BailPosteTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/HostSpaceTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/AdminDashboardTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/PilotageOverviewTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/HardeningTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/ProductionReadinessTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/InstallationTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/DemoCleanupTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/EventsTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/InstitutionTest.php');

pest()->use(DatabaseTransactions::class)->in('Feature/PermanentHoldersTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/HolderCardsTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/PermanentAccessTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/HomeConfigTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/DemoProtectionTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/ConsolidationTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/RecetteA07Test.php');
pest()->use(DatabaseTransactions::class)->in('Feature/RegistrePersonnesTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/AutorisationsS02Test.php');
pest()->use(DatabaseTransactions::class)->in('Feature/VisitesVehiculesVipTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/InterfacesS10Test.php');
pest()->use(DatabaseTransactions::class)->in('Feature/ConsolidationNavigationTest.php');
pest()->use(DatabaseTransactions::class)->in('Feature/CaracterisationV1Test.php');
pest()->use(DatabaseTransactions::class)->in('Feature/SiteAccesTest.php');

/**
 * Imprime un lot de badges par le circuit actuel : tirage tracé (plan) puis document du tirage.
 *
 * @param  array<string, mixed>  $options
 */
function atelierDocument(mixed $test, array $options): mixed
{
    $run = $test->postJson('/administration/atelier/tirages', $options)->assertOk()->json('run');

    return $test->get('/administration/atelier/tirages/'.$run.'/document');
}
