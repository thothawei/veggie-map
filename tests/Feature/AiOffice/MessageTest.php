<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Message;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\AiOffice\Services\AgentMessenger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 規格第 34 節：Agent 之間的訊息。
 *
 * 這張表從 Phase 2 就建好了，但在這之前**一行寫入都沒有**——規格舉的四個例子
 * （CEO→Backend 派工、Backend→CEO 回報完成、失敗時通知 CEO）一個都沒發生過。
 */
class MessageTest extends TestCase
{
    use RefreshDatabase;

    private function ceo(): Agent
    {
        return Agent::factory()->create([
            'role' => (string) config('ai_office.planner.agent_role'),
            'name' => 'AI 主管 Michael',
        ]);
    }

    public function test_assigning_a_task_sends_a_message_from_the_ceo(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend', 'name' => '後端阿明', 'status' => 'idle']);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'title' => '建立 API']);

        app(AgentOrchestrator::class)->assign($task, 'backend');

        $message = Message::query()->firstOrFail();

        $this->assertSame($ceo->id, $message->from_agent_id);
        $this->assertSame($backend->id, $message->to_agent_id);
        $this->assertStringContainsString('建立 API', $message->content);
    }

    public function test_completing_a_task_reports_back_to_the_ceo(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend', 'name' => '後端阿明']);
        $project = Project::factory()->create();
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => '建立 API',
            'status' => 'completed',
            'assigned_agent_id' => $backend->id,
        ]);
        $run = TaskRun::create([
            'task_id' => $task->id,
            'agent_id' => $backend->id,
            'run_number' => 1,
            'status' => 'completed',
        ]);

        app(AgentOrchestrator::class)->afterTaskRun($task, $run);

        $message = Message::query()->where('from_agent_id', $backend->id)->firstOrFail();

        $this->assertSame($ceo->id, $message->to_agent_id);
        $this->assertStringContainsString('已完成', $message->content);
    }

    /**
     * `handleFailure()` 一直寫著一則「通知 CEO」的 Activity，但沒有任何東西真的
     * 送到 CEO 手上——這是規格第 34 節最明顯的缺口。
     */
    public function test_permanent_failure_actually_notifies_the_ceo(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend']);
        $project = Project::factory()->create();
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => '建立 API',
            'status' => 'failed',
            'assigned_agent_id' => $backend->id,
            'retry_count' => 3,
            'max_retries' => 3,
            'error' => 'SQLSTATE 連線失敗',
        ]);

        app(AgentOrchestrator::class)->handleFailure($task);

        $message = Message::query()->where('to_agent_id', $ceo->id)->firstOrFail();

        $this->assertStringContainsString('仍然失敗', $message->content);
        $this->assertStringContainsString('SQLSTATE', $message->content, '最後一次的錯誤要帶上，不然 CEO 得自己去翻');
    }

    /**
     * 訊息的意義來自「誰對誰」。缺一邊的紀錄留著只會讓這張表變成第二份 Activity。
     */
    public function test_no_message_is_written_when_one_side_is_missing(): void
    {
        // 沒有 CEO（例如 seeder 沒建）。
        $backend = Agent::factory()->create(['role' => 'backend', 'status' => 'idle']);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);

        app(AgentOrchestrator::class)->assign($task, 'backend');

        $this->assertSame(0, Message::query()->count());
        // 但派工本身仍然要成立——訊息是附加的紀錄，不是前置條件。
        $this->assertSame($backend->id, $task->fresh()->assigned_agent_id);
    }

    public function test_sender_and_recipient_being_the_same_agent_writes_nothing(): void
    {
        $ceo = $this->ceo();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'assigned_agent_id' => $ceo->id]);

        // CEO 自己執行的任務完成時，「CEO 通知 CEO」沒有意義。
        app(AgentMessenger::class)->taskCompleted($task);

        $this->assertSame(0, Message::query()->count());
    }

    public function test_endpoint_lists_messages_with_both_names(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend', 'name' => '後端阿明']);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id, 'title' => '建立 API']);

        app(AgentMessenger::class)->taskAssigned($task, $backend);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.from.name', 'AI 主管 Michael')
            ->assertJsonPath('data.0.to.name', '後端阿明')
            ->assertJsonPath('data.0.task_id', $task->id);
    }

    public function test_endpoint_supports_incremental_fetch(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend']);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);

        $first = app(AgentMessenger::class)->taskAssigned($task, $backend);
        // taskCompleted 的寄件人是 task 的執行 Agent，沒有指派就不會有第二則。
        $task->update(['assigned_agent_id' => $backend->id]);
        app(AgentMessenger::class)->taskCompleted($task->fresh());

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/messages?after_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_messages_of_another_project_are_not_visible(): void
    {
        $ceo = $this->ceo();
        $backend = Agent::factory()->create(['role' => 'backend']);
        $mine = Project::factory()->create();
        $other = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $other->id]);

        app(AgentMessenger::class)->taskAssigned($task, $backend);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/projects/{$mine->id}/messages")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_consumer_role_cannot_read_messages(): void
    {
        $project = Project::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/messages")->assertForbidden();
    }
}
