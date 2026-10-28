<?php

namespace App\Console\Commands;

use App\Models\Badge;
use App\Models\User;
use App\Services\BadgeLots;
use App\Services\BadgeService;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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
        $s = Str::upper(Str::random(5));
        $now = now();
        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'name' => "Recette fictive du poste d’accueil {$s}", 'starts_at' => $now->copy()->subHour(), 'ends_at' => $now->copy()->addDays(2), 'created_at' => $now, 'updated_at' => $now]);
        $zones = [];
        foreach (['Hall d’accueil' => true, 'Salle principale' => false, 'Espace VIP' => false] as $name => $reception) {
            DB::table('zones')->insert(['id' => $zones[$name] = (string) Str::uuid(), 'event_id' => $event, 'name' => $name, 'est_accueil' => $reception, 'created_at' => $now, 'updated_at' => $now]);
        }
        $categories = [];
        foreach ([['VIP', '#C9962B', true, ['Hall d’accueil', 'Salle principale', 'Espace VIP']], ['Visiteur', '#0F5AA6', false, ['Hall d’accueil', 'Salle principale']]] as [$name, $color, $priority, $allowed]) {
            DB::table('categories')->insert(['id' => $categories[$name] = (string) Str::uuid(), 'event_id' => $event, 'name' => $name, 'color' => $color, 'priority_circuit' => $priority, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($allowed as $zone) {
                DB::table('category_zone')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'category_id' => $categories[$name], 'zone_id' => $zones[$zone], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $host = (string) Str::uuid();
        DB::table('hosts')->insert(['id' => $host, 'event_id' => $event, 'name' => "Hôte fictif {$s}", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('devices')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'name' => "Tablette recette {$s}", 'station' => "Poste recette {$s}", 'created_at' => $now, 'updated_at' => $now]);
        $visitor = function (string $name, string $category, ?string $from = null, ?string $until = null) use ($event, $host, $categories, $now): string {
            $id = (string) Str::uuid();
            DB::table('visitors')->insert(['id' => $id, 'event_id' => $event, 'full_name' => $name, 'organisation' => 'Organisation fictive de recette', 'host_id' => $host, 'category_id' => $categories[$category], 'valid_from' => $from ?? $now->copy()->subHour(), 'valid_until' => $until ?? $now->copy()->addDay(), 'source' => 'listé', 'status' => 'attendu', 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        };
        $names = ['listed' => "Aminata Dembélé {$s}", 'vip' => "Soro Prestige {$s}", 'refused' => "Yéo Refus {$s}", 'outside' => "Traoré Horsperiode {$s}", 'taken' => "Koffi Occupant {$s}", 'second' => "Bamba Second {$s}", 'badged' => "Diallo Déjàbadgé {$s}", 'scan' => "Ouattara Scanner {$s}", 'mobile' => "Konaté Mobile {$s}"];
        $ids = [
            'listed' => $visitor($names['listed'], 'Visiteur'), 'vip' => $visitor($names['vip'], 'VIP'), 'refused' => $visitor($names['refused'], 'Visiteur'),
            'outside' => $visitor($names['outside'], 'Visiteur', $now->copy()->subDays(3), $now->copy()->subDay()), 'taken' => $visitor($names['taken'], 'Visiteur'), 'second' => $visitor($names['second'], 'Visiteur'), 'badged' => $visitor($names['badged'], 'Visiteur'), 'scan' => $visitor($names['scan'], 'Visiteur'), 'mobile' => $visitor($names['mobile'], 'Visiteur'),
        ];
        $password = Str::password(24);
        $secret = (new Google2FA)->generateSecretKey();
        $agent = User::create(['name' => "Agent fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $password]);
        $agent->assignRole('agent_accueil');
        $admin = User::create(['name' => "Administrateur fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => Str::password(24), 'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => $now]);
        $admin->assignRole('administrateur');
        $lots = app(BadgeLots::class);
        $visitorBadges = Badge::where('badge_batch_id', $lots->generate($event, $categories['Visiteur'], 12, $admin)->id)->orderBy('code')->get();
        $vipBadges = Badge::where('badge_batch_id', $lots->generate($event, $categories['VIP'], 3, $admin)->id)->orderBy('code')->get();
        // Deux badges déjà remis : l'un à « Koffi Occupant » (refus « déjà associé »), l'autre à « Diallo Déjàbadgé » (visiteur déjà badgé).
        app(BadgeService::class)->associer($visitorBadges[0], $ids['taken'], $agent);
        app(BadgeService::class)->associer($visitorBadges[1], $ids['badged'], $agent);
        $this->line(json_encode([
            'email' => $agent->email, 'password' => $password, 'suffix' => $s, 'poste' => "Poste recette {$s}", 'names' => $names, 'ids' => $ids,
            'takenBadge' => $visitorBadges[0]->code, 'vipBadges' => $vipBadges->pluck('code'), 'freeBadges' => $visitorBadges->skip(3)->pluck('code')->values(),
            'qrSvg' => app(QrSvg::class)->render(QrSvg::payload((string) DB::table('badges')->where('id', $visitorBadges[2]->id)->value('qr_token')), 'QR du badge '.$visitorBadges[2]->code), 'qrBadge' => $visitorBadges[2]->code,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
