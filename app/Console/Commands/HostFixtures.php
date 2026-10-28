<?php

namespace App\Console\Commands;

use App\Models\Badge;
use App\Models\Device;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\Poste\Devices;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

final class HostFixtures extends Command
{
    protected $signature = 'test:host-fixtures';

    protected $description = 'Prépare une recette hôte fictive isolée des autres lots.';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            return self::FAILURE;
        }$this->callSilent('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $at = now();
        $suffix = Str::upper(Str::random(5));
        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'name' => 'Recette hôte fictive '.$suffix, 'starts_at' => $at->copy()->subHours(2), 'ends_at' => $at->copy()->addDays(2), 'created_at' => $at, 'updated_at' => $at]);
        $zones = [];
        foreach (['Hall accueil fictif' => true, 'Salle fictive' => false] as $name => $reception) {
            $id = (string) Str::uuid();
            DB::table('zones')->insert(['id' => $id, 'event_id' => $event, 'name' => $name, 'est_accueil' => $reception, 'created_at' => $at, 'updated_at' => $at]);
            $zones[] = $id;
        }
        $category = (string) Str::uuid();
        DB::table('categories')->insert(['id' => $category, 'event_id' => $event, 'name' => 'Visiteur', 'color' => '#0F5AA6', 'priority_circuit' => false, 'created_at' => $at, 'updated_at' => $at]);
        foreach ($zones as $zone) {
            DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'category_id' => $category, 'zone_id' => $zone, 'created_at' => $at, 'updated_at' => $at]);
        }
        $users = [];
        foreach (['hote', 'agent_accueil', 'administrateur'] as $role) {
            $password = Str::password(24);
            $u = User::create(['name' => 'Compte hôte fictif '.$suffix.' '.$role, 'email' => Str::uuid().'@exemple.test', 'password' => $password, 'two_factor_confirmed_at' => $at]);
            $u->assignRole($role);
            $users[$role] = ['id' => $u->id, 'email' => $u->email, 'password' => $password];
        }
        $host = (string) Str::uuid();
        DB::table('hosts')->insert(['id' => $host, 'event_id' => $event, 'user_id' => $users['hote']['id'], 'name' => 'Hôte fictif '.$suffix, 'created_at' => $at, 'updated_at' => $at]);
        $listed = (string) Str::uuid();
        $name = 'Invité fictif arrivée '.$suffix;
        DB::table('visitors')->insert(['id' => $listed, 'event_id' => $event, 'full_name' => $name, 'organisation' => 'Organisation fictive L10', 'host_id' => $host, 'category_id' => $category, 'valid_from' => $at->copy()->subHour(), 'valid_until' => $at->copy()->addDay(), 'source' => 'listé', 'status' => 'attendu', 'created_at' => $at, 'updated_at' => $at]);
        $poste = Device::create(['event_id' => $event, 'name' => 'Tablette hôte '.$suffix, 'station' => 'Poste hôte '.$suffix]);
        $admin = User::findOrFail($users['administrateur']['id']);
        $batch = app(BadgeLots::class)->generate($event, $category, 8, $admin);
        $badges = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->get();
        $numbers = $badges->pluck('code')->map(fn ($c) => (int) substr($c, 2));
        app(BadgeLots::class)->reserve($batch->id, $numbers->min(), $numbers->max(), $poste, $admin);
        $code = app(Devices::class)->issueCode($poste, $admin);
        DB::table('devices')->where('id', $poste->id)->update(['enrollment_expires_at' => now()->addHours(6)]);
        $this->output->writeln(json_encode(['host' => $users['hote'], 'agent' => $users['agent_accueil'], 'event' => $event, 'poste' => 'Poste hôte '.$suffix, 'hostName' => 'Hôte fictif '.$suffix, 'hostId' => $host, 'category' => $category, 'listedName' => $name, 'unlistedName' => 'Non listé fictif L10 '.$suffix, 'addedName' => 'Invité ajouté fictif '.$suffix, 'enrolCode' => $code, 'badges' => $badges->pluck('code')->all()], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
