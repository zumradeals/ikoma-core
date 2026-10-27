<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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
        $password = Str::password(24);
        $secret = (new Google2FA)->generateSecretKey();
        $user = User::create(['name' => 'Administrateur fictif', 'email' => Str::uuid().'@exemple.test', 'password' => $password, 'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode([])), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('administrateur');
        $this->line(json_encode(['email' => $user->email, 'password' => $password, 'secret' => $secret], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
