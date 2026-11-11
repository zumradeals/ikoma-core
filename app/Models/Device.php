<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $point_code
 * @property string|null $event_id
 * @property string|null $checkpoint_id
 */
class Device extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Badge, $this> */
    public function reservedBadges(): HasMany
    {
        return $this->hasMany(Badge::class, 'reserved_device_id');
    }
}
