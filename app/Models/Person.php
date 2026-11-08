<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identité durable d'une personne, indépendante de toute activité (événement, stage, visite). Ses participations sont les fiches `visitors` d'une activité.
 */
class Person extends Model
{
    use HasUuids;

    protected $table = 'persons';

    protected $guarded = ['id'];

    /** @return HasMany<Visitor, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(Visitor::class, 'person_id');
    }
}
