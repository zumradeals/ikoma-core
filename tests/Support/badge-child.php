<?php

use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeRefused;
use App\Services\BadgeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Processus enfant des tests de concurrence : une opération sur la base, résultat JSON sur la sortie standard.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $badge, $visitor, $user] = $argv;
try {
    if ($operation === 'associer') {
        $assignment = app(BadgeService::class)->associer($badge, $visitor, User::findOrFail($user));
        echo json_encode(['ok' => true, 'assignment' => $assignment->id]);
    } elseif ($operation === 'lot') {
        // Arguments : catégorie, événement, administrateur.
        app(BadgeLots::class)->generate($visitor, $badge, 3, User::findOrFail($user));
        echo json_encode(['ok' => true]);
    } else {
        DB::table('badge_assignments')->insert(['id' => (string) Str::uuid(), 'event_id' => $user, 'badge_id' => $badge, 'visitor_id' => $visitor, 'assigned_at' => now()]);
        echo json_encode(['ok' => true]);
    }
} catch (BadgeRefused $e) {
    echo json_encode(['ok' => false, 'reason' => $e->reason]);
} catch (QueryException $e) {
    echo json_encode(['ok' => false, 'sqlstate' => $e->getCode()]);
}
