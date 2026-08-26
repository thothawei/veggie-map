<?php

namespace Tests\Feature\Console;

use App\Support\DietCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /**
     * 用指定的 sync_regions 重跑一次 routes/console.php，回傳排進去的指令字串。
     * 不讀環境變數，避免測試結果跟著 .env 的 EXTERNAL_API_SYNC_BBOXES 飄移。
     *
     * @param  array<int, array{bbox: string, diet: string}>  $regions
     * @return array<int, string>
     */
    /**
     * 同上，但回傳 cron 運算式而不是指令字串——驗「排在什麼時候」用的。
     *
     * @param  array<int, array{bbox: string, diet: string}>  $regions
     * @return array<int, string>
     */
    private function scheduledExpressions(array $regions): array
    {
        config(['services.sync_regions' => $regions]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command, 'restaurants:sync'))
            ->map(fn ($event) => $event->expression)
            ->values()
            ->all();
    }

    private function scheduledCommands(array $regions): array
    {
        config(['services.sync_regions' => $regions]);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return collect($schedule->events())
            ->map(fn ($event) => trim($event->command))
            ->all();
    }

    public function test_recalculate_ratings_and_calculate_scores_are_scheduled_daily(): void
    {
        $commands = collect($this->scheduledCommands([]));

        $this->assertTrue($commands->contains(fn ($command) => str_contains($command, 'restaurants:recalculate-ratings')));
        $this->assertTrue($commands->contains(fn ($command) => str_contains($command, 'restaurants:calculate-scores')));
    }

    public function test_sync_is_not_scheduled_when_no_region_is_configured(): void
    {
        $commands = collect($this->scheduledCommands([]));

        $this->assertFalse($commands->contains(fn ($command) => str_contains($command, 'restaurants:sync')));
    }

    public function test_each_region_gets_its_own_sync_schedule_carrying_its_own_diet_rule(): void
    {
        $commands = collect($this->scheduledCommands([
            ['bbox' => '23.9500,120.4300,24.4500,121.4700', 'diet' => 'only'],
            ['bbox' => '35.5300,139.5600,35.8200,139.9200', 'diet' => 'yes'],
        ]))->filter(fn ($command) => str_contains($command, 'restaurants:sync'))->values();

        $this->assertCount(2, $commands, '兩個範圍要各自產生一條排程，不是合併成一次大查詢');

        // 收錄規則必須跟著各自的範圍走。要是 --diet 沒帶或帶錯，東京會被套上台灣的 only
        // 規則，整個 23 区只剩 46 家（見 docs/progress.md 2026-08-25）。
        $this->assertTrue($commands->contains(
            fn ($c) => str_contains($c, '23.9500,120.4300,24.4500,121.4700') && str_contains($c, "--diet='only'")
        ));
        $this->assertTrue($commands->contains(
            fn ($c) => str_contains($c, '35.5300,139.5600,35.8200,139.9200') && str_contains($c, "--diet='yes'")
        ));
    }

    public function test_default_env_covers_configured_cities_with_known_diet_modes(): void
    {
        $regions = config('services.sync_regions');
        $cities = config('cities');

        $this->assertCount(count($cities), $regions);

        foreach ($regions as $region) {
            $this->assertContains(
                $region['diet'],
                DietCatalog::syncModeNames(),
                "sync region [{$region['bbox']}] 的 diet [{$region['diet']}] 不在 config/diet.php sync_modes"
            );
        }

        $this->assertSame(
            [
                ['bbox' => '24.9613,121.4570,25.2130,121.6663', 'diet' => 'yes'],
                ['bbox' => '23.9500,120.4300,24.4500,121.4700', 'diet' => 'yes'],
                ['bbox' => '22.4500,120.1500,23.3000,121.0600', 'diet' => 'yes'],
                ['bbox' => '22.8500,120.0000,23.4500,120.7000', 'diet' => 'yes'],
                ['bbox' => '35.5300,139.5600,35.8200,139.9200', 'diet' => 'yes'],
            ],
            $regions
        );
    }

    public function test_tokyo_uses_a_mode_that_includes_yes(): void
    {
        $tokyo = collect(config('cities'))->firstWhere('slug', 'tokyo');
        $this->assertNotNull($tokyo);

        $region = collect(config('services.sync_regions'))->firstWhere('bbox', $tokyo['bbox']);
        $this->assertNotNull($region, '東京 bbox 必須在 sync_regions 裡');
        $this->assertTrue(
            DietCatalog::syncModeIncludes($region['diet'], 'yes'),
            "東京的收錄模式 [{$region['diet']}] 必須含 osm value yes，否則友善店進不來"
        );
    }

    /**
     * 五個城市不能在同一秒一起打 Overpass。理由不是我們自己的效能——Overpass 是
     * 免費的社群服務，使用政策明確要求節制；2026-08-26 手動重跑時東京（最大的
     * bbox）就連續拿到兩次 HTTP 504。
     */
    public function test_regions_are_staggered_so_they_do_not_hit_overpass_at_once(): void
    {
        $expressions = $this->scheduledExpressions([
            ['bbox' => 'a', 'diet' => 'yes'],
            ['bbox' => 'b', 'diet' => 'yes'],
            ['bbox' => 'c', 'diet' => 'yes'],
        ]);

        $this->assertSame(['0 1 * * *', '10 1 * * *', '20 1 * * *'], $expressions);
        $this->assertCount(3, array_unique($expressions), '每個城市要有自己的時間，不能重疊');
    }

    public function test_many_regions_roll_over_into_the_next_hour_instead_of_colliding(): void
    {
        // 城市數量變多時不能繞回同一分鐘（`% 60` 沒有配上小時進位的話，第七個
        // 城市會跟第一個撞在一起）。
        $regions = array_map(fn (int $i) => ['bbox' => "bbox-{$i}", 'diet' => 'yes'], range(1, 8));

        $expressions = $this->scheduledExpressions($regions);

        $this->assertCount(8, array_unique($expressions));
        $this->assertContains('0 2 * * *', $expressions, '第七個要滾到 02:00，不是繞回 01:00');
    }
}
