<?php

namespace Tests\Feature\Console;

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

    public function test_default_env_covers_four_taiwanese_cities_with_only_and_tokyo_with_yes(): void
    {
        // 涵蓋範圍與各自的收錄規則都是產品決定（2026-08-25），不是隨手填的值，所以鎖在
        // 測試裡——有人改動時要是有意識的決定。
        $this->assertSame(
            [
                ['bbox' => '24.9613,121.4570,25.2130,121.6663', 'diet' => 'only'],  // 台北市
                ['bbox' => '23.9500,120.4300,24.4500,121.4700', 'diet' => 'only'],  // 台中市
                ['bbox' => '22.4500,120.1500,23.3000,121.0600', 'diet' => 'only'],  // 高雄市
                ['bbox' => '22.8500,120.0000,23.4500,120.7000', 'diet' => 'only'],  // 台南市
                ['bbox' => '35.5300,139.5600,35.8200,139.9200', 'diet' => 'yes'],   // 東京 23 区
            ],
            config('services.sync_regions')
        );
    }

    public function test_only_tokyo_uses_the_relaxed_diet_rule(): void
    {
        $regions = collect(config('services.sync_regions'));

        // 台灣四市一律 only、只有日本放寬成 yes。要是哪天有人順手把台灣某市改成 yes，
        // 那個城市會混進「有素食選項的一般餐廳」，跟其他三市的收錄標準不一致。
        $this->assertSame(
            1,
            $regions->where('diet', 'yes')->count(),
            '目前只有東京該用 yes 規則'
        );
        $this->assertSame(4, $regions->where('diet', 'only')->count());
    }
}
