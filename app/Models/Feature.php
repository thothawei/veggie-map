<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    use HasFactory;

    /**
     * 固定清單，與 FeatureSeeder／前端 FEATURE_CODES 必須一致。查詢參數
     * `?takeout=1` 也用這份名單，不寫死在 Repository／FormRequest 兩處。
     *
     * @var list<string>
     */
    public const CODES = [
        'pet_friendly',
        'parking',
        'delivery',
        'takeout',
        'reservation',
        'wifi',
        'outdoor_seating',
        'family_friendly',
    ];

    public $timestamps = true;

    protected $fillable = [
        'code',
        'label',
    ];

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_features')
            ->withPivot('created_at');
    }

    /**
     * @return array<string, list<string>>
     */
    public static function booleanFilterRules(): array
    {
        $rules = [];

        foreach (self::CODES as $code) {
            $rules[$code] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * axios 會把布林序列化成 `"true"`，Laravel 的 boolean 規則不吃。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalizeBooleanInputs(array $input): array
    {
        $normalized = [];

        foreach (self::CODES as $code) {
            if (! array_key_exists($code, $input)) {
                continue;
            }

            $value = $input[$code];

            if ($value === 'true') {
                $normalized[$code] = '1';
            } elseif ($value === 'false') {
                $normalized[$code] = '0';
            }
        }

        return $normalized;
    }
}
