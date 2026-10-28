<?php

namespace App\Console\Commands;

use App\Models\Badge;
use App\Models\Device;
use App\Models\Group;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\Groups;
use App\Services\Poste\Devices;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Output\OutputInterface;

class DeskFixtures extends Command
{
    protected $signature = 'test:desk-fixtures';

    protected $description = 'Prépare un événement fictif et un compte agent pour les parcours automatisés du poste d’accueil';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('Commande réservée aux tests.');

            return self::FAILURE;
        }
        $this->callSilent('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $s = 'R'.Str::upper(Str::random(5));
        $now = now();
        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'name' => "Recette fictive du poste d’accueil {$s}", 'starts_at' => $now->copy()->subHour(), 'ends_at' => $now->copy()->addDays(2), 'created_at' => $now, 'updated_at' => $now]);
        $zones = [];
        foreach (['Hall d’accueil' => true, 'Salle principale' => false, 'Espace VIP' => false] as $name => $reception) {
            DB::table('zones')->insert(['id' => $zones[$name] = (string) Str::uuid(), 'event_id' => $event, 'name' => $name, 'est_accueil' => $reception, 'created_at' => $now, 'updated_at' => $now]);
        }
        $categories = [];
        foreach ([['VIP', '#C9962B', true, ['Hall d’accueil', 'Salle principale', 'Espace VIP']], ['Visiteur', '#0F5AA6', false, ['Hall d’accueil', 'Salle principale']], ['Stagiaire', '#1E9E5A', false, ['Hall d’accueil', 'Salle principale']]] as [$name, $color, $priority, $allowed]) {
            DB::table('categories')->insert(['id' => $categories[$name] = (string) Str::uuid(), 'event_id' => $event, 'name' => $name, 'color' => $color, 'priority_circuit' => $priority, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($allowed as $zone) {
                DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'category_id' => $categories[$name], 'zone_id' => $zones[$zone], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $host = (string) Str::uuid();
        DB::table('hosts')->insert(['id' => $host, 'event_id' => $event, 'name' => "Hôte fictif {$s}", 'created_at' => $now, 'updated_at' => $now]);
        $device = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device, 'event_id' => $event, 'name' => "Tablette recette {$s}", 'station' => "Poste recette {$s}", 'created_at' => $now, 'updated_at' => $now]);
        // Deuxième tablette (accueil + contrôle sur site) : son propre lot de badges réservé, utilisée par le parcours « contrôle » (L7).
        $device2 = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device2, 'event_id' => $event, 'name' => "Tablette contrôle {$s}", 'station' => "Poste contrôle {$s}", 'created_at' => $now, 'updated_at' => $now]);
        // Troisième tablette, réservée au parcours du superviseur (L8) : son propre lot de badges et son code d'enrôlement.
        $device3 = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device3, 'event_id' => $event, 'name' => "Tablette supervision {$s}", 'station' => "Poste supervision {$s}", 'created_at' => $now, 'updated_at' => $now]);
        $visitor = function (string $name, string $category, ?string $from = null, ?string $until = null) use ($event, $host, $categories, $now): string {
            $id = (string) Str::uuid();
            DB::table('visitors')->insert(['id' => $id, 'event_id' => $event, 'full_name' => $name, 'organisation' => 'Organisation fictive de recette', 'host_id' => $host, 'category_id' => $categories[$category], 'valid_from' => $from ?? $now->copy()->subHour(), 'valid_until' => $until ?? $now->copy()->addDay(), 'source' => 'listé', 'status' => 'attendu', 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        };
        // Trente visiteurs de plus pour le parcours hors ligne en rafale.
        $bulk = [];
        for ($i = 1; $i <= 30; $i++) {
            $bulk[] = sprintf('Rafale Fictif %02d %s', $i, $s);
            $visitor(end($bulk), 'Visiteur');
        }
        $names = ['listed' => "Aminata Dembélé {$s}", 'vip' => "Soro Prestige {$s}", 'refused' => "Yéo Refus {$s}", 'outside' => "Traoré Horsperiode {$s}", 'taken' => "Koffi Occupant {$s}", 'second' => "Bamba Second {$s}", 'badged' => "Diallo Déjàbadgé {$s}", 'scan' => "Ouattara Scanner {$s}", 'mobile' => "Konaté Mobile {$s}"];
        $ids = [
            'listed' => $visitor($names['listed'], 'Visiteur'), 'vip' => $visitor($names['vip'], 'VIP'), 'refused' => $visitor($names['refused'], 'Visiteur'),
            'outside' => $visitor($names['outside'], 'Visiteur', $now->copy()->subDays(3), $now->copy()->subDay()), 'taken' => $visitor($names['taken'], 'Visiteur'), 'second' => $visitor($names['second'], 'Visiteur'), 'badged' => $visitor($names['badged'], 'Visiteur'), 'scan' => $visitor($names['scan'], 'Visiteur'), 'mobile' => $visitor($names['mobile'], 'Visiteur'),
        ];
        $controlNames = [];
        for ($i = 1; $i <= 6; $i++) {
            $controlNames[] = sprintf('Contrôle Fictif %02d %s', $i, $s);
            $visitor(end($controlNames), 'Visiteur');
        }
        $pilotageNames = [];
        for ($i = 1; $i <= 6; $i++) {
            $pilotageNames[] = sprintf('Pilotage Fictif %02d %s', $i, $s);
            $visitor(end($pilotageNames), 'Visiteur');
        }
        $password = Str::password(24);
        $secret = (new Google2FA)->generateSecretKey();
        $agent = User::create(['name' => "Agent fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $password]);
        $agent->assignRole('agent_accueil');
        $admin = User::create(['name' => "Administrateur fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $adminPassword = Str::password(24).'aA1!', 'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => $now]);
        $admin->assignRole('administrateur');
        $lots = app(BadgeLots::class);
        $deviceModel = Device::findOrFail($device);
        $visitorBatch = $lots->generate($event, $categories['Visiteur'], 50, $admin);
        $vipBatch = $lots->generate($event, $categories['VIP'], 3, $admin);
        // La tablette ne reçoit que les badges qui lui sont réservés : tout le stock de la recette lui est réservé.
        foreach ([$visitorBatch, $vipBatch] as $batch) {
            $numbers = Badge::where('badge_batch_id', $batch->id)->pluck('code')->map(fn (string $code) => (int) substr($code, 2));
            $lots->reserve($batch->id, $numbers->min(), $numbers->max(), $deviceModel, $admin);
        }
        $controlBatch = $lots->generate($event, $categories['Visiteur'], 30, $admin);
        $numbers2 = Badge::where('badge_batch_id', $controlBatch->id)->pluck('code')->map(fn (string $code) => (int) substr($code, 2));
        $lots->reserve($controlBatch->id, $numbers2->min(), $numbers2->max(), Device::findOrFail($device2), $admin);
        $controlBadges = Badge::where('badge_batch_id', $controlBatch->id)->orderBy('code')->get();
        $pilotageBatch = $lots->generate($event, $categories['Visiteur'], 20, $admin);
        $numbers3 = Badge::where('badge_batch_id', $pilotageBatch->id)->pluck('code')->map(fn (string $code) => (int) substr($code, 2));
        $lots->reserve($pilotageBatch->id, $numbers3->min(), $numbers3->max(), Device::findOrFail($device3), $admin);
        $pilotageBadges = Badge::where('badge_batch_id', $pilotageBatch->id)->orderBy('code')->get();
        $supervisorPassword = Str::password(24).'aA1!';
        $supervisorSecret = (new Google2FA)->generateSecretKey();
        $supervisor = User::create(['name' => "Superviseur fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $supervisorPassword, 'two_factor_secret' => encrypt($supervisorSecret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => $now]);
        $supervisor->assignRole('superviseur');
        $visitorBadges = Badge::where('badge_batch_id', $visitorBatch->id)->orderBy('code')->get();
        $vipBadges = Badge::where('badge_batch_id', $vipBatch->id)->orderBy('code')->get();
        // Deux badges déjà remis : l'un à « Koffi Occupant » (refus « déjà associé »), l'autre à « Diallo Déjàbadgé » (visiteur déjà badgé).
        app(BadgeService::class)->associer($visitorBadges[0], $ids['taken'], $agent, $deviceModel);
        app(BadgeService::class)->associer($visitorBadges[1], $ids['badged'], $agent, $deviceModel);
        // Code d'enrôlement de la tablette de recette : en recette seulement, sa validité est prolongée pour couvrir tout le parcours.
        $enrolCode = app(Devices::class)->issueCode($deviceModel, $admin);
        $enrolCode2 = app(Devices::class)->issueCode(Device::findOrFail($device2), $admin);
        $enrolCode3 = app(Devices::class)->issueCode(Device::findOrFail($device3), $admin);
        DB::table('devices')->where('id', $device3)->update(['enrollment_expires_at' => now()->addHours(6)]);
        DB::table('devices')->where('id', $device2)->update(['enrollment_expires_at' => now()->addHours(6)]);
        DB::table('devices')->where('id', $device)->update(['enrollment_expires_at' => now()->addHours(6)]);
        // Lot L9 : tablette des cars, encadreur et quatre groupes (50 stagiaires, absents, hors ligne, arrivée avec écart).
        $device4 = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device4, 'event_id' => $event, 'name' => "Tablette cars {$s}", 'station' => "Poste cars {$s}", 'created_at' => $now, 'updated_at' => $now]);
        $encadreurPassword = Str::password(24).'aA1!';
        $encadreur = User::create(['name' => "Encadreur fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $encadreurPassword]);
        $encadreur->assignRole('encadreur');
        $encadreur2 = User::create(['name' => "Encadreur voisin {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24).'aA1!']);
        $encadreur2->assignRole('encadreur');
        // Encadreur du car 2 : ses confirmations se font en direct pendant le parcours (les cars 1 et 3 sont déjà confirmés).
        $liveEncadreurPassword = Str::password(24).'aA1!';
        $liveEncadreur = User::create(['name' => "Encadreur direct {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $liveEncadreurPassword]);
        $liveEncadreur->assignRole('encadreur');
        $groups = app(Groups::class);
        $deviceCars = Device::findOrFail($device4);
        $cars = [];
        foreach ([[1, 50], [2, 6], [3, 8], [4, 6]] as [$n, $size]) {
            $group = $groups->create($event, "Groupe stagiaires {$n} {$s}", "Car {$n}", (match ($n) {
                2 => $liveEncadreur, 4 => $encadreur2, default => $encadreur
            })->id, $admin);
            $members = [];
            for ($i = 1; $i <= $size; $i++) {
                $members[] = $name = sprintf('Stagiaire C%d %02d %s', $n, $i, $s);
                $id = $visitor($name, 'Stagiaire');
                $groups->attach($group, $id, $admin);
            }
            $batch = $lots->generate($event, $categories['Stagiaire'], $size, $admin);
            $codes = Badge::where('badge_batch_id', $batch->id)->pluck('code')->map(fn (string $code) => (int) substr($code, 2));
            $lots->reserve($batch->id, $codes->min(), $codes->max(), Group::findOrFail($group), $admin);
            $badges = Badge::where('badge_batch_id', $batch->id)->orderBy('code')->limit($size)->get();
            $cars[$n] = ['id' => $group, 'name' => "Groupe stagiaires {$n} {$s}", 'bus' => "Car {$n}", 'members' => $members, 'ids' => DB::table('visitors')->where('group_id', $group)->orderBy('full_name')->pluck('id')->all(), 'badges' => $badges->pluck('code')->values()->all(), 'qr' => $badges->mapWithKeys(fn ($b) => [$b->code => app(QrSvg::class)->render(QrSvg::payload((string) DB::table('badges')->where('id', $b->id)->value('qr_token')), 'QR du badge '.$b->code)])->all()];
        }
        // Car 1 : l'encadreur a déjà confirmé les 50 personnes (le parcours mesure l'appel et la remise). Car 3 : confirmé aussi, pour le scénario hors ligne.
        foreach ([1, 3] as $n) {
            foreach (DB::table('visitors')->where('group_id', $cars[$n]['id'])->pluck('id') as $vid) {
                $groups->confirm($encadreur, $cars[$n]['id'], $vid);
            }
        }
        // Car 4, déjà parti : cinq embarqués (badge remis avant la fermeture), un sixième badgé après coup (badge scanné à l'arrivée sans être embarqué).
        $car4 = DB::table('visitors')->where('group_id', $cars[4]['id'])->orderBy('full_name')->pluck('id')->all();
        foreach ($car4 as $i => $vid) {
            $groups->confirm($encadreur2, $cars[4]['id'], $vid);
            $groups->check($agent, $cars[4]['id'], $vid);
            if ($i < 5) {
                $groups->deliver($deviceCars, $agent, $cars[4]['id'], $vid, $cars[4]['badges'][$i]);
            }
        }
        $groups->depart($agent, $cars[4]['id']);
        app(BadgeService::class)->associer(Badge::where('event_id', $event)->where('code', $cars[4]['badges'][5])->firstOrFail(), $car4[5], $agent, $deviceCars);
        $enrolCode4 = app(Devices::class)->issueCode($deviceCars, $admin);
        DB::table('devices')->where('id', $device4)->update(['enrollment_expires_at' => now()->addHours(6)]);
        // Un agent distinct pour les cars (la tablette est réservée à l'agent qui l'enrôle).
        $carAgentPassword = Str::password(24);
        $carAgent = User::create(['name' => "Agent cars {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $carAgentPassword]);
        $carAgent->assignRole('agent_accueil');
        $this->output->writeln(json_encode([
            'cars' => $cars, 'enrolCode4' => $enrolCode4, 'posteCars' => "Poste cars {$s}", 'encadreur' => ['email' => $encadreur->email, 'password' => $encadreurPassword], 'liveEncadreur' => ['email' => $liveEncadreur->email, 'password' => $liveEncadreurPassword], 'carAgent' => ['email' => $carAgent->email, 'password' => $carAgentPassword],
            'enrolCode' => $enrolCode, 'enrolCode2' => $enrolCode2, 'enrolCode3' => $enrolCode3, 'poste3' => "Poste supervision {$s}", 'pilotageNames' => $pilotageNames, 'pilotageBadges' => $pilotageBadges->pluck('code')->values(), 'supervisor' => ['email' => $supervisor->email, 'password' => $supervisorPassword, 'secret' => $supervisorSecret], 'poste2' => "Poste contrôle {$s}", 'controlNames' => $controlNames, 'controlBadges' => $controlBadges->pluck('code')->values(), 'controlQrSvg' => app(QrSvg::class)->render(QrSvg::payload((string) DB::table('badges')->where('id', $controlBadges[0]->id)->value('qr_token')), 'QR du badge '.$controlBadges[0]->code), 'zonesNames' => array_keys($zones), 'admin' => ['email' => $admin->email, 'password' => $adminPassword, 'secret' => $secret], 'bulk' => $bulk, 'bulkPrefix' => 'Rafale Fictif',
            'email' => $agent->email, 'password' => $password, 'suffix' => $s, 'poste' => "Poste recette {$s}", 'names' => $names, 'ids' => $ids,
            'takenBadge' => $visitorBadges[0]->code, 'vipBadges' => $vipBadges->pluck('code'), 'freeBadges' => $visitorBadges->skip(3)->pluck('code')->values(),
            'qrSvg' => app(QrSvg::class)->render(QrSvg::payload((string) DB::table('badges')->where('id', $visitorBadges[2]->id)->value('qr_token')), 'QR du badge '.$visitorBadges[2]->code), 'qrBadge' => $visitorBadges[2]->code,
        ], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
