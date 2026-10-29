<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Console\Output\OutputInterface;

class ScreenshotAccount extends Command
{
    protected $signature = 'test:screen-account';

    protected $description = 'Prépare un compte fictif temporaire pour les captures automatisées';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('Commande réservée aux tests.');

            return self::FAILURE;
        }
        $this->callSilent('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        // Compte exclusivement fictif : chaîne alphanumérique longue, sans ambiguïté
        // d'encodage lors de sa transmission au navigateur de recette.
        $password = Str::random(32).'aA1!';
        $secret = (new Google2FA)->generateSecretKey();
        $user = User::create(['name' => 'Administrateur fictif', 'email' => Str::uuid().'@exemple.test', 'password' => $password, 'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('administrateur');

        // Détecter une incohérence de provisionnement AVANT de démarrer les E2E.
        // Aucun mot de passe ni secret n'est affiché en cas d'échec.
        if (! Hash::check($password, $user->fresh()->password)
            || ! Auth::validate(['email' => $user->email, 'password' => $password])
            || ! $user->fresh()->hasRole('administrateur')) {
            $this->error('Compte de recette administrateur invalide.');

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(['email' => $user->email, 'password' => $password, 'secret' => $secret], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
