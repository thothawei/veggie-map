<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /**
     * 用指定的 sync_bboxes 重跑一次 routes/console.php，回傳排進去的指令字串。
     * 不讀環境變數，避免測試結果跟著 .env 的 EXTERNAL_API_SYNC_BBOXES 飄移。
     *
     * @param  array<int, string>  $bboxes
     * @return array<int, string>
     */
    private function scheduledCommands(array $bboxes): array
    {
        config(['services.sync_bboxes' => $bboxes]);

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

    public function test_sync_is_not_scheduled_when_no_bbox_is_configured(): void
    {
        $commands = collect($this->scheduledCommands([]));

        $this->assertFalse($commands->contains(fn ($command) => str_contains($command, 'restaurants:sync')));
    }

    public function test_each_configured_bbox_gets_its_own_sync_schedule(): void
    {
        $commands = collect($this->scheduledCommands([
            '24.9613,121.4570,25.2130,121.6663',
            '22.60,120.28,22.68,120.35',
        ]));

        $syncCommands = $commands->filter(fn ($command) => str_contains($command, 'restaurants:sync'))->values();

        $this->assertCount(2, $syncCommands);
        $this->assertTrue($syncCommands->contains(fn ($command) => str_contains($command, '24.9613,121.4570,25.2130,121.6663')));
        $this->assertTrue($syncCommands->contains(fn ($command) => str_contains($command, '22.60,120.28,22.68,120.35')));
    }

    public function test_default_env_covers_taichung_and_tokyo(): void
    {
        // 預設涵蓋範圍是產品決定（2026-08-25：台中市＋東京 23 区），不是隨手填的值，
        // 所以鎖在測試裡——有人改動 .env.example 的涵蓋城市時要是有意識的決定。
        $this->assertSame(
            [
                '23.9500,120.4300,24.4500,121.4700',
                '35.5300,139.5600,35.8200,139.9200',
            ],
            config('services.sync_bboxes')
        );
    }

    public function test_semicolon_separated_bboxes_are_parsed_into_separate_schedules(): void
    {
        $commands = collect($this->scheduledCommands(
            config('services.sync_bboxes')
        ))->filter(fn ($command) => str_contains($command, 'restaurants:sync'));

        $this->assertCount(2, $commands, '兩個城市要各自產生一條排程，不是合併成一次大查詢');
    }
}
