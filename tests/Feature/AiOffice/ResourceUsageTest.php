<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/v1/ai-office/resource-usage`（規格第 39、44 節）。
 *
 * 規格明講拿不到 host CPU/Memory 就用 application-level metrics，但 UI 要標明
 * 資料來源——所以每個測試都釘住 `source` 欄位存在，以及數字是「查出來的」
 * 而不是寫死的（用 Task/Agent 的真實狀態筆數比對）。
 */
class ResourceUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_application_as_the_data_source(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/resource-usage')
            ->assertOk()
            ->assertJsonPath('data.source', 'application');
    }

    public function test_task_and_agent_counts_reflect_real_rows_not_hardcoded_values(): void
    {
        $project = Project::factory()->create();

        Task::factory()->for($project)->create(['status' => 'running']);
        Task::factory()->for($project)->create(['status' => 'running']);
        Task::factory()->for($project)->create(['status' => 'waiting_review']);
        Task::factory()->for($project)->create(['status' => 'completed']);

        Agent::factory()->create(['status' => 'working']);
        Agent::factory()->create(['status' => 'idle']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/resource-usage')
            ->assertOk()
            ->assertJsonPath('data.tasks.running_tasks', 2)
            ->assertJsonPath('data.tasks.waiting_review_tasks', 1)
            ->assertJsonPath('data.tasks.working_agents', 1);
    }

    public function test_php_memory_and_host_load_shapes_are_present(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $response = $this->getJson('/api/v1/ai-office/resource-usage')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'source',
                'host_load' => ['available', 'one', 'five', 'fifteen'],
                'php_memory' => ['used_bytes', 'peak_bytes', 'limit'],
                'queue' => ['connection', 'queue', 'pending_jobs'],
                'tasks' => ['running_tasks', 'waiting_review_tasks', 'working_agents'],
                'sandbox' => ['docker_available', 'docker_tool_enabled', 'cpu_limit', 'memory_limit_mb'],
            ],
        ]);

        // PHP process 一定用得到記憶體，這個數字不會是 0——反向驗證這條真的
        // 讀到 memory_get_usage()，不是回一個寫死的殼。
        $this->assertGreaterThan(0, $response->json('data.php_memory.used_bytes'));
    }

    public function test_sandbox_limits_come_from_config_not_hardcoded(): void
    {
        config(['ai_office.sandbox.cpu_limit' => '2.5', 'ai_office.sandbox.memory_limit_mb' => 999]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/resource-usage')
            ->assertOk()
            ->assertJsonPath('data.sandbox.cpu_limit', '2.5')
            ->assertJsonPath('data.sandbox.memory_limit_mb', 999);
    }

    public function test_consumer_role_cannot_read_resource_usage(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/v1/ai-office/resource-usage')->assertStatus(403);
    }

    public function test_guest_cannot_read_resource_usage(): void
    {
        $this->getJson('/api/v1/ai-office/resource-usage')->assertStatus(401);
    }
}
