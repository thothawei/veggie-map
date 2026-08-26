<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Jobs\ExecuteTaskJob;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\AiOffice\Runtime\AgentRuntime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 規格第 50 節的 POST /tasks/{id}/retry 與 /cancel。
 */
class TaskRetryCancelTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role = 'developer'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function failedTask(array $attributes = []): Task
    {
        $project = Project::factory()->create();
        $agent = Agent::factory()->create(['role' => 'backend', 'status' => 'error']);

        // 順序很重要：PHP 的 `+` 是左邊優先，寫成 `[預設] + $attributes` 的話
        // 傳進來的覆寫會被預設值蓋掉，測試就會在測一個跟自己想的不一樣的情境。
        return Task::factory()->create($attributes + [
            'project_id' => $project->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'failed',
            'error' => '上一輪爆了',
            'retry_count' => 3,
            'max_retries' => 3,
        ]);
    }

    public function test_retry_requeues_a_failed_task_and_clears_the_error(): void
    {
        Queue::fake();
        $task = $this->failedTask();
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'assigned');

        $task->refresh();
        $this->assertNull($task->error);
        Queue::assertPushed(ExecuteTaskJob::class);
    }

    public function test_manual_retry_works_even_after_max_retries_is_reached(): void
    {
        Queue::fake();
        // retry_count 已經等於 max_retries：自動重試在這裡會放棄，人工重試不該放棄
        // ——那正是使用者會去按那顆按鈕的時刻。
        $task = $this->failedTask(['retry_count' => 3, 'max_retries' => 3]);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")->assertOk();

        $this->assertSame('assigned', $task->fresh()->status);
    }

    public function test_manual_retry_does_not_reset_the_retry_count(): void
    {
        Queue::fake();
        $task = $this->failedTask(['retry_count' => 2, 'max_retries' => 5]);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")->assertOk();

        // 失敗過兩次是事實，不因為換人按而消失。
        $this->assertSame(2, $task->fresh()->retry_count);
    }

    public function test_a_cancelled_task_can_be_retried(): void
    {
        Queue::fake();
        $task = $this->failedTask(['status' => 'cancelled']);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")->assertOk();

        $this->assertSame('assigned', $task->fresh()->status);
    }

    public function test_retrying_a_completed_task_is_rejected(): void
    {
        Queue::fake();
        $task = $this->failedTask(['status' => 'completed']);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('completed', $task->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_cancel_marks_a_queued_task_cancelled_and_frees_the_agent(): void
    {
        Queue::fake();
        $agent = Agent::factory()->create(['role' => 'backend', 'status' => 'working']);
        $task = Task::factory()->create([
            'project_id' => Project::factory()->create()->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'assigned',
        ]);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/cancel", ['reason' => '需求改了'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            // 沒在跑的任務，取消就是立刻生效，不需要等步進點。
            ->assertJsonPath('data.stops_after_current_step', false);

        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame('需求改了', $task->fresh()->error);
        // Agent 的並行額度要放掉，否則之後什麼都派不進去。
        $this->assertSame('idle', $agent->fresh()->status);
    }

    public function test_cancelling_a_running_task_is_cooperative(): void
    {
        Queue::fake();
        $agent = Agent::factory()->create(['role' => 'backend', 'status' => 'working']);
        $task = Task::factory()->create([
            'project_id' => Project::factory()->create()->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'running',
        ]);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            // 呼叫端必須知道「已取消」不等於「此刻已經停了」。
            ->assertJsonPath('data.stops_after_current_step', true);

        // 正在跑的那一輪還沒結束，Agent 由 AgentRuntime 收尾時歸位，不在這裡搶著改。
        $this->assertSame('working', $agent->fresh()->status);
    }

    public function test_cancelling_a_finished_task_is_rejected(): void
    {
        $task = $this->failedTask(['status' => 'completed']);
        $this->actingAsRole();

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_a_cancelled_task_is_not_executed_by_the_worker(): void
    {
        $agent = Agent::factory()->create(['role' => 'backend', 'status' => 'idle']);
        $task = Task::factory()->create([
            'project_id' => Project::factory()->create()->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'cancelled',
        ]);

        // 已經排進佇列的 job 之後才被撈到：開頭的狀態檢查必須擋住它，
        // 否則「取消」只是畫面上的字。
        (new ExecuteTaskJob($task->id))->handle(
            app(AgentRuntime::class),
            app(AgentOrchestrator::class),
        );

        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertDatabaseCount('ai_office_task_runs', 0);
    }

    public function test_viewer_cannot_retry_or_cancel(): void
    {
        $task = $this->failedTask();
        $this->actingAsRole('viewer');

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/retry")->assertStatus(403);
        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/cancel")->assertStatus(403);
    }
}
