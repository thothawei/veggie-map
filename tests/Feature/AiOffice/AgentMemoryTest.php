<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentMemory;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Runtime\AgentRuntime;
use App\AiOffice\Services\AgentMemoryService;
use App\AiOffice\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 規格第 41 節的 Agent 記憶。表從 Phase 2 就在，Phase 10 才真的接起來——
 * 這裡驗的是「有寫進去」「下次真的會被讀出來放進 prompt」兩件事，缺一個就等於沒做。
 */
class AgentMemoryTest extends TestCase
{
    use RefreshDatabase;

    private MockProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new MockProvider;
        $this->app->instance(LlmProviderInterface::class, $this->llm);
        $this->app->instance(ToolRegistry::class, new ToolRegistry);
    }

    private function memories(): AgentMemoryService
    {
        return $this->app->make(AgentMemoryService::class);
    }

    private function task(Agent $agent, ?Project $project = null): Task
    {
        return Task::factory()->create([
            'project_id' => ($project ?? Project::factory()->create())->id,
            'assigned_agent_id' => $agent->id,
            'title' => '建立 REST API',
        ]);
    }

    public function test_completing_a_task_writes_a_task_result_memory(): void
    {
        $agent = Agent::factory()->create(['status' => 'idle']);
        $task = $this->task($agent);

        $this->llm->pushText('端點做好了，在 routes/api.php。');
        $this->app->make(AgentRuntime::class)->run($task->fresh(['agent']));

        $memory = AgentMemory::where('agent_id', $agent->id)->firstOrFail();

        $this->assertSame('task_result', $memory->memory_type);
        $this->assertSame($task->project_id, $memory->project_id);
        $this->assertStringContainsString('建立 REST API', $memory->content);
        $this->assertStringContainsString('端點做好了', $memory->content);
    }

    public function test_a_failed_task_is_remembered_as_an_error_pattern_with_higher_importance(): void
    {
        $agent = Agent::factory()->create(['status' => 'idle']);
        $task = $this->task($agent);

        // 沒有排任何回覆 → MockProvider 會丟例外 → 任務失敗。
        $this->app->make(AgentRuntime::class)->run($task->fresh(['agent']));

        $memory = AgentMemory::where('agent_id', $agent->id)->firstOrFail();

        // 「上次為什麼掛掉」比「上次做了什麼」更該先被想起來，所以重要度比較高。
        $this->assertSame('error_pattern', $memory->memory_type);
        $this->assertSame(7, $memory->importance);
        $this->assertGreaterThan(
            (int) config('ai_office.memory.importance.task_result'),
            $memory->importance,
        );
    }

    public function test_remembered_facts_are_sent_to_the_model_on_the_next_run(): void
    {
        $agent = Agent::factory()->create(['status' => 'idle']);
        $project = Project::factory()->create();

        $this->memories()->remember($agent, $project, 'technical_decision', '這個專案用 MySQL 不是 Postgres。');

        $this->llm->pushText('了解');
        $this->app->make(AgentRuntime::class)->run($this->task($agent, $project)->fresh(['agent']));

        $prompt = $this->llm->received()[0]->messages[0]['content'];

        $this->assertStringContainsString('你先前記得的事', $prompt);
        $this->assertStringContainsString('這個專案用 MySQL 不是 Postgres。', $prompt);
    }

    public function test_the_prompt_recorded_on_the_run_is_the_one_that_was_actually_sent(): void
    {
        $agent = Agent::factory()->create(['status' => 'idle']);
        $project = Project::factory()->create();
        $this->memories()->remember($agent, $project, 'user_preference', '回報時用繁體中文。');

        $this->llm->pushText('好');
        $run = $this->app->make(AgentRuntime::class)->run($this->task($agent, $project)->fresh(['agent']));

        // task_runs.input 是事後查案唯一的憑據，跟真的送出去的必須是同一份。
        $this->assertSame($this->llm->received()[0]->messages[0]['content'], $run->input['prompt']);
    }

    public function test_other_agents_memories_are_not_recalled(): void
    {
        $mine = Agent::factory()->create();
        $other = Agent::factory()->create();
        $project = Project::factory()->create();

        $this->memories()->remember($other, $project, 'technical_decision', '別人的記憶。');

        $this->assertCount(0, $this->memories()->recall($mine, $project));
    }

    public function test_cross_project_memories_are_recalled_in_every_project(): void
    {
        $agent = Agent::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        // project_id 為 null＝跨專案通則（例如使用者偏好），換專案不該就忘記。
        $this->memories()->remember($agent, null, 'user_preference', '一律用繁體中文。');
        $this->memories()->remember($agent, $projectA, 'project_context', '只跟 A 專案有關。');

        $recalledInB = $this->memories()->recall($agent, $projectB)->pluck('content')->all();

        $this->assertContains('一律用繁體中文。', $recalledInB);
        $this->assertNotContains('只跟 A 專案有關。', $recalledInB);
    }

    public function test_recall_is_ordered_by_importance_and_capped_by_config(): void
    {
        config(['ai_office.memory.recall_limit' => 2]);

        $agent = Agent::factory()->create();
        $project = Project::factory()->create();

        $this->memories()->remember($agent, $project, 'project_context', '不重要', 1);
        $this->memories()->remember($agent, $project, 'project_context', '最重要', 9);
        $this->memories()->remember($agent, $project, 'project_context', '中等', 5);

        $recalled = $this->memories()->recall($agent, $project)->pluck('content')->all();

        // 上限存在的理由是成本：記憶會進 prompt，每次請求都要為它付 token。
        $this->assertSame(['最重要', '中等'], $recalled);
    }

    public function test_long_content_is_truncated_instead_of_rejected(): void
    {
        config(['ai_office.memory.max_content_length' => 60]);

        $agent = Agent::factory()->create();
        $memory = $this->memories()->remember($agent, null, 'task_result', str_repeat('長', 200));

        $this->assertNotNull($memory);
        $this->assertSame(60, mb_strlen($memory->content));
        $this->assertStringEndsWith('…', $memory->content);
    }

    public function test_disabling_memory_stops_both_writing_and_recalling(): void
    {
        config(['ai_office.memory.enabled' => false]);

        $agent = Agent::factory()->create(['status' => 'idle']);
        $project = Project::factory()->create();

        $this->llm->pushText('做完了');
        $this->app->make(AgentRuntime::class)->run($this->task($agent, $project)->fresh(['agent']));

        $this->assertSame(0, AgentMemory::count());
        $this->assertStringNotContainsString('你先前記得的事', $this->llm->received()[0]->messages[0]['content']);
    }

    public function test_unknown_memory_type_is_rejected_before_it_reaches_the_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->memories()->remember(Agent::factory()->create(), null, 'gossip', '八卦');
    }

    public function test_memories_endpoint_lists_them_by_importance(): void
    {
        $agent = Agent::factory()->create();
        $project = Project::factory()->create();

        $this->memories()->remember($agent, $project, 'project_context', '普通', 3);
        $this->memories()->remember($agent, $project, 'error_pattern', '很重要', 9);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}/memories")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.content', '很重要')
            ->assertJsonPath('data.0.memory_type', 'error_pattern')
            // 面板要能說明「前幾則才會真的進 prompt」。
            ->assertJsonPath('meta.recall_limit', (int) config('ai_office.memory.recall_limit'));
    }

    public function test_memories_endpoint_rejects_the_consumer_role(): void
    {
        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson("/api/v1/ai-office/agents/{$agent->id}/memories")->assertStatus(403);
    }
}
