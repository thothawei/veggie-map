<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantConfidenceScore extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $primaryKey = 'restaurant_id';

    public $incrementing = false;

    protected $fillable = [
        'restaurant_id',
        'score',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
