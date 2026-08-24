<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_recalculate_ratings_and_calculate_scores_are_scheduled_daily(): void
    {
        $events = app(Schedule::class)->events();

        $commands = collect($events)->map(fn ($event) => trim($event->command));

        $this->assertTrue($commands->contains(fn ($command) => str_contains($command, 'restaurants:recalculate-ratings')));
        $this->assertTrue($commands->contains(fn ($command) => str_contains($command, 'restaurants:calculate-scores')));
    }

    public function test_sync_bboxes_config_is_empty_by_default_so_sync_is_not_scheduled(): void
    {
        $this->assertSame([], config('services.sync_bboxes'));

        $events = app(Schedule::class)->events();
        $commands = collect($events)->map(fn ($event) => trim($event->command));

        $this->assertFalse($commands->contains(fn ($command) => str_contains($command, 'restaurants:sync')));
    }
}
