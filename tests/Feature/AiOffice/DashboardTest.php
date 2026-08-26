<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentError;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /ai-office/dashboard`（規格第 38／50 節）。
 *
 * 在這之前這支端點不存在，儀表板的數字是前端從已經載入的**分頁清單**自己數出來的
 * ——那種數字會隨著「載入了幾頁」變動，比 hardcode 更難發現是錯的。
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function project(): Project
    {
        return Project::factory()->create();
    }

    public function test_today_counts_only_tasks_completed_today(): void
    {
        // 應用程式時區是 UTC（config/app.php），所以「今天」從 UTC 00:00 起算。
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'UTC'));

        $project = $this->project();

        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'completed_at' => CarbonImmutable::parse('2026-08-26 09:00:00', 'UTC'),
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'completed',
            'completed_at' => CarbonImmutable::parse('2026-08-25 23:00:00', 'UTC'),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.completed', 1);
    }

    /**
     * 「等待處理」與「執行中」是**此刻的狀態**，不該被「今天」篩掉：一個昨天卡住
     * 等核准的任務，今天仍然要被看見——用 created_at 濾掉它才是真正的謊報。
     */
    public function test_waiting_and_running_are_current_state_not_limited_to_today(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'UTC'));

        $project = $this->project();

        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'waiting_review',
            'created_at' => CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC'),
        ]);
        Task::factory()->create([
            'project_id' => $project->id,
            'status' => 'running',
            'created_at' => CarbonImmutable::parse('2026-08-19 10:00:00', 'UTC'),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.waiting', 1)
            ->assertJsonPath('data.today.running', 1);
    }

    public function test_errors_count_today_only(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'UTC'));

        $project = $this->project();
        $agent = Agent::factory()->create();

        // created_at 不在 $fillable，而且 Eloquent 會自己蓋掉——要事後改。
        // 這正是「以為設定了時間、其實兩筆都是現在」那種安靜的測試錯誤。
        $today = AgentError::create([
            'agent_id' => $agent->id,
            'project_id' => $project->id,
            'type' => 'task_failed',
            'message' => '今天的錯誤',
        ]);
        $today->forceFill(['created_at' => CarbonImmutable::parse('2026-08-26 01:00:00', 'UTC')])->saveQuietly();

        $yesterday = AgentError::create([
            'agent_id' => $agent->id,
            'project_id' => $project->id,
            'type' => 'task_failed',
            'message' => '昨天的錯誤',
        ]);
        $yesterday->forceFill(['created_at' => CarbonImmutable::parse('2026-08-25 01:00:00', 'UTC')])->saveQuietly();

        $this->assertSame(
            '2026-08-25',
            $yesterday->fresh()->created_at->toDateString(),
            '時間沒有真的被改掉的話，這條測試會永遠綠',
        );

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.errors', 1);
    }

    /**
     * 每個合法狀態都要出現（沒有的補 0）。少一個 key 的話前端得自己 `?? 0`，
     * 而且看不出「是 0 還是這個狀態不存在」。
     */
    public function test_every_known_status_appears_even_when_zero(): void
    {
        Agent::factory()->create(['status' => 'working']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $data = $this->getJson('/api/v1/ai-office/dashboard')->assertOk()->json('data');

        foreach (Agent::STATUSES as $status) {
            $this->assertArrayHasKey($status, $data['agents']);
        }

        foreach (Project::STATUSES as $status) {
            $this->assertArrayHasKey($status, $data['projects']);
        }

        $this->assertSame(1, $data['agents']['working']);
        $this->assertSame(0, $data['agents']['offline']);
    }

    public function test_pending_approvals_are_counted(): void
    {
        $project = $this->project();
        $agent = Agent::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);

        Approval::create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'action' => 'write_file',
            'risk_level' => 'high',
            'reason' => '測試',
            'payload' => [],
            'status' => 'pending',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/dashboard')
            ->assertOk()
            ->assertJsonPath('data.approvals.pending', 1);
    }

    public function test_consumer_role_cannot_reach_the_dashboard(): void
    {
        // 一般消費者 `user` 註冊過也不該看得到 AI Office（`ai-office` 中介層）。
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/v1/ai-office/dashboard')->assertForbidden();
    }

    public function test_guests_cannot_reach_the_dashboard(): void
    {
        $this->getJson('/api/v1/ai-office/dashboard')->assertUnauthorized();
    }
}
