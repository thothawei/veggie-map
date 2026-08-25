<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role = 'developer'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    public function test_creating_a_task_with_dependencies_stores_the_edges(): void
    {
        $project = Project::factory()->create();
        $database = Task::factory()->create(['project_id' => $project->id, 'title' => '設計資料庫']);
        $this->actingAsRole();

        $response = $this->postJson("/api/v1/ai-office/projects/{$project->id}/tasks", [
            'title' => '建立 REST API',
            'priority' => 80,
            'dependencies' => [$database->id],
        ])->assertStatus(201)
            ->assertJsonPath('data.title', '建立 REST API')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.priority', 80)
            ->assertJsonPath('data.dependencies', [$database->id]);

        $this->assertDatabaseHas('ai_office_task_dependencies', [
            'task_id' => $response->json('data.id'),
            'depends_on_task_id' => $database->id,
        ]);
    }

    public function test_dependencies_from_another_project_are_rejected(): void
    {
        $project = Project::factory()->create();
        $foreignTask = Task::factory()->create();  // 屬於另一個 factory 生出來的專案
        $this->actingAsRole();

        // 跨專案相依等於打破專案隔離（規格第 42 節），必須在驗證層就擋掉。
        $this->postJson("/api/v1/ai-office/projects/{$project->id}/tasks", [
            'title' => '偷連別的專案',
            'dependencies' => [$foreignTask->id],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('ai_office_task_dependencies', 0);
    }

    public function test_task_is_not_ready_until_every_dependency_succeeded(): void
    {
        $project = Project::factory()->create();
        $first = Task::factory()->create(['project_id' => $project->id, 'status' => 'completed']);
        $second = Task::factory()->create(['project_id' => $project->id, 'status' => 'running']);

        $task = Task::factory()->create(['project_id' => $project->id]);
        $task->dependencies()->sync([$first->id, $second->id]);

        $this->actingAsRole();

        $this->getJson("/api/v1/ai-office/tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.dependencies_satisfied', false);

        $second->update(['status' => 'completed']);

        $this->getJson("/api/v1/ai-office/tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.dependencies_satisfied', true);
    }

    public function test_a_failed_dependency_does_not_unblock_the_task(): void
    {
        // 這是最容易寫錯的一條：如果判斷寫成「前置不是 pending 就算過」，
        // 前面壞掉的整條鏈會繼續往下跑。
        $project = Project::factory()->create();
        $failed = Task::factory()->create(['project_id' => $project->id, 'status' => 'failed']);

        $task = Task::factory()->create(['project_id' => $project->id]);
        $task->dependencies()->sync([$failed->id]);

        $this->assertFalse($task->dependenciesSatisfied());
    }

    public function test_task_list_filters_by_status_and_sorts_by_priority(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create(['project_id' => $project->id, 'priority' => 10, 'title' => '低']);
        Task::factory()->create(['project_id' => $project->id, 'priority' => 90, 'title' => '高']);
        Task::factory()->create(['project_id' => $project->id, 'status' => 'completed', 'title' => '完成']);

        $this->actingAsRole();

        $this->getJson("/api/v1/ai-office/projects/{$project->id}/tasks?status=pending")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', '高')
            ->assertJsonPath('data.1.title', '低');
    }

    public function test_task_can_be_assigned_to_an_agent(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agent = Agent::factory()->role('backend')->create();

        $this->actingAsRole();

        $this->patchJson("/api/v1/ai-office/tasks/{$task->id}", [
            'assigned_agent_id' => $agent->id,
            'status' => 'assigned',
        ])->assertStatus(200)
            ->assertJsonPath('data.assigned_agent_id', $agent->id)
            ->assertJsonPath('data.status', 'assigned');
    }

    public function test_viewer_cannot_create_tasks(): void
    {
        $project = Project::factory()->create();
        $this->actingAsRole('viewer');

        $this->postJson("/api/v1/ai-office/projects/{$project->id}/tasks", ['title' => '不該建得起來'])
            ->assertStatus(403);

        $this->assertDatabaseCount('ai_office_tasks', 0);
    }
}
