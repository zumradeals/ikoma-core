<?php

namespace App\Services;

/** Résultat d’un contrôle de badge. `reason` vaut null quand le badge est valide. */
final readonly class BadgeVerdict
{
    /**
     * @param  array{id:string,name:string}|null  $person
     * @param  array{id:string,name:string,color:string}|null  $category
     * @param  list<string>  $zones
     */
    public function __construct(
        public bool $valid,
        public ?string $reason,
        public string $message,
        public ?string $badgeId = null,
        public ?array $person = null,
        public ?array $category = null,
        public ?string $organisation = null,
        public array $zones = [],
    ) {}
}
