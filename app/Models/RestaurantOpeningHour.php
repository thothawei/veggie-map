<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 解析後的營業時段。0=週一 … 6=週日，時間是「距離該日 00:00 的分鐘數」，
 * 跨午夜已在 `App\Support\OpeningHours` 切成兩列（見該類別與 migration 註解）。
 */
class RestaurantOpeningHour extends Model
{
    protected $fillable = ['restaurant_id', 'day_of_week', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'opens_at' => 'integer',
            'closes_at' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** "09:30"。前端直接顯示，不要在 Vue 再算一次分鐘轉時間。 */
    public function formatOpensAt(): string
    {
        return self::formatMinutes($this->opens_at);
    }

    public function formatClosesAt(): string
    {
        return self::formatMinutes($this->closes_at);
    }

    public static function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
