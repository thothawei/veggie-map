<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'verification_type',
        'score',
        'verified_by',
        'verified_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
