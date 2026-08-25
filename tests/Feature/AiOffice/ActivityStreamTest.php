<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7：事件流 REST 讀取、SSE 串流、以及 Task／Agent 狀態變動有沒有真的進事件流。
 */
class ActivityStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 測試不要真的等一秒一輪：串流跑一輪就到期收工。
        config([
            'ai_office.events.poll_interval_ms' => 1,
            'ai_office.events.max_duration_seconds' => 1,
        ]);
    }

    private function activity(Project $project, string $type = 'TaskStarted'): Activity
    {
        return Activity::create([
            'project_id' => $project->id,
            'type' => $type,
            'description' => "事件 {$type}",
        ]);
    }

    public function test_activities_are_listed_newest_first(): void
    {
        $project = Project::factory()->create();
        $first = $this->activity($project);
        $second = $this->activity($project, 'TaskCompleted');

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/activities")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('meta.latest_id', $second->id);
    }

    public function test_after_id_returns_only_newer_events_in_ascending_order(): void
    {
        $project = Project::factory()->create();
        $first = $this->activity($project);
        $second = $this->activity($project, 'TaskCompleted');
        $third = $this->activity($project, 'TaskFailed');

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/activities?after_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $third->id);
    }

    public function test_activities_of_other_projects_are_not_leaked(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->activity($other);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/activities")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_consumer_role_cannot_read_activities(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/activities")->assertStatus(403);
    }

    public function test_stream_requires_a_valid_ticket(): void
    {
        $project = Project::factory()->create();

        $this->get("/api/v1/ai-office/projects/{$project->id}/events?ticket=nope")
            ->assertStatus(401);
    }

    public function test_stream_pushes_activities_created_after_the_cursor(): void
    {
        $project = Project::factory()->create();
        $old = $this->activity($project);
        $new = $this->activity($project, 'TaskCompleted');

        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $ticket = $this->postJson("/api/v1/ai-office/projects/{$project->id}/events/ticket")
            ->assertOk()
            ->json('data.ticket');

        $response = $this->get(
            "/api/v1/ai-office/projects/{$project->id}/events?ticket={$ticket}&after_id={$old->id}",
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $response->assertHeader('X-Accel-Buffering', 'no');

        $body = $response->streamedContent();

        $this->assertStringContainsString("id: {$new->id}", $body);
        $this->assertStringContainsString('event: activity', $body);
        $this->assertStringContainsString('TaskCompleted', $body);
        $this->assertStringNotContainsString("id: {$old->id}\n", $body);
        // 壽命到期要自己收尾並附上游標，前端才知道從哪裡重連。
        $this->assertStringContainsString('event: reconnect', $body);
        $this->assertStringContainsString("\"last_id\":{$new->id}", $body);
    }

    public function test_ticket_can_only_be_used_once(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $ticket = $this->postJson("/api/v1/ai-office/projects/{$project->id}/events/ticket")
            ->json('data.ticket');

        $this->get("/api/v1/ai-office/projects/{$project->id}/events?ticket={$ticket}")
            ->streamedContent();

        $this->get("/api/v1/ai-office/projects/{$project->id}/events?ticket={$ticket}")
            ->assertStatus(401);
    }

    public function test_ticket_is_bound_to_the_project_it_was_issued_for(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $ticket = $this->postJson("/api/v1/ai-office/projects/{$project->id}/events/ticket")
            ->json('data.ticket');

        $this->get("/api/v1/ai-office/projects/{$other->id}/events?ticket={$ticket}")
            ->assertStatus(401);
    }

    public function test_too_many_concurrent_streams_are_rejected(): void
    {
        config(['ai_office.events.max_connections_per_user' => 0]);

        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $ticket = $this->postJson("/api/v1/ai-office/projects/{$project->id}/events/ticket")
            ->json('data.ticket');

        $this->get("/api/v1/ai-office/projects/{$project->id}/events?ticket={$ticket}")
            ->assertStatus(429);
    }

    public function test_stream_slot_is_released_after_the_connection_ends(): void
    {
        config(['ai_office.events.max_connections_per_user' => 1]);

        $project = Project::factory()->create();
        $user = User::factory()->create(['role' => 'developer']);
        $this->actingAs($user);

        foreach (range(1, 2) as $round) {
            $ticket = $this->postJson("/api/v1/ai-office/projects/{$project->id}/events/ticket")
                ->json('data.ticket');

            $response = $this->get("/api/v1/ai-office/projects/{$project->id}/events?ticket={$ticket}");
            $response->assertOk();
            // 名額在串流真的跑完（streamedContent 觸發 callback）之後才會還回去。
            $response->streamedContent();
        }
    }

    public function test_task_status_change_is_recorded_as_an_activity(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->for($project)->create(['status' => 'pending']);

        $task->update(['status' => 'running']);

        $activity = Activity::where('type', 'TaskStatusChanged')->firstOrFail();

        $this->assertSame($project->id, $activity->project_id);
        $this->assertSame($task->id, $activity->task_id);
        // assertEquals：payload 是 JSON 解出來的關聯陣列，鍵的順序不是契約的一部分。
        $this->assertEquals(['from' => 'pending', 'to' => 'running'], $activity->payload);
    }

    public function test_task_update_without_status_change_records_nothing(): void
    {
        $task = Task::factory()->create(['status' => 'pending']);

        $task->update(['title' => '改標題不是改狀態']);

        $this->assertSame(0, Activity::where('type', 'TaskStatusChanged')->count());
    }

    public function test_agent_status_change_lands_on_the_project_it_is_working_for(): void
    {
        $project = Project::factory()->create();
        $agent = Agent::factory()->create(['status' => 'idle']);
        $task = Task::factory()->for($project)->create([
            'status' => 'running',
            'assigned_agent_id' => $agent->id,
        ]);

        $agent->update(['status' => 'working']);

        $activity = Activity::where('type', 'AgentStatusChanged')->firstOrFail();

        $this->assertSame($project->id, $activity->project_id);
        $this->assertSame($task->id, $activity->task_id);
        $this->assertSame($agent->id, $activity->agent_id);
        $this->assertEquals(['from' => 'idle', 'to' => 'working'], $activity->payload);
    }
}
