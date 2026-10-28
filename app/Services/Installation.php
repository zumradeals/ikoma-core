<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Assistant d'initialisation (L12). Disponible une seule fois, sur un serveur de production, tant que : (1) scripts/install.sh a déposé un jeton à
 * usage unique, (2) l'installation n'est pas marquée terminée, (3) aucun administrateur n'existe. Il crée le premier administrateur (le 2FA lui est
 * imposé dès sa première connexion), le premier événement et sa zone d'accueil, puis se verrouille. Aucune donnée de démonstration.
 */
final class Installation
{
    public function __construct(private readonly Audit $audit) {}

    public function finished(): bool
    {
        return DB::table('settings')->where('key', 'installation_terminee')->value('value') === '1';
    }

    private function tokenFile(): ?string
    {
        $path = config('ikoma.install_token_file');

        return is_string($path) && $path !== '' && is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Un administrateur existe-t-il ? Requête directe : sur une base neuve, les rôles n'existent pas encore (ils sont créés par l'assistant),
     * et User::role() lèverait « le rôle n'existe pas ».
     */
    public function administratorExists(): bool
    {
        return DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('r.name', 'administrateur')->exists();
    }

    /** Vrai si l'assistant peut être utilisé maintenant. */
    public function available(): bool
    {
        return $this->tokenFile() !== null && ! $this->finished() && ! $this->administratorExists();
    }

    public function tokenMatches(string $given): bool
    {
        $file = $this->tokenFile();

        return $file !== null && $given !== '' && hash_equals(trim((string) file_get_contents($file)), trim($given));
    }

    /** @return list<array{label:string,ok:bool,critical:bool,help:string}> */
    public function checks(bool $secure): array
    {
        $db = true;
        $migrated = true;
        try {
            DB::selectOne('SELECT 1');
            $migrated = DB::getSchemaBuilder()->hasTable('users') && DB::getSchemaBuilder()->hasTable('events');
        } catch (\Throwable) {
            $db = false;
            $migrated = false;
        }

        return [
            ['label' => 'La base de données répond', 'ok' => $db, 'critical' => true, 'help' => 'Vérifiez que le conteneur de la base est démarré.'],
            ['label' => 'La base est à jour', 'ok' => $migrated, 'critical' => true, 'help' => 'Relancez install.sh : il applique les migrations.'],
            ['label' => 'Le dossier de stockage est inscriptible', 'ok' => is_writable(storage_path('app')), 'critical' => true, 'help' => 'Les photos et les imports y sont chiffrés ; sans lui, l’accueil ne peut pas fonctionner.'],
            ['label' => 'La clé de chiffrement est présente', 'ok' => (string) config('app.key') !== '', 'critical' => true, 'help' => 'Relancez install.sh : il génère la clé.'],
            ['label' => 'La connexion est chiffrée (HTTPS)', 'ok' => $secure, 'critical' => false, 'help' => 'Sans HTTPS, la caméra des tablettes et les mots de passe ne sont pas protégés. Configurez le certificat avant la mise en service.'],
            ['label' => 'Le serveur est en mode production', 'ok' => app()->environment('production'), 'critical' => false, 'help' => 'APP_ENV doit valoir production sur le serveur réel.'],
        ];
    }

    public function ready(bool $secure): bool
    {
        foreach ($this->checks($secure) as $check) {
            if ($check['critical'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{name:string,email:string,password:string,event_name:string,site:?string,starts_at:string,ends_at:string}  $data
     */
    public function install(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            // Deux requêtes simultanées : une seule peut passer.
            DB::table('settings')->where('key', 'installation_terminee')->lockForUpdate()->first();
            if ($this->finished() || $this->administratorExists()) {
                throw new \RuntimeException('L’installation est déjà terminée.');
            }
            (new RoleSeeder)->run();
            $admin = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $admin->assignRole('administrateur');
            $event = (string) Str::uuid();
            $now = now();
            DB::table('events')->insert(['id' => $event, 'name' => $data['event_name'], 'site' => $data['site'] ?: null, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'created_at' => $now, 'updated_at' => $now]);
            DB::table('zones')->insert(['id' => (string) Str::uuid(), 'event_id' => $event, 'name' => 'Accueil', 'est_accueil' => true, 'created_at' => $now, 'updated_at' => $now]);
            if (app()->environment('production')) {
                DB::table('settings')->where('key', 'sauvegarde_active')->update(['value' => '1']); // le suivi des sauvegardes commence avec la mise en service
            }
            DB::table('settings')->where('key', 'installation_terminee')->update(['value' => '1']);
            $this->audit->append('compte.cree', null, ['id' => $admin->id, 'role' => 'administrateur', 'source' => 'installation']);
            $this->audit->append('installation.terminee', null, ['event_id' => $event, 'administrateur_id' => $admin->id]);

            return $admin;
        });
    }

    /** Tentative d'accès alors que l'assistant n'est pas disponible : journalisée sans donnée personnelle (empreinte de l'adresse seulement). */
    public function logRefusedAccess(string $ip): void
    {
        $this->audit->append('installation.acces_refuse', null, ['empreinte_ip' => substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 16)]);
    }
}
