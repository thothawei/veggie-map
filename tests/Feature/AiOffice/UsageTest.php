<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\TokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10：用量、成本、Agent 效能。重點是「數字真的是算出來的」——
 * 每個斷言都對照手算的期望值，不是拿實作的輸出回填。
 */
class UsageTest extends TestCase
{
    use RefreshDatabase;

    private function usage(array $attributes = []): TokenUsage
    {
        return TokenUsage::create($attributes + [
            'provider' => 'mock',
            'model' => 'mock-1',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost' => '0.001000',
        ]);
    }

    public function test_totals_sum_tokens_and_cost(): void
    {
        $this->usage(['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'estimated_cost' => '0.002000']);
        $this->usage(['input_tokens' => 200, 'output_tokens' => 30, 'total_tokens' => 230, 'estimated_cost' => '0.003500']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage')
            ->assertOk()
            ->assertJsonPath('data.totals.requests', 2)
            ->assertJsonPath('data.totals.input_tokens', 300)
            ->assertJsonPath('data.totals.output_tokens', 80)
            ->assertJsonPath('data.totals.total_tokens', 380)
            // 金額回字串，固定 6 位小數——帳務數字不經過 float。
            ->assertJsonPath('data.totals.estimated_cost', '0.005500');
    }

    public function test_usage_is_grouped_by_model_agent_and_project(): void
    {
        $project = Project::factory()->create(['name' => '待辦 API']);
        $agent = Agent::factory()->create(['name' => '後端小周']);

        $this->usage(['model' => 'claude-opus-5', 'agent_id' => $agent->id, 'project_id' => $project->id, 'total_tokens' => 500]);
        $this->usage(['model' => 'mock-1', 'total_tokens' => 100]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $response = $this->getJson('/api/v1/ai-office/usage')->assertOk();

        // 用量大的排前面，前端不用自己再排一次。
        $response->assertJsonPath('data.by_model.0.model', 'claude-opus-5');
        $response->assertJsonPath('data.by_model.0.total_tokens', 500);
        // 名字直接帶回來，前端不必為了顯示一個名字再打一次 API。
        $response->assertJsonPath('data.by_agent.0.agent_name', '後端小周');
        $response->assertJsonPath('data.by_project.0.project_name', '待辦 API');
    }

    public function test_usage_can_be_filtered_by_project(): void
    {
        $mine = Project::factory()->create();
        $other = Project::factory()->create();

        $this->usage(['project_id' => $mine->id, 'total_tokens' => 111]);
        $this->usage(['project_id' => $other->id, 'total_tokens' => 999]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/usage?project_id={$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.total_tokens', 111)
            ->assertJsonPath('data.totals.requests', 1);
    }

    public function test_date_range_filter_excludes_older_rows(): void
    {
        $old = $this->usage(['total_tokens' => 777]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $this->usage(['total_tokens' => 123]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.totals.total_tokens', 123);
    }

    public function test_date_filter_works_together_with_the_joined_groupings(): void
    {
        // 這條是為了那個只在「日期篩選 ＋ join」同時出現時才會炸的
        // 「Column 'created_at' is ambiguous」而寫的，不是重複上一條。
        $agent = Agent::factory()->create(['name' => '後端小周']);
        $this->usage(['agent_id' => $agent->id, 'total_tokens' => 42]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.by_agent.0.agent_name', '後端小周')
            ->assertJsonPath('data.by_agent.0.total_tokens', 42);
    }

    public function test_to_before_from_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage?from=2026-08-10&to=2026-08-01')
            ->assertStatus(422);
    }

    public function test_daily_series_only_contains_days_with_usage(): void
    {
        $old = $this->usage(['total_tokens' => 10]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->usage(['total_tokens' => 20]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage')
            ->assertOk()
            ->assertJsonCount(2, 'data.daily')
            ->assertJsonPath('data.daily.0.day', now()->subDays(3)->toDateString())
            ->assertJsonPath('data.daily.1.total_tokens', 20);
    }

    public function test_response_reports_the_pricing_table_the_costs_came_from(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/usage')
            ->assertOk()
            ->assertJsonPath('meta.pricing.claude-opus-5.input', 5);
    }

    public function test_consumer_role_cannot_read_usage(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/v1/ai-office/usage')->assertStatus(403);
    }

    public function test_agent_performance_counts_tasks_runs_and_cost(): void
    {
        $project = Project::factory()->create();
        $agent = Agent::factory()->create(['name' => '後端小周']);

        Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id, 'status' => 'completed']);
        Task::factory()->for($project)->create(['assigned_agent_id' => $agent->id, 'status' => 'completed']);
        $failed = Task::factory()->for($project)->create([
            'assigned_agent_id' => $agent->id,
            'status' => 'failed',
            'retry_count' => 2,
        ]);

        TaskRun::create([
            'task_id' => $failed->id, 'agent_id' => $agent->id, 'run_number' => 1,
            'status' => 'completed', 'duration_ms' => 1000, 'started_at' => now(),
        ]);
        TaskRun::create([
            'task_id' => $failed->id, 'agent_id' => $agent->id, 'run_number' => 2,
            'status' => 'completed', 'duration_ms' => 3000, 'started_at' => now(),
        ]);
        // 失敗的執行不算進平均耗時——把它算進去會讓「失敗得很快」看起來像效率高。
        TaskRun::create([
            'task_id' => $failed->id, 'agent_id' => $agent->id, 'run_number' => 3,
            'status' => 'failed', 'duration_ms' => 50, 'started_at' => now(),
        ]);

        $this->usage(['agent_id' => $agent->id, 'project_id' => $project->id, 'total_tokens' => 400, 'estimated_cost' => '0.004000']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/stats/agents')
            ->assertOk()
            ->assertJsonPath('data.0.name', '後端小周')
            ->assertJsonPath('data.0.tasks', 3)
            ->assertJsonPath('data.0.completed', 2)
            ->assertJsonPath('data.0.failed', 1)
            ->assertJsonPath('data.0.retries', 2)
            ->assertJsonPath('data.0.runs', 3)
            ->assertJsonPath('data.0.success_rate', 0.6667)
            ->assertJsonPath('data.0.avg_duration_ms', 2000)
            ->assertJsonPath('data.0.total_tokens', 400)
            ->assertJsonPath('data.0.estimated_cost', '0.004000');
    }

    public function test_agent_without_tasks_has_null_success_rate_not_zero(): void
    {
        Agent::factory()->create(['name' => '還沒上工的人']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        // 0% 跟「還沒有資料」是兩件事，混在一起會讓排行榜把新人排到最後一名。
        $this->getJson('/api/v1/ai-office/stats/agents')
            ->assertOk()
            ->assertJsonPath('data.0.success_rate', null)
            ->assertJsonPath('data.0.avg_duration_ms', null)
            ->assertJsonPath('data.0.tasks', 0);
    }

    public function test_agent_performance_can_be_scoped_to_one_project(): void
    {
        $mine = Project::factory()->create();
        $other = Project::factory()->create();
        $agent = Agent::factory()->create();

        Task::factory()->for($mine)->create(['assigned_agent_id' => $agent->id, 'status' => 'completed']);
        Task::factory()->for($other)->create(['assigned_agent_id' => $agent->id, 'status' => 'failed']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/stats/agents?project_id={$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.0.tasks', 1)
            ->assertJsonPath('data.0.failed', 0);
    }
}
