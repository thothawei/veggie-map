<?php

namespace App\Support;

use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use Carbon\CarbonImmutable;

/**
 * 依「該店當地時間」把解析後的時段換算成畫面要的三態。
 *
 * 三態而不是布林：`unknown` 是真實且大量存在的答案（OSM 多數餐廳沒填
 * opening_hours），把它壓成 false 會讓使用者以為店家打烊了。API 回三態，
 * 前端才有辦法誠實顯示「營業時間未知」。
 */
final class OpeningStatus
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const UNKNOWN = 'unknown';

    /** @var array<int, string> 0=週一 … 6=週日 */
    public const DAY_LABELS = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];

    /**
     * @return array{status: string, open_now: bool|null, closes_at: string|null, opens_at: string|null}
     */
    public static function for(Restaurant $restaurant): array
    {
        $hours = $restaurant->openingHours;

        if ($hours->isEmpty()) {
            return ['status' => self::UNKNOWN, 'open_now' => null, 'closes_at' => null, 'opens_at' => null];
        }

        $local = CarbonImmutable::now()->setTimezone(
            $restaurant->timezone ?: CityCatalog::fallbackTimezone(),
        );
        $day = $local->dayOfWeekIso - 1;
        $minutes = $local->hour * 60 + $local->minute;

        $today = $hours->where('day_of_week', $day)->sortBy('opens_at');

        foreach ($today as $slot) {
            if ($slot->opens_at <= $minutes && $slot->closes_at > $minutes) {
                return [
                    'status' => self::OPEN,
                    'open_now' => true,
                    'closes_at' => RestaurantOpeningHour::formatMinutes($slot->closes_at),
                    'opens_at' => null,
                ];
            }
        }

        // 還沒開：給今天接下來最近的一段，讓卡片能顯示「17:00 開始營業」。
        $next = $today->firstWhere(fn (RestaurantOpeningHour $slot) => $slot->opens_at > $minutes);

        return [
            'status' => self::CLOSED,
            'open_now' => false,
            'closes_at' => null,
            'opens_at' => $next ? RestaurantOpeningHour::formatMinutes($next->opens_at) : null,
        ];
    }

    /**
     * 詳情頁的一週時間表。沒有時段的日子留空陣列（＝公休），呼叫端自己決定文案。
     *
     * @return list<array{day: int, label: string, ranges: list<string>}>
     */
    public static function week(Restaurant $restaurant): array
    {
        $hours = $restaurant->openingHours;

        if ($hours->isEmpty()) {
            return [];
        }

        return array_map(function (int $day) use ($hours) {
            $ranges = $hours->where('day_of_week', $day)
                ->sortBy('opens_at')
                ->map(fn (RestaurantOpeningHour $slot) => $slot->formatOpensAt().'–'.$slot->formatClosesAt())
                ->values()
                ->all();

            return ['day' => $day, 'label' => self::DAY_LABELS[$day], 'ranges' => $ranges];
        }, range(0, 6));
    }
}
