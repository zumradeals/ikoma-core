<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class DemoSeeder extends Seeder
{
    public static function id(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://ikoma.example/demo/'.$key)->toString();
    }

    public function run(): void
    {
        DB::transaction(function (): void {
            $event = self::id('event');
            if (DB::table('events')->where('id', $event)->exists()) {
                return;
            }
            $now = now();
            $dates = ['created_at' => $now, 'updated_at' => $now];
            DB::table('events')->insert(['id' => $event, 'name' => 'Exercice fictif IKOMA — démonstration', 'starts_at' => '2027-01-10 08:00:00+00', 'ends_at' => '2027-01-10 20:00:00+00', 'site' => 'Site fictif de démonstration', 'status' => null, ...$dates]);
            $zones = ['Accueil', 'Salle principale', 'Espace VIP', 'Espace technique', 'Cour', 'Espace stagiaires'];
            foreach ($zones as $i => $name) {
                DB::table('zones')->insert(['id' => self::id('zone'.$i), 'event_id' => $event, 'name' => $name, ...$dates]);
            }
            $cats = [['VIP', '#C9962B', true, [0, 1, 2]], ['Visiteur', '#0F5AA6', false, [0, 1, 4]], ['Prestataire', '#F26B21', false, [0, 3, 4]], ['Stagiaire', '#1E9E5A', false, [0, 4, 5]]];
            foreach ($cats as $i => [$name,$color,$priority,$allowed]) {
                DB::table('categories')->insert(['id' => self::id('category'.$i), 'event_id' => $event, 'name' => $name, 'color' => $color, 'priority_circuit' => $priority, ...$dates]);
                foreach ($allowed as $zone) {
                    DB::table('category_zone')->insert(['id' => self::id('pivot'.$i.'-'.$zone), 'event_id' => $event, 'category_id' => self::id('category'.$i), 'zone_id' => self::id('zone'.$zone), ...$dates]);
                }
            }
            for ($i = 0; $i < 3; $i++) {
                DB::table('hosts')->insert(['id' => self::id('host'.$i), 'event_id' => $event, 'name' => 'Hôte fictif '.($i + 1), 'service' => 'Service fictif '.($i + 1), 'user_id' => null, ...$dates]);
            }
            DB::table('devices')->insert(['id' => self::id('device'), 'event_id' => $event, 'name' => 'Tablette fictive A', 'station' => 'Poste fictif A', 'token_hash' => null, ...$dates]);
            DB::table('badge_batches')->insert(['id' => self::id('batch'), 'event_id' => $event, 'name' => 'Lot fictif de 400 badges', 'quantity' => 400, 'margin' => 100, ...$dates]);
            DB::table('groups')->insert(['id' => self::id('group'), 'event_id' => $event, 'name' => 'Groupe fictif de stagiaires', 'bus_number' => 'Car fictif 1', 'supervisor_id' => DB::table('users')->where('email', 'encadreur@exemple.test')->value('id'), 'badge_batch_id' => self::id('batch'), ...$dates]);
            $names = ['Yao Brou', 'Yao Konan', 'Koné Mariam', 'Aimé Traoré', 'Yao Brou', 'Koné Mariam', 'Yao Konan', 'Yao Brou'];
            $visitors = [];
            $members = [];
            for ($i = 0; $i < 300; $i++) {
                $group = $i >= 260 ? self::id('group') : null;
                $visitors[] = ['id' => self::id('visitor'.$i), 'event_id' => $event, 'full_name' => $names[$i] ?? 'Visiteur fictif '.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'organisation' => 'Organisation fictive '.($i % 12 + 1), 'host_id' => self::id('host'.($i % 3)), 'category_id' => self::id('category'.($group ? 3 : $i % 3)), 'group_id' => $group, 'valid_from' => '2027-01-10 08:00:00+00', 'valid_until' => '2027-01-10 20:00:00+00', 'source' => 'listé', 'status' => null, ...$dates];
                if ($group) {
                    $members[] = ['id' => self::id('member'.$i), 'group_id' => $group, 'visitor_id' => self::id('visitor'.$i), ...$dates];
                }
            }
            DB::table('visitors')->insert($visitors);
            DB::table('group_members')->insert($members);
            $badges = [];
            for ($i = 1; $i <= 400; $i++) {
                $badges[] = ['id' => self::id('badge'.$i), 'event_id' => $event, 'code' => 'B-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'qr_token' => Str::random(64), 'status' => 'Disponible', 'badge_batch_id' => self::id('batch'), 'reserved_device_id' => $i <= 60 ? self::id('device') : null, ...$dates];
            }
            DB::table('badges')->insert($badges);
        });
    }
}
