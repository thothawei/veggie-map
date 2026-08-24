<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DietType extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $fillable = [
        'code',
        'label',
    ];

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_diet_types')
            ->withPivot('created_at');
    }
}
