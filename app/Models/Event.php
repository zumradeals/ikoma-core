<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Zone, $this> */
    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Visitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    /** @return HasMany<Badge, $this> */
    public function badges(): HasMany
    {
        return $this->hasMany(Badge::class);
    }

    /** @return HasMany<Host, $this> */
    public function hosts(): HasMany
    {
        return $this->hasMany(Host::class);
    }

    /** @return HasMany<Group, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<BadgeBatch, $this> */
    public function badgeBatches(): HasMany
    {
        return $this->hasMany(BadgeBatch::class);
    }
}
