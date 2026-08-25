<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Jobs\PlanProjectJob;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    public function test_creating_a_project_assigns_creator_and_workspace_path(): void
    {
        $user = $this->actingAsRole('developer');

        Queue::fake();

        $response = $this->postJson('/api/v1/ai-office/projects', [
            'name' => '台灣素食餐廳地圖',
            'description' => '示範專案',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', '台灣素食餐廳地圖')
            ->assertJsonPath('data.status', 'planning')
            ->assertJsonPath('data.created_by', $user->id);

        // workspace 路徑必須是相對的，而且要對得上實際建立出來的 id
        // ——存絕對路徑的話換機器就全錯（見 ProjectController 註解）。
        $id = $response->json('data.id');
        $this->assertSame("project-{$id}", $response->json('data.workspace_path'));

        Queue::assertPushed(PlanProjectJob::class, fn (PlanProjectJob $job) => $job->projectId === $id);
    }

    public function test_viewer_can_read_but_cannot_create(): void
    {
        Project::factory()->create();
        $this->actingAsRole('viewer');

        $this->getJson('/api/v1/ai-office/projects')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/ai-office/projects', ['name' => '不該建得起來'])
            ->assertStatus(403);

        $this->assertDatabaseCount('ai_office_projects', 1);
    }

    public function test_developer_cannot_delete_a_project(): void
    {
        $project = Project::factory()->create();
        $this->actingAsRole('developer');

        // 刪專案會連帶刪掉底下所有任務與執行紀錄，不是 developer 該有的權限。
        $this->deleteJson("/api/v1/ai-office/projects/{$project->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ai_office_projects', ['id' => $project->id]);
    }

    public function test_manager_can_delete_a_project(): void
    {
        $project = Project::factory()->create();
        $this->actingAsRole('manager');

        $this->deleteJson("/api/v1/ai-office/projects/{$project->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('ai_office_projects', ['id' => $project->id]);
    }

    public function test_consumer_role_cannot_reach_projects_at_all(): void
    {
        $this->actingAsRole('user');

        $this->getJson('/api/v1/ai-office/projects')->assertStatus(403);
    }

    public function test_project_list_filters_by_status_and_reports_task_count(): void
    {
        $active = Project::factory()->create(['status' => 'active']);
        Project::factory()->create(['status' => 'archived']);
        Task::factory()->count(3)->create(['project_id' => $active->id]);

        $this->actingAsRole('admin');

        $this->getJson('/api/v1/ai-office/projects?status=active')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.task_count', 3)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_updating_a_project_rejects_an_unknown_status(): void
    {
        $project = Project::factory()->create();
        $this->actingAsRole('admin');

        $this->patchJson("/api/v1/ai-office/projects/{$project->id}", ['status' => 'shipping'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->patchJson("/api/v1/ai-office/projects/{$project->id}", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
    }
}
