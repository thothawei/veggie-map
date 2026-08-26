<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $score
 * @property string $verification_type
 * @property Carbon|null $verified_at
 * @property Carbon|null $expires_at 到期後這筆不再計入可信度
 *                                   （見 CalculateRestaurantScoreJob
 *                                   與 VerificationCatalog::breakdown）
 */
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
