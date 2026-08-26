<?php

namespace Tests\Unit;

use App\Support\OpeningHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpeningHoursTest extends TestCase
{
    public function test_24_7_covers_every_day(): void
    {
        $rows = OpeningHours::parse('24/7');

        $this->assertCount(7, $rows);

        foreach ($rows as $row) {
            $this->assertSame(0, $row['opens_at']);
            $this->assertSame(1440, $row['closes_at']);
        }
    }

    public function test_day_range_with_two_spans(): void
    {
        $rows = OpeningHours::parse('Mo-Fr 11:00-14:00,17:00-21:00');

        // 五個工作日 × 兩個時段
        $this->assertCount(10, $rows);
        $this->assertSame(['day' => 0, 'opens_at' => 660, 'closes_at' => 840], $rows[0]);
        $this->assertSame(['day' => 0, 'opens_at' => 1020, 'closes_at' => 1260], $rows[1]);
        $this->assertSame(4, $rows[9]['day']);
    }

    public function test_time_only_rule_applies_to_every_day(): void
    {
        $rows = OpeningHours::parse('11:00-21:00');

        $this->assertCount(7, $rows);
        $this->assertSame(range(0, 6), array_column($rows, 'day'));
    }

    public function test_later_rule_overrides_earlier_one(): void
    {
        $rows = OpeningHours::parse('Mo-Su 09:00-18:00; Su off');

        $this->assertCount(6, $rows);
        $this->assertNotContains(6, array_column($rows, 'day'));
    }

    public function test_crossing_midnight_is_split_into_two_rows(): void
    {
        $rows = OpeningHours::parse('Sa 17:00-02:00');

        $this->assertSame([
            ['day' => 5, 'opens_at' => 1020, 'closes_at' => 1440],
            ['day' => 6, 'opens_at' => 0, 'closes_at' => 120],
        ], $rows);
    }

    public function test_sunday_crossing_midnight_wraps_to_monday(): void
    {
        $rows = OpeningHours::parse('Su 22:00-01:00');

        $this->assertSame(0, $rows[0]['day']);
        $this->assertSame(6, $rows[1]['day']);
    }

    public function test_public_holiday_rule_is_ignored_but_keeps_the_rest(): void
    {
        $rows = OpeningHours::parse('Mo-Fr 09:00-17:00; PH off');

        $this->assertCount(5, $rows);
    }

    public function test_holiday_only_value_parses_to_no_intervals(): void
    {
        // 解析成功但沒有任何營業時段——與「無法解析」是不同的答案。
        $this->assertSame([], OpeningHours::parse('PH off'));
    }

    public function test_comma_separated_days(): void
    {
        $rows = OpeningHours::parse('Mo,We,Fr 09:00-18:00');

        $this->assertSame([0, 2, 4], array_column($rows, 'day'));
    }

    public function test_day_range_wrapping_over_the_weekend(): void
    {
        $rows = OpeningHours::parse('Fr-Mo 10:00-20:00');

        $this->assertSame([0, 4, 5, 6], array_column($rows, 'day'));
    }

    /**
     * 這些是 OSM 真實會出現、但不在子集內的寫法。錯解會把打烊的店標成營業中，
     * 所以一律回 null，讓呼叫端顯示「營業時間未知」。
     */
    #[DataProvider('unsupportedValues')]
    public function test_unsupported_syntax_returns_null(string $raw): void
    {
        $this->assertNull(OpeningHours::parse($raw));
    }

    public static function unsupportedValues(): array
    {
        return [
            'month range' => ['Apr-Oct Mo-Su 10:00-18:00'],
            'week selector' => ['week 1-53 Mo 10:00-18:00'],
            'sunrise' => ['sunrise-sunset'],
            'nth weekday' => ['Mo[1] 10:00-18:00'],
            'free text' => ['"by appointment"'],
            'open ended' => ['Mo-Fr 09:00+'],
            'value unknown' => ['unknown'],
            'days without times' => ['Mo-Fr'],
            'empty' => [''],
            'garbage' => ['whenever we feel like it'],
            'bad minutes' => ['Mo 09:70-18:00'],
        ];
    }

    /**
     * 以下五個字串是 2026-08-26 同步台中 bbox 之後，實際解析失敗的那 5 筆
     * （共 73 筆有 opening_hours）。全部是同一個形狀：逗號後面有空白。
     * 拿真實資料回來補測試，比自己想像 OSM 會長什麼樣子可靠。
     */
    #[DataProvider('realWorldValuesThatUsedToFail')]
    public function test_real_world_values_from_the_taichung_sync(string $raw, int $expectedRows): void
    {
        $rows = OpeningHours::parse($raw);

        $this->assertNotNull($rows, "應該要解析得出來：{$raw}");
        $this->assertCount($expectedRows, $rows);
    }

    public static function realWorldValuesThatUsedToFail(): array
    {
        return [
            // 逗號接兩條完整規則：全週 11:00-14:00（7）＋ 平日 16:00-19:00（5）
            '天慈素食' => ['Mo-Su 11:00-14:00, Mo-Fr 16:00-19:00', 12],
            // 逗號後有空白的星期列表＋兩個時段：(Mo,We,Th,Fr) × 2 ＋ Sa × 2 ＋ Su × 2
            '韓閣蔬食' => ['Mo, We-Fr 11:00-14:00, 17:00-21:00; Sa 11:00-14:30, 17:00-21:00; Su 11:00-15:00, 17:00-21:00', 12],
            '陶米健康素' => ['Tu-Su 11:00-14:30, Tu-Su 17:00-21:30', 12],
            '小莊素食' => ['Mo-Tu,Th-Sa, Su 06:00-10:00', 6],
            '梅香源艾草麵線' => ['Mo, We-Fr 10:00-19:00; Sa 10:30-19:00; Su 09:00-19:00', 6],
        ];
    }

    public function test_null_input_returns_null(): void
    {
        $this->assertNull(OpeningHours::parse(null));
    }
}
