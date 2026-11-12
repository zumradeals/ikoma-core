<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Zone extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_zone')->withPivot(['id', 'event_id'])->withTimestamps();
    }
}
