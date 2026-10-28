<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Double validation (audit global D2). Dans le profil de sécurité renforcé, une action critique n'est exécutée qu'après la validation d'un second
 * administrateur, distinct de l'auteur de la demande (contrainte aussi imposée par la base). Actions couvertes : création d'un compte privilégié,
 * changement de rôle vers ou depuis un rôle privilégié, réactivation d'un compte privilégié, retour au profil « standard ».
 * Tant qu'il y a moins de deux administrateurs actifs, la double validation ne peut pas s'appliquer : elle s'applique dès le deuxième.
 */
final class Approvals
{
    public const TTL_HOURS = 24;

    public const PRIVILEGED_ROLES = ['administrateur', 'superviseur'];

    public function __construct(private readonly Audit $audit) {}

    public static function privileged(?string $role): bool
    {
        return $role !== null && in_array($role, self::PRIVILEGED_ROLES, true);
    }

    /** La double validation est exigée : profil renforcé et au moins deux administrateurs actifs. */
    public function required(): bool
    {
        return SecurityProfile::strict() && ! $this->soleAdministrator();
    }

    public function soleAdministrator(): bool
    {
        return User::role('administrateur')->where('active', true)->count() < 2;
    }

    /** @param array<string, mixed> $payload */
    public function request(string $action, array $payload, string $summary, User $by): string
    {
        $id = (string) Str::uuid();
        DB::table('approvals')->insert(['id' => $id, 'action' => $action, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'summary' => mb_substr($summary, 0, 200), 'requested_by' => $by->id, 'expires_at' => now()->addHours(self::TTL_HOURS), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->append('approbation.demandee', $by->id, ['approval_id' => $id, 'action' => $action]);

        return $id;
    }

    /** @return Collection<int, \stdClass> */
    public function pending(): Collection
    {
        return DB::table('approvals as a')->join('users as u', 'u.id', '=', 'a.requested_by')->whereNull('a.decision')->where('a.expires_at', '>', now())
            ->orderBy('a.created_at')->get(['a.id', 'a.action', 'a.summary', 'a.requested_by', 'a.created_at', 'a.expires_at', 'u.name as requester']);
    }

    public function pendingCount(): int
    {
        return DB::table('approvals')->whereNull('decision')->where('expires_at', '>', now())->count();
    }

    public function decide(string $id, User $approver, bool $approve): void
    {
        DB::transaction(function () use ($id, $approver, $approve): void {
            $row = DB::table('approvals')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->decision !== null) {
                throw ValidationException::withMessages(['approbation' => 'Cette demande a déjà été traitée ou n’existe plus.']);
            }
            if ($row->expires_at < now()) {
                throw ValidationException::withMessages(['approbation' => 'Cette demande a expiré : demandez-la de nouveau.']);
            }
            if ($row->requested_by === $approver->id) {
                throw ValidationException::withMessages(['approbation' => 'Une demande doit être validée par un autre administrateur que son auteur.']);
            }
            $update = ['decided_by' => $approver->id, 'decided_at' => now(), 'decision' => $approve ? 'approuve' : 'refuse', 'updated_at' => now()];
            if ($approve) {
                $this->execute((string) $row->action, (array) json_decode((string) $row->payload, true), User::findOrFail($row->requested_by), $approver);
                $update['executed_at'] = now();
            }
            DB::table('approvals')->where('id', $id)->update($update);
            $this->audit->append($approve ? 'approbation.approuvee' : 'approbation.refusee', $approver->id, ['approval_id' => $id, 'action' => $row->action, 'demande_par' => $row->requested_by]);
        });
    }

    /** @param array<string, mixed> $p */
    private function execute(string $action, array $p, User $requester, User $approver): void
    {
        match ($action) {
            'compte.creer' => $this->createAccount($p, $requester, $approver),
            'compte.modifier' => $this->updateAccount($p, $approver),
            'securite.profil_standard' => $this->downgradeProfile($approver),
            default => throw ValidationException::withMessages(['approbation' => 'Action inconnue.']),
        };
    }

    /** @param array<string, mixed> $p */
    private function createAccount(array $p, User $requester, User $approver): void
    {
        if (User::where('email', strtolower((string) $p['email']))->exists()) {
            throw ValidationException::withMessages(['approbation' => 'Cette adresse électronique est déjà utilisée : la demande ne peut plus être exécutée.']);
        }
        $user = User::create(['name' => $p['name'], 'email' => strtolower((string) $p['email']), 'password' => $p['password_hash']]); // déjà une empreinte : jamais rehachée
        $user->assignRole((string) $p['role']);
        $this->audit->append('compte.cree', $approver->id, ['id' => $user->id, 'role' => $p['role'], 'demande_par' => $requester->id, 'double_validation' => true]);
    }

    /** @param array<string, mixed> $p */
    private function updateAccount(array $p, User $approver): void
    {
        $user = User::findOrFail($p['user_id']);
        if ($user->active !== (bool) $p['active']) {
            $user->update(['active' => (bool) $p['active']]);
            $this->audit->append($p['active'] ? 'compte.active' : 'compte.desactive', $approver->id, ['id' => $user->id, 'double_validation' => true]);
        }
        $old = $user->getRoleNames()->first();
        if ($old !== $p['role']) {
            $user->syncRoles([(string) $p['role']]);
            $this->audit->append('role.change', $approver->id, ['id' => $user->id, 'avant' => $old, 'apres' => $p['role'], 'double_validation' => true]);
        }
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function downgradeProfile(User $approver): void
    {
        DB::table('settings')->where('key', 'profil_securite')->update(['value' => SecurityProfile::STANDARD]);
        $this->audit->append('securite.profil_modifie', $approver->id, ['avant' => SecurityProfile::STRICT, 'apres' => SecurityProfile::STANDARD, 'double_validation' => true]);
    }
}
