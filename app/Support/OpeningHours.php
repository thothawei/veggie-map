<?php

namespace App\Support;

/**
 * OSM `opening_hours` 標籤的**子集**解析器。
 *
 * 為什麼是子集：完整的 opening_hours 規格（月份區間、週序、日出日落、節慶、
 * 例外日期）是一套獨立的小語言，為了一個「營業中」篩選去實作整套不划算，而且
 * 錯解比不解更糟——會把打烊的店標成營業中。所以這裡只認最常見的形式，其餘一律
 * 回傳 null（＝「這家店的營業時間我們沒有可信資料」），由呼叫端誠實顯示，
 * 不猜、不用預設值填空。
 *
 * 支援：
 *   24/7
 *   Mo-Fr 11:00-14:00,17:00-21:00
 *   Mo,We,Fr 09:00-18:00; Sa 10:00-14:00; Su off
 *   11:00-21:00                （沒有星期＝每天）
 *   Mo-Su 17:00-02:00          （跨午夜，切成兩段）
 *   PH off / PH 09:00-12:00    （節慶規則直接忽略，不影響其他規則）
 *
 * 不支援（整串視為無法解析）：
 *   Apr-Oct ...、week 1-53、sunrise-sunset、Mo[1] ...、"by appointment"、09:00+
 */
final class OpeningHours
{
    /** OSM 的星期縮寫 → 0=週一 … 6=週日（與 Carbon 的 ISO-8601 dayOfWeek-1 對齊）。 */
    private const DAYS = ['Mo' => 0, 'Tu' => 1, 'We' => 2, 'Th' => 3, 'Fr' => 4, 'Sa' => 5, 'Su' => 6];

    /** 出現任何一個就代表用到子集外的語法，寧可整串放棄。 */
    private const UNSUPPORTED = [
        'sunrise', 'sunset', 'dawn', 'dusk', 'week ', 'easter', 'open', 'unknown',
        'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
    ];

    /**
     * @return list<array{day: int, opens_at: int, closes_at: int}>|null
     *                                                                   null＝無法解析；空陣列＝解析成功但全天候公休（例如 "Mo-Su off"）。
     *                                                                   opens_at／closes_at 是「距離該日 00:00 的分鐘數」，closes_at 上限 1440，
     *                                                                   跨午夜的區間已經在這裡切成兩段，SQL 端就不必再處理跨日比較。
     */
    public static function parse(?string $raw): ?array
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $value) ?? '';
        $lower = mb_strtolower($normalized);

        if ($lower === '24/7') {
            return self::allDay();
        }

        foreach (self::UNSUPPORTED as $token) {
            if (str_contains($lower, $token)) {
                return null;
            }
        }

        // 中括號＝週序（Mo[1]）、引號＝自由文字說明，兩者都不在子集內。
        if (str_contains($normalized, '[') || str_contains($normalized, '"')) {
            return null;
        }

        /** @var array<int, list<array{0: int, 1: int}>> $byDay */
        $byDay = [];
        $matchedAnyRule = false;

        foreach (explode(';', $normalized) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            // 節慶／學期規則整條跳過：我們沒有節慶行事曆，套用它只會產生錯的答案。
            // 跳過不算失敗——"Mo-Fr 09:00-17:00; PH off" 的平日時段仍然可用。
            if (preg_match('/\b(PH|SH)\b/', $rule) === 1) {
                $matchedAnyRule = true;

                continue;
            }

            $parsed = self::parseRule($rule);

            if ($parsed === null) {
                return null;
            }

            $matchedAnyRule = true;
            [$days, $intervals] = $parsed;

            foreach ($days as $day) {
                // OSM 語意是「後面的規則覆蓋前面的」，所以整天重設而不是累加。
                $byDay[$day] = $intervals;
            }
        }

        if (! $matchedAnyRule) {
            return null;
        }

        return self::flatten($byDay);
    }

    /**
     * 解析單一規則，回傳 [適用的星期, 該日的時段（已含跨午夜切段標記）]。
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     */
    private static function parseRule(string $rule): ?array
    {
        $days = range(0, 6);
        $timePart = $rule;

        if (preg_match('/^((?:Mo|Tu|We|Th|Fr|Sa|Su)(?:[-,](?:Mo|Tu|We|Th|Fr|Sa|Su))*)\s*(.*)$/', $rule, $m) === 1) {
            $days = self::parseDays($m[1]);
            $timePart = trim($m[2]);

            if ($days === null) {
                return null;
            }
        }

        $lower = mb_strtolower($timePart);

        if ($lower === 'off' || $lower === 'closed') {
            return [$days, []];
        }

        // 只有星期沒有時間（"Mo-Fr"）＝沒說幾點到幾點，不能當成全天營業。
        if ($timePart === '') {
            return null;
        }

        $intervals = [];

        foreach (explode(',', $timePart) as $span) {
            $span = trim($span);

            if (preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $span, $t) !== 1) {
                return null;
            }

            $opens = ((int) $t[1]) * 60 + (int) $t[2];
            $closes = ((int) $t[3]) * 60 + (int) $t[4];

            if ($opens > 1440 || $closes > 1440 || (int) $t[2] > 59 || (int) $t[4] > 59) {
                return null;
            }

            $intervals[] = [$opens, $closes];
        }

        return [$days, $intervals];
    }

    /**
     * "Mo-Fr"／"Mo,We,Fr"／"Sa-Su" → [0,1,2,3,4]。
     *
     * @return list<int>|null
     */
    private static function parseDays(string $spec): ?array
    {
        $days = [];

        foreach (explode(',', $spec) as $part) {
            if (str_contains($part, '-')) {
                [$from, $to] = explode('-', $part, 2);

                if (! isset(self::DAYS[$from], self::DAYS[$to])) {
                    return null;
                }

                $start = self::DAYS[$from];
                $end = self::DAYS[$to];

                // Sa-Su 是 5→6，Fr-Mo 是 4→0（繞過週末），兩種都要能展開。
                for ($i = 0; $i <= 6; $i++) {
                    $day = ($start + $i) % 7;
                    $days[] = $day;

                    if ($day === $end) {
                        break;
                    }
                }

                continue;
            }

            if (! isset(self::DAYS[$part])) {
                return null;
            }

            $days[] = self::DAYS[$part];
        }

        return array_values(array_unique($days));
    }

    /**
     * 攤平成 DB 列，並在這裡處理跨午夜：17:00-02:00 切成「當日 17:00-24:00」與
     * 「隔日 00:00-02:00」。切在寫入端而不是查詢端，`open_now` 的 SQL 才能維持
     * 單純的 `opens_at <= now < closes_at`，吃得到索引。
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $byDay
     * @return list<array{day: int, opens_at: int, closes_at: int}>
     */
    private static function flatten(array $byDay): array
    {
        $rows = [];

        foreach ($byDay as $day => $intervals) {
            foreach ($intervals as [$opens, $closes]) {
                if ($closes === $opens) {
                    // "00:00-00:00" 在 OSM 是全天營業。
                    $rows[] = ['day' => $day, 'opens_at' => 0, 'closes_at' => 1440];

                    continue;
                }

                if ($closes > $opens) {
                    $rows[] = ['day' => $day, 'opens_at' => $opens, 'closes_at' => $closes];

                    continue;
                }

                $rows[] = ['day' => $day, 'opens_at' => $opens, 'closes_at' => 1440];
                $rows[] = ['day' => ($day + 1) % 7, 'opens_at' => 0, 'closes_at' => $closes];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['day'], $a['opens_at']] <=> [$b['day'], $b['opens_at']]);

        return $rows;
    }

    /**
     * @return list<array{day: int, opens_at: int, closes_at: int}>
     */
    private static function allDay(): array
    {
        return array_map(
            fn (int $day) => ['day' => $day, 'opens_at' => 0, 'closes_at' => 1440],
            range(0, 6),
        );
    }
}
