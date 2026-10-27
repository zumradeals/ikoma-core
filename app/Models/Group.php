<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BadgeBatch, $this> */
    public function badgeBatch(): BelongsTo
    {
        return $this->belongsTo(BadgeBatch::class);
    }

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return HasMany<Visitor, $this> */
    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }
}
