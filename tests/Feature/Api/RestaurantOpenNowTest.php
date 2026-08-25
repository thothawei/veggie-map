<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `open_now`（總 Prompt 第八、二十八節）。
 *
 * 每個測試都把時間釘死：不釘的話同一組斷言半夜跑會全部反過來，變成「有時綠有時紅」
 * 的假保護。基準時間用 UTC 表示，讓「台北與東京差一小時」在斷言裡看得出來。
 */
class RestaurantOpenNowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** 2026-08-26 是星期三。UTC 04:00 ＝ 台北 12:00、東京 13:00。 */
    private function freezeAt(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    public function test_open_now_keeps_only_restaurants_open_at_this_moment(): void
    {
        $this->freezeAt('2026-08-26 04:00:00'); // 台北 12:00（週三）

        $open = Restaurant::factory()->withOpeningHours('Mo-Fr 11:00-14:00,17:00-21:00')->create();
        $closedNow = Restaurant::factory()->withOpeningHours('Mo-Fr 17:00-21:00')->create();
        $closedToday = Restaurant::factory()->withOpeningHours('Sa-Su 11:00-21:00')->create();

        $response = $this->getJson('/api/v1/restaurants?open_now=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($open->id, $response->json('data.0.id'));
        $this->assertNotContains(
            $closedNow->id,
            array_column($response->json('data'), 'id'),
        );
        $this->assertNotContains($closedToday->id, array_column($response->json('data'), 'id'));
    }

    public function test_restaurants_without_parsable_hours_are_excluded_not_assumed_open(): void
    {
        $this->freezeAt('2026-08-26 04:00:00');

        Restaurant::factory()->create(['opening_hours' => null]);
        Restaurant::factory()->withOpeningHours('by appointment')->create();

        $this->getJson('/api/v1/restaurants?open_now=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_open_now_uses_the_local_time_of_each_restaurant(): void
    {
        // UTC 12:30 ＝ 台北 20:30（還在營業）、東京 21:30（已打烊）。
        $this->freezeAt('2026-08-26 12:30:00');

        $taipei = Restaurant::factory()
            ->withOpeningHours('Mo-Su 11:00-21:00', 'Asia/Taipei')
            ->create(['city' => '台北市']);

        $tokyo = Restaurant::factory()
            ->withOpeningHours('Mo-Su 11:00-21:00', 'Asia/Tokyo')
            ->create(['city' => '東京都']);

        $ids = array_column($this->getJson('/api/v1/restaurants?open_now=1')->json('data'), 'id');

        $this->assertContains($taipei->id, $ids);
        $this->assertNotContains($tokyo->id, $ids, '東京時間已經 21:30，不該被算成營業中');
    }

    public function test_crossing_midnight_is_open_after_midnight(): void
    {
        // UTC 17:00（週三）＝ 台北 01:00（週四凌晨）。
        $this->freezeAt('2026-08-26 17:00:00');

        $lateNight = Restaurant::factory()->withOpeningHours('We 18:00-03:00')->create();

        $this->getJson('/api/v1/restaurants?open_now=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $lateNight->id);
    }

    public function test_resource_reports_three_states_not_a_boolean(): void
    {
        $this->freezeAt('2026-08-26 04:00:00');

        $open = Restaurant::factory()->withOpeningHours('Mo-Fr 11:00-14:00')->create();
        $unknown = Restaurant::factory()->create(['opening_hours' => null]);

        $data = collect($this->getJson('/api/v1/restaurants')->json('data'))->keyBy('id');

        $this->assertSame('open', $data[$open->id]['open_status']);
        $this->assertSame('14:00', $data[$open->id]['closes_at']);
        $this->assertSame('unknown', $data[$unknown->id]['open_status']);
        $this->assertNull($data[$unknown->id]['open_now']);
    }

    public function test_detail_returns_the_weekly_schedule(): void
    {
        $this->freezeAt('2026-08-26 04:00:00');

        $restaurant = Restaurant::factory()->withOpeningHours('Mo-Fr 11:00-14:00; Sa 11:00-21:00')->create();

        $week = $this->getJson("/api/v1/restaurants/{$restaurant->id}")->json('data.opening_hours_week');

        $this->assertCount(7, $week);
        $this->assertSame(['11:00–14:00'], $week[0]['ranges']);
        $this->assertSame(['11:00–21:00'], $week[5]['ranges']);
        $this->assertSame([], $week[6]['ranges'], '週日沒有時段＝公休');
    }

    public function test_open_now_accepts_the_string_true_from_axios(): void
    {
        $this->freezeAt('2026-08-26 04:00:00');

        Restaurant::factory()->withOpeningHours('Mo-Fr 11:00-14:00')->create();
        Restaurant::factory()->withOpeningHours('Sa-Su 11:00-14:00')->create();

        $this->getJson('/api/v1/restaurants?open_now=true')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_open_now_false_does_not_filter_anything(): void
    {
        $this->freezeAt('2026-08-26 04:00:00');

        Restaurant::factory()->create(['opening_hours' => null]);
        Restaurant::factory()->withOpeningHours('Sa-Su 11:00-14:00')->create();

        $this->getJson('/api/v1/restaurants?open_now=false')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
