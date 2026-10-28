<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        $hostAccount = User::where('email', 'hote@exemple.test')->first();
        if ($hostAccount) {
            DB::table('hosts')->where('id', DemoSeeder::id('host0'))->whereNull('user_id')->update(['user_id' => $hostAccount->id]);
        }
    }
}
