<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        foreach (array_keys(config('roles')) as $role) {
            $email = $role.'@exemple.test';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $password = Str::password(24);
            $user = User::create(['name' => 'Compte fictif '.$role, 'email' => $email, 'password' => $password]);
            $user->assignRole($role);
            app(Audit::class)->append('compte.cree', null, ['id' => $user->id, 'role' => $role, 'source' => 'seed']);
            $this->command?->warn($email.' — mot de passe affiché une seule fois : '.$password);
        }
        $this->call(DemoSeeder::class);
    }
}
