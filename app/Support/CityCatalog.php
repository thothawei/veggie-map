<?php

namespace App\Support;

/**
 * config/cities.php 的讀取端。目前只負責「座標落在哪個城市」→ 時區，
 * 讓 `open_now` 用該店所在地的當地時間判斷，而不是伺服器時間。
 */
final class CityCatalog
{
    /**
     * 落在多個 bbox 時取第一個命中的（現行城市 bbox 沒有重疊）。沒命中就回 fallback，
     * 不猜——時區猜錯的後果是「營業中」整批標反，比留空更糟。
     */
    public static function timezoneFor(float $latitude, float $longitude): string
    {
        foreach ((array) config('cities', []) as $city) {
            $bbox = self::parseBbox((string) ($city['bbox'] ?? ''));

            if ($bbox === null) {
                continue;
            }

            [$minLat, $minLng, $maxLat, $maxLng] = $bbox;

            if ($latitude >= $minLat && $latitude <= $maxLat && $longitude >= $minLng && $longitude <= $maxLng) {
                return (string) ($city['timezone'] ?? self::fallbackTimezone());
            }
        }

        return self::fallbackTimezone();
    }

    /**
     * open_now 的 SQL 要一次比完所有時區，所以需要知道「資料裡可能出現哪些時區」。
     *
     * @return list<string>
     */
    public static function timezones(): array
    {
        $zones = array_map(
            fn (array $city) => (string) ($city['timezone'] ?? self::fallbackTimezone()),
            (array) config('cities', []),
        );

        $zones[] = self::fallbackTimezone();

        return array_values(array_unique(array_filter($zones)));
    }

    public static function fallbackTimezone(): string
    {
        return (string) config('veggiemap.default_timezone', 'Asia/Taipei');
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function parseBbox(string $bbox): ?array
    {
        $parts = array_map('trim', explode(',', $bbox));

        if (count($parts) !== 4) {
            return null;
        }

        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);

        return [$minLat, $minLng, $maxLat, $maxLng];
    }
}
