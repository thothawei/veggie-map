<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Jobs\ExecuteTaskJob;
use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Runtime\AgentRuntime;
use App\AiOffice\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AiOffice\RecordingTool;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private MockProvider $llm;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new MockProvider;
        $this->app->instance(LlmProviderInterface::class, $this->llm);

        $this->registry = new ToolRegistry;
        $this->app->instance(ToolRegistry::class, $this->registry);
    }

    public function test_pending_approvals_are_listed_by_default(): void
    {
        $project = Project::factory()->create();
        Approval::query()->create([
            'project_id' => $project->id,
            'action' => 'deploy_production',
            'risk_level' => 'critical',
            'status' => 'pending',
        ]);
        Approval::query()->create([
            'project_id' => $project->id,
            'action' => 'git_push',
            'risk_level' => 'high',
            'status' => 'approved',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/approvals')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'deploy_production')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_developer_cannot_approve(): void
    {
        $approval = Approval::query()->create([
            'action' => 'deploy_production',
            'risk_level' => 'critical',
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $this->postJson("/api/v1/ai-office/approvals/{$approval->id}/approve")
            ->assertStatus(403);
    }

    public function test_expired_requests_cannot_be_approved(): void
    {
        $approval = Approval::query()->create([
            'action' => 'deploy_production',
            'risk_level' => 'critical',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->postJson("/api/v1/ai-office/approvals/{$approval->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('expired', $approval->fresh()->status);
    }

    public function test_approving_executes_the_tool_that_was_paused(): void
    {
        Queue::fake([ExecuteTaskJob::class]);

        $tool = new RecordingTool(name: 'deploy_production', toolset: 'docker', riskLevel: 'critical');
        $this->registry->register($tool);

        $agent = $this->agent(['deploy_production' => 'approval'], ['docker']);
        $task = $this->task($agent);
        $this->llm->pushToolCall('deploy_production', ['target' => 'prod']);
        $this->runtime()->run($task);

        $this->assertSame(0, $tool->callCount());
        $approval = Approval::query()->where('action', 'deploy_production')->firstOrFail();
        $this->assertSame('pending', $approval->status);
        $this->assertSame(['target' => 'prod'], $approval->payload['input']);

        $manager = User::factory()->create(['role' => 'manager', 'name' => '主管']);
        $this->actingAs($manager);

        $this->postJson("/api/v1/ai-office/approvals/{$approval->id}/approve", [
            'comment' => 'QA 都過了',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by', $manager->id);

        $this->assertSame(1, $tool->callCount());
        $this->assertSame(['target' => 'prod'], $tool->calls[0]);
        $this->assertSame('succeeded', $approval->fresh()->toolExecution?->status);
        $this->assertSame('assigned', $task->fresh()->status);
        $this->assertSame('idle', $agent->fresh()->status);
        Queue::assertPushed(ExecuteTaskJob::class, fn (ExecuteTaskJob $job) => $job->taskId === $task->id);
    }

    public function test_rejecting_does_not_execute_the_tool(): void
    {
        $tool = new RecordingTool(name: 'deploy_production', toolset: 'docker', riskLevel: 'critical');
        $this->registry->register($tool);

        $agent = $this->agent(['deploy_production' => 'approval'], ['docker']);
        $task = $this->task($agent);
        $this->llm->pushToolCall('deploy_production', ['target' => 'prod']);
        $this->runtime()->run($task);

        $approval = Approval::query()->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/v1/ai-office/approvals/{$approval->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, $tool->callCount());
        $this->assertSame('rejected', $task->fresh()->status);
        $this->assertSame('denied', $approval->fresh()->toolExecution?->status);
    }

    public function test_high_risk_allow_still_requires_approval_until_threshold_moves(): void
    {
        $tool = new RecordingTool(name: 'git_push', toolset: 'git', riskLevel: 'high');
        $this->registry->register($tool);

        $agent = $this->agent(['git_push' => 'allow'], ['git']);
        $task = $this->task($agent);
        $this->llm->pushToolCall('git_push', ['branch' => 'feature']);
        $this->runtime()->run($task);

        $this->assertSame(0, $tool->callCount());
        $this->assertDatabaseHas('ai_office_approvals', [
            'action' => 'git_push',
            'risk_level' => 'high',
            'status' => 'pending',
        ]);
    }

    public function test_raising_threshold_lets_high_risk_allow_execute(): void
    {
        config(['ai_office.approvals.threshold' => 'critical']);

        $tool = new RecordingTool(name: 'git_push', toolset: 'git', riskLevel: 'high');
        $this->registry->register($tool);

        $agent = $this->agent(['git_push' => 'allow'], ['git']);
        $task = $this->task($agent);
        $this->llm->pushToolCall('git_push', ['branch' => 'feature']);
        $this->llm->pushText('push 完了');

        $run = $this->runtime()->run($task);

        $this->assertSame(1, $tool->callCount());
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseMissing('ai_office_approvals', ['action' => 'git_push']);
    }

    public function test_critical_requires_approval_even_when_threshold_is_off(): void
    {
        config(['ai_office.approvals.threshold' => 'off']);

        $tool = new RecordingTool(name: 'deploy_production', toolset: 'docker', riskLevel: 'critical');
        $this->registry->register($tool);

        $agent = $this->agent(['deploy_production' => 'allow'], ['docker']);
        $task = $this->task($agent);
        $this->llm->pushToolCall('deploy_production', ['target' => 'prod']);
        $this->runtime()->run($task);

        $this->assertSame(0, $tool->callCount());
        $this->assertDatabaseHas('ai_office_approvals', [
            'action' => 'deploy_production',
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, string>  $permissions
     * @param  list<string>  $toolsets
     */
    private function agent(array $permissions, array $toolsets): Agent
    {
        $agent = Agent::factory()->role('devops')->create(['status' => 'idle']);

        foreach ($toolsets as $toolset) {
            $agent->tools()->create(['tool' => $toolset]);
        }

        foreach ($permissions as $ability => $effect) {
            $agent->permissions()->create(['ability' => $ability, 'effect' => $effect]);
        }

        return $agent->fresh(['tools', 'permissions']);
    }

    private function task(Agent $agent): Task
    {
        return Task::factory()->create([
            'project_id' => Project::factory(),
            'assigned_agent_id' => $agent->id,
            'title' => '部署正式環境',
        ]);
    }

    private function runtime(): AgentRuntime
    {
        return $this->app->make(AgentRuntime::class);
    }
}
