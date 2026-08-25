<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Jobs\ExecuteTaskJob;
use App\AiOffice\Jobs\PlanProjectJob;
use App\AiOffice\Jobs\RetryFailedTaskJob;
use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 規格第 27～32 節：規劃 → 建任務圖 → 指派 → 佇列執行 → 失敗重試。
 * 全程 MockProvider，不打真的 Claude API。
 */
class OrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private MockProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new MockProvider;
        $this->app->instance(LlmProviderInterface::class, $this->llm);
    }

    /**
     * @return array{ceo: Agent, backend: Agent, frontend: Agent, qa: Agent}
     */
    private function staff(): array
    {
        return [
            'ceo' => Agent::factory()->role('ceo')->create(['name' => 'AI 主管 Michael']),
            'backend' => Agent::factory()->role('backend')->create(['name' => '後端阿明', 'max_concurrency' => 1]),
            'frontend' => Agent::factory()->role('frontend')->create(['name' => '前端小王']),
            'qa' => Agent::factory()->role('qa')->create(['name' => '測試小美']),
        ];
    }

    private function planJson(): string
    {
        return json_encode([
            'project' => ['name' => '台灣素食餐廳地圖', 'description' => '從需求拆任務'],
            'tasks' => [
                ['title' => '設計資料庫', 'agent' => 'backend', 'priority' => 90, 'dependencies' => []],
                ['title' => '建立 REST API', 'agent' => 'backend', 'priority' => 80, 'dependencies' => ['設計資料庫']],
                ['title' => '建立前端地圖', 'agent' => 'frontend', 'priority' => 70, 'dependencies' => []],
                ['title' => 'QA', 'agent' => 'qa', 'priority' => 40, 'dependencies' => ['建立 REST API', '建立前端地圖']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_creating_a_project_dispatches_plan_job_instead_of_running_the_llm(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'developer']));

        $response = $this->postJson('/api/v1/ai-office/projects', [
            'name' => '台灣素食餐廳地圖',
            'description' => '示範',
        ])->assertStatus(201);

        Queue::assertPushed(PlanProjectJob::class, fn (PlanProjectJob $job) => $job->projectId === $response->json('data.id'));
        $this->assertSame('ai-office', (new PlanProjectJob($response->json('data.id')))->queue);
    }

    public function test_planner_builds_the_task_graph_assigns_agents_and_runs_ready_tasks(): void
    {
        $staff = $this->staff();
        $project = Project::factory()->create([
            'name' => '台灣素食餐廳地圖',
            'description' => '我要建立一個台灣素食餐廳地圖。',
            'status' => 'planning',
        ]);

        $this->llm->pushText($this->planJson());
        $this->llm->pushText('資料庫 schema 已完成。');
        $this->llm->pushText('前端地圖已完成。');
        $this->llm->pushText('REST API 已完成。');
        $this->llm->pushText('QA 全部通過。');

        app(AgentOrchestrator::class)->planProject($project);

        $project->refresh();
        $this->assertSame('completed', $project->status);
        $this->assertEqualsCanonicalizing(
            ['設計資料庫', '建立 REST API', '建立前端地圖', 'QA'],
            $project->tasks()->pluck('title')->all(),
        );

        $api = Task::query()->where('title', '建立 REST API')->firstOrFail();
        $this->assertTrue($api->dependencies()->where('title', '設計資料庫')->exists());
        $this->assertSame($staff['backend']->id, $api->assigned_agent_id);
        $this->assertSame('completed', $api->status);

        $qa = Task::query()->where('title', 'QA')->firstOrFail();
        $this->assertSame($staff['qa']->id, $qa->assigned_agent_id);
        $this->assertSame('completed', $qa->status);

        $this->assertDatabaseHas('ai_office_task_assignments', [
            'task_id' => $api->id,
            'agent_id' => $staff['backend']->id,
        ]);
        $this->assertDatabaseHas('ai_office_activities', [
            'project_id' => $project->id,
            'type' => 'ProjectPlanned',
        ]);
        $this->assertDatabaseHas('ai_office_token_usages', [
            'project_id' => $project->id,
            'agent_id' => $staff['ceo']->id,
            'task_id' => null,
        ]);
    }

    public function test_planner_retries_invalid_json_then_accepts_a_valid_plan(): void
    {
        $this->staff();
        $project = Project::factory()->create(['status' => 'planning']);

        $this->llm->pushText('1. 做資料庫 2. 做 API');
        $this->llm->pushText($this->planJson());
        $this->llm->pushText('ok');
        $this->llm->pushText('ok');
        $this->llm->pushText('ok');
        $this->llm->pushText('ok');

        app(AgentOrchestrator::class)->planProject($project);

        $this->assertSame('completed', $project->fresh()->status);
        $this->assertGreaterThanOrEqual(2, count($this->llm->received()));
        $this->assertStringContainsString('找不到 JSON', $this->llm->received()[1]->messages[0]['content']);
    }

    public function test_natural_language_plan_does_not_create_tasks(): void
    {
        $this->staff();
        $project = Project::factory()->create(['status' => 'planning']);
        config(['ai_office.planner.max_attempts' => 1]);

        $this->llm->pushText("請執行以下任務：\n做資料庫\n做 API");

        app(AgentOrchestrator::class)->planProject($project);

        $this->assertSame('failed', $project->fresh()->status);
        $this->assertSame(0, $project->tasks()->count());
        $this->assertDatabaseHas('ai_office_activities', [
            'project_id' => $project->id,
            'type' => 'ProjectPlanningFailed',
        ]);
    }

    public function test_failed_task_is_retried_until_max_retries_then_ceo_is_notified(): void
    {
        $this->staff();
        $project = Project::factory()->create(['status' => 'planning']);
        config(['ai_office.limits.max_retries' => 2]);
        config(['ai_office.jobs.retry_delay_seconds' => 0]);

        $this->llm->pushText(json_encode([
            'project' => ['name' => $project->name],
            'tasks' => [
                ['title' => '設計資料庫', 'agent' => 'backend', 'dependencies' => []],
            ],
        ], JSON_UNESCAPED_UNICODE));

        app(AgentOrchestrator::class)->planProject($project);

        $task = $project->tasks()->firstOrFail();
        // 若 ExecuteTaskJob 加回 ShouldBeUnique，sync 佇列重試會被 unique lock 吃掉，
        // 狀態會停在 assigned、retry_count 只有 1。
        $this->assertSame('failed', $task->status);
        $this->assertSame(2, $task->retry_count);
        $this->assertSame(2, $task->runs()->count());
        $this->assertDatabaseHas('ai_office_agent_errors', ['task_id' => $task->id]);
        $this->assertDatabaseHas('ai_office_activities', [
            'task_id' => $task->id,
            'type' => 'TaskPermanentlyFailed',
        ]);
        $this->assertSame('failed', $project->fresh()->status);
    }

    public function test_busy_agent_does_not_receive_another_execute_job(): void
    {
        Queue::fake();
        $backend = Agent::factory()->role('backend')->create(['max_concurrency' => 1]);
        $project = Project::factory()->create(['status' => 'active']);

        Task::factory()->create([
            'project_id' => $project->id,
            'assigned_agent_id' => $backend->id,
            'status' => 'running',
            'title' => '正在跑',
        ]);
        $waiting = Task::factory()->create([
            'project_id' => $project->id,
            'assigned_agent_id' => $backend->id,
            'status' => 'assigned',
            'title' => '排隊中',
        ]);

        app(AgentOrchestrator::class)->dispatchReadyTasks($project);

        Queue::assertNotPushed(
            ExecuteTaskJob::class,
            fn (ExecuteTaskJob $job) => $job->taskId === $waiting->id,
        );
    }

    public function test_jobs_use_the_configured_queue_name(): void
    {
        config(['ai_office.queue' => 'ai-office-test']);

        $this->assertSame('ai-office-test', (new PlanProjectJob(1))->queue);
        $this->assertSame('ai-office-test', (new ExecuteTaskJob(1))->queue);
        $this->assertSame('ai-office-test', (new RetryFailedTaskJob(1))->queue);
    }

    public function test_manual_task_without_an_agent_is_not_executed(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => 'developer']));
        $project = Project::factory()->create();

        $this->postJson("/api/v1/ai-office/projects/{$project->id}/tasks", [
            'title' => '人手建的任務',
        ])->assertStatus(201);

        Queue::assertNotPushed(ExecuteTaskJob::class);
        $this->assertSame('pending', Task::query()->where('title', '人手建的任務')->value('status'));
    }
}
