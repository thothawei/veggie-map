<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentError;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\TokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /ai-office/agents/{id}`（規格第 47 節 `AgentDetailView`）補的那一半：
 * Current Task／Recent Tasks／Recent Errors／Success Rate／Average Duration／
 * Token Usage。原本這些散在 `/ai-office/usage` 跟沒有任何端點的角落，
 * 這裡併回單一 Agent 的詳情。
 */
class AgentDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_task_is_the_one_actively_assigned_or_running(): void
    {
        $agent = Agent::factory()->create();
        $project = Project::factory()->create();

        Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id, 'status' => 'completed']);
        $running = Task::factory()->for($project)->create([
            'assigned_agent_id' => $agent->id,
            'status' => 'running',
            'title' => '正在做的事',
            'started_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.current_task.id', $running->id)
            ->assertJsonPath('data.current_task.title', '正在做的事');
    }

    public function test_current_task_is_null_when_agent_has_nothing_in_flight(): void
    {
        $agent = Agent::factory()->create();
        Task::factory()->for(Project::factory()->create())->create([
            'assigned_agent_id' => $agent->id,
            'status' => 'completed',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.current_task', null);
    }

    public function test_recent_tasks_are_ordered_newest_first_and_capped(): void
    {
        $agent = Agent::factory()->create();
        $project = Project::factory()->create();

        $old = Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $new = Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.recent_tasks.0.id', $new->id)
            ->assertJsonPath('data.recent_tasks.1.id', $old->id)
            ->assertJsonCount(2, 'data.recent_tasks');
    }

    public function test_recent_errors_are_ordered_newest_first(): void
    {
        $agent = Agent::factory()->create();

        $old = AgentError::create(['agent_id' => $agent->id, 'type' => 'timeout', 'message' => '第一次']);
        $old->forceFill(['created_at' => now()->subHour()])->save();
        $new = AgentError::create(['agent_id' => $agent->id, 'type' => 'tool_error', 'message' => '第二次']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.recent_errors.0.id', $new->id)
            ->assertJsonPath('data.recent_errors.0.message', '第二次')
            ->assertJsonPath('data.recent_errors.1.id', $old->id);
    }

    public function test_performance_numbers_match_the_usage_stats_endpoint(): void
    {
        $agent = Agent::factory()->create();
        $project = Project::factory()->create();

        $completed = Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id, 'status' => 'completed']);
        Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id, 'status' => 'failed']);

        TaskRun::create([
            'task_id' => $completed->id, 'agent_id' => $agent->id, 'run_number' => 1,
            'status' => 'completed', 'duration_ms' => 4000, 'started_at' => now(),
        ]);

        TokenUsage::create([
            'agent_id' => $agent->id, 'provider' => 'mock', 'model' => 'mock-1',
            'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150,
            'estimated_cost' => '0.001500',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.performance.tasks', 2)
            ->assertJsonPath('data.performance.completed', 1)
            ->assertJsonPath('data.performance.failed', 1)
            ->assertJsonPath('data.performance.success_rate', 0.5)
            ->assertJsonPath('data.performance.avg_duration_ms', 4000)
            ->assertJsonPath('data.performance.total_tokens', 150)
            ->assertJsonPath('data.performance.estimated_cost', '0.001500');
    }

    public function test_agent_with_no_history_gets_null_success_rate_not_zero(): void
    {
        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}")
            ->assertOk()
            ->assertJsonPath('data.performance.tasks', 0)
            ->assertJsonPath('data.performance.success_rate', null)
            ->assertJsonPath('data.performance.avg_duration_ms', null)
            ->assertJsonCount(0, 'data.recent_tasks')
            ->assertJsonCount(0, 'data.recent_errors');
    }
}
