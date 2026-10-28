<?php

namespace App\Models;

use App\Enums\BadgeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BadgeStatus $status
 */
class Badge extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['qr_token'];

    protected function casts(): array
    {
        return ['status' => BadgeStatus::class];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<BadgeBatch, $this> */
    public function badgeBatch(): BelongsTo
    {
        return $this->belongsTo(BadgeBatch::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function reservedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function reservedGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'reserved_group_id');
    }

    /** @return HasMany<BadgeAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(BadgeAssignment::class);
    }
}
