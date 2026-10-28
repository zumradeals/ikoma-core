<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonImmutable|null $withdrawn_at */
class Visitor extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['withdrawn_at' => 'immutable_datetime', 'valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Host, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(Host::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /** @return HasMany<BadgeAssignment, $this> */
    public function badgeAssignments(): HasMany
    {
        return $this->hasMany(BadgeAssignment::class);
    }

    /** @return HasMany<IdentityCheck, $this> */
    public function identityChecks(): HasMany
    {
        return $this->hasMany(IdentityCheck::class);
    }

    /** @return HasMany<Photo, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** @return HasMany<AccessEvent, $this> */
    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    /** @return HasMany<Refusal, $this> */
    public function refusals(): HasMany
    {
        return $this->hasMany(Refusal::class);
    }
}
