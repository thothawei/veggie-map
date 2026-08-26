<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一個曾經有效、現在已被換掉的 slug。見 migration 對這張表的說明。
 */
class RestaurantSlugAlias extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'restaurant_id',
        'slug',
    ];

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
