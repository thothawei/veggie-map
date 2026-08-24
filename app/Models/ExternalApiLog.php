<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalApiLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'endpoint',
        'status',
        'response_time_ms',
        'success',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'response_time_ms' => 'integer',
            'success' => 'boolean',
        ];
    }
}
