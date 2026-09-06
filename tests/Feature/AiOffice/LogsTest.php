<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /ai-office/activities`（規格第 44 節 `LogsView`）：跨專案的執行紀錄。
 * 選 `activities` 當這一頁的粒度（見 ActivityController::across() 開頭的說明），
 * 不是 `tool_executions`／`task_runs`，所以測試著重在「跨專案」與「篩選」，
 * 不是重複 ActivityStreamTest 已經釘住的單一專案排序行為。
 */
class LogsTest extends TestCase
{
    use RefreshDatabase;

    private function activity(Project $project, array $attributes = []): Activity
    {
        return Activity::create($attributes + [
            'project_id' => $project->id,
            'type' => 'TaskStarted',
            'description' => '事件',
        ]);
    }

    public function test_lists_activities_across_projects_newest_first(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();

        $first = $this->activity($a);
        $second = $this->activity($b);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/activities')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_includes_the_project_name_since_this_is_the_cross_project_view(): void
    {
        $project = Project::factory()->create(['name' => '待辦 API']);
        $this->activity($project);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/activities')
            ->assertOk()
            ->assertJsonPath('data.0.project_name', '待辦 API');
    }

    public function test_can_be_filtered_by_project(): void
    {
        $mine = Project::factory()->create();
        $other = Project::factory()->create();

        $this->activity($mine);
        $this->activity($other);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/v1/ai-office/activities?project_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_id', $mine->id);
    }

    public function test_can_be_filtered_by_type_and_agent(): void
    {
        $project = Project::factory()->create();
        $agent = Agent::factory()->create();

        $this->activity($project, ['type' => 'AgentStatusChanged', 'agent_id' => $agent->id]);
        $this->activity($project, ['type' => 'TaskCompleted']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/activities?type=AgentStatusChanged')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'AgentStatusChanged');

        $this->getJson("/api/v1/ai-office/activities?agent_id={$agent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_id', $agent->id);
    }

    public function test_response_is_paginated(): void
    {
        $project = Project::factory()->create();
        $this->activity($project);
        $this->activity($project);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/activities?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_consumer_role_cannot_read_the_cross_project_log(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/v1/ai-office/activities')->assertStatus(403);
    }
}
