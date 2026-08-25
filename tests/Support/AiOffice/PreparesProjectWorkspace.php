<?php

namespace Tests\Support\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Tools\ToolContext;
use Illuminate\Support\Facades\File;

trait PreparesProjectWorkspace
{
    private string $workspaceRoot;

    protected function prepareWorkspace(?Project $project = null): Project
    {
        $this->workspaceRoot = sys_get_temp_dir().'/ai-office-ws-'.uniqid('', true);
        mkdir($this->workspaceRoot, 0755, true);
        config(['ai_office.workspace_root' => $this->workspaceRoot]);

        $project ??= Project::factory()->create();
        $project->update(['workspace_path' => 'project-'.$project->id]);

        app(WorkspaceGuard::class)->rootFor($project);

        return $project;
    }

    protected function toolContext(Project $project, ?Agent $agent = null): ToolContext
    {
        $agent ??= Agent::factory()->role('backend')->create();
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'assigned_agent_id' => $agent->id,
        ]);
        $run = TaskRun::create([
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'run_number' => 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        return new ToolContext($agent, $task->load('project'), $run);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspaceRoot) && is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }

        parent::tearDown();
    }
}
