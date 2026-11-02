<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\User;
use App\Services\CardLifecycle;
use App\Services\HolderCards;
use App\Services\HolderEntitlements;
use App\Services\InstitutionalCategories;
use App\Services\PermanentAccess;
use App\Services\PermanentHolders;
use App\Services\Poste\Devices;
use App\Services\Poste\SiteControl;
use App\Services\QrSvg;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Output\OutputInterface;

/** Données FICTIVES pour le parcours navigateur de la tablette et des titulaires permanents (recette A06). Réservé aux tests. */
class PermanentFixtures extends Command
{
    protected $signature = 'test:permanent-fixtures';

    protected $description = 'Prépare un événement fictif, une tablette et des titulaires permanents pour la recette navigateur A06';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('Commande réservée aux tests.');

            return self::FAILURE;
        }
        $this->callSilent('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $s = 'P'.Str::upper(Str::random(5));
        $now = now();
        $adminSecret = (new Google2FA)->generateSecretKey();
        $admin = User::create(['name' => "Administrateur fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $adminPassword = Str::password(24).'aA1!', 'two_factor_secret' => encrypt($adminSecret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => $now]);
        $admin->assignRole('administrateur');
        $agent = User::create(['name' => "Agent fictif {$s}", 'email' => Str::uuid().'@exemple.test', 'password' => $agentPassword = Str::password(24)]);
        $agent->assignRole('agent_accueil');

        $event = (string) Str::uuid();
        DB::table('events')->insert(['id' => $event, 'name' => "Recette titulaires permanents {$s}", 'starts_at' => $now->copy()->subHour(), 'ends_at' => $now->copy()->addDays(2), 'created_at' => $now, 'updated_at' => $now]);
        $hall = (string) Str::uuid();
        DB::table('zones')->insert(['id' => $hall, 'event_id' => $event, 'name' => "Hall titulaires {$s}", 'est_accueil' => true, 'created_at' => $now, 'updated_at' => $now]);
        $holders = app(HolderEntitlements::class);
        $holders->savePolicy($event, $hall, 'NEUTRE-TEST', true, true, $admin);
        $device = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device, 'event_id' => $event, 'name' => "Tablette permanents {$s}", 'station' => "Entrée permanents {$s}", 'created_at' => $now, 'updated_at' => $now]);
        $deviceModel = Device::findOrFail($device);

        app(InstitutionalCategories::class)->initialize($admin);
        app(PermanentHolders::class)->createPermanentContext($admin);
        app(PermanentAccess::class)->saveOfflineTrust(30, true, $admin);

        $registry = app(PermanentHolders::class);
        $cards = app(HolderCards::class);
        $make = function (string $label, bool $entitled) use ($registry, $cards, $holders, $admin, $event, $hall, $now, $s): array {
            $name = "{$label} Fictif {$s}";
            $id = $registry->create(['full_name' => $name, 'organisation' => 'Organisation fictive', 'institutional_category' => 'AILCT-02'], $admin);
            $cards->issue($id, $admin);
            if ($entitled) {
                $holders->grant($id, $event, $hall, $now->copy()->subHour()->toIso8601String(), $now->copy()->addDay()->toIso8601String(), $admin);
            }
            $token = (string) DB::table('holder_cards as k')->join('badges as b', 'b.id', '=', 'k.badge_id')->where('k.holder_id', $id)->where('k.status', 'active')->value('b.qr_token');

            return ['id' => $id, 'name' => $name, 'identifier' => (string) DB::table('permanent_holders')->where('id', $id)->value('identifier'), 'qr' => QrSvg::payload($token)];
        };
        $valid = $make('Habilité', true);
        $none = $make('Sansdroit', false);
        $revoked = $make('Revoque', true);
        app(CardLifecycle::class)->revoke($revoked['id'], 'perdue', null, $admin);
        $conflict = $make('Conflit', true);
        // Titulaire déjà présent dont la carte est ensuite déclarée volée : sa sortie doit toujours être possible, avec une alerte.
        $leaver = $make('Sortant', true);
        app(SiteControl::class)->control($deviceModel, $agent, $leaver['qr'], null);
        app(CardLifecycle::class)->revoke($leaver['id'], 'volee', null, $admin);

        $enrolCode = app(Devices::class)->issueCode($deviceModel, $admin);
        DB::table('devices')->where('id', $device)->update(['enrollment_expires_at' => now()->addHours(6)]);
        $this->output->writeln(json_encode([
            'agent' => ['email' => $agent->email, 'password' => $agentPassword], 'admin' => ['email' => $admin->email, 'password' => $adminPassword, 'secret' => $adminSecret],
            'enrolCode' => $enrolCode, 'event' => ['id' => $event, 'name' => "Recette titulaires permanents {$s}"], 'hall' => "Hall titulaires {$s}", 'poste' => "Entrée permanents {$s}",
            'holders' => compact('valid', 'none', 'revoked', 'conflict', 'leaver'),
        ], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
