<?php

namespace App\Services;

use RuntimeException;

/** Refus métier : le message est destiné à l’agent, `reason` est un code stable pour les écrans et les tests. */
final class BadgeRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
