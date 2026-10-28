<?php

namespace App\Services\Poste;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Refus métier du poste d'accueil : `code` est stable pour l'interface, `action` propose la suite à l'agent. */
final class DeskRefused extends RuntimeException
{
    /** @var (\Closure(): void)|null Écriture à faire APRÈS l'annulation des écritures de l'opération refusée (alerte du superviseur). */
    private ?\Closure $afterRollback = null;

    public function __construct(public readonly string $code_, string $message, public readonly int $status = 422, public readonly ?string $action = null)
    {
        parent::__construct($message);
    }

    /** @param \Closure(): void $callback */
    public function afterRollback(\Closure $callback): self
    {
        $this->afterRollback = $callback;

        return $this;
    }

    public function runAfterRollback(): void
    {
        if ($this->afterRollback !== null) {
            ($this->afterRollback)();
        }
    }

    /** Hors d'une opération idempotente (lectures), le refus est rendu en JSON avec son statut. */
    public function render(): JsonResponse
    {
        return response()->json($this->toArray(), $this->status);
    }

    /** @return array{code:string,message:string,action:?string} */
    public function toArray(): array
    {
        return ['code' => $this->code_, 'message' => $this->getMessage(), 'action' => $this->action];
    }
}
