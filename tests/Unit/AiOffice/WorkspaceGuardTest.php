<?php

namespace Tests\Unit\AiOffice;

use App\AiOffice\Models\Project;
use App\AiOffice\Security\WorkspaceEscapeException;
use App\AiOffice\Security\WorkspaceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class WorkspaceGuardTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    public function test_relative_paths_inside_the_project_resolve(): void
    {
        $project = $this->prepareWorkspace();
        $guard = app(WorkspaceGuard::class);
        $root = $guard->rootFor($project);
        file_put_contents($root.'/notes.md', 'hi');

        $resolved = $guard->resolve($project, 'notes.md');

        $this->assertSame($root.'/notes.md', $resolved);
        $this->assertSame('notes.md', $guard->relativeToRoot($project, $resolved));
    }

    public function test_dotdot_cannot_escape_the_workspace(): void
    {
        $project = $this->prepareWorkspace();

        $this->expectException(WorkspaceEscapeException::class);
        app(WorkspaceGuard::class)->resolve($project, '../secret.txt');
    }

    public function test_absolute_system_paths_are_rejected(): void
    {
        $project = $this->prepareWorkspace();

        $this->expectException(WorkspaceEscapeException::class);
        app(WorkspaceGuard::class)->resolve($project, '/etc/passwd');
    }

    public function test_null_bytes_are_rejected(): void
    {
        $project = $this->prepareWorkspace();

        $this->expectException(WorkspaceEscapeException::class);
        app(WorkspaceGuard::class)->resolve($project, "notes.md\0/etc/passwd");
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $project = $this->prepareWorkspace();
        $root = app(WorkspaceGuard::class)->rootFor($project);
        symlink('/etc', $root.'/escape');

        $this->expectException(WorkspaceEscapeException::class);
        app(WorkspaceGuard::class)->resolve($project, 'escape/passwd');
    }

    public function test_another_projects_workspace_is_out_of_bounds(): void
    {
        $alpha = $this->prepareWorkspace();
        $bravo = Project::factory()->create();
        $bravo->update(['workspace_path' => 'project-'.$bravo->id]);

        $guard = app(WorkspaceGuard::class);
        file_put_contents($guard->rootFor($alpha).'/secret.txt', 'nope');

        $this->expectException(WorkspaceEscapeException::class);
        $guard->resolve($bravo, '../project-'.$alpha->id.'/secret.txt');
    }

    public function test_malicious_workspace_path_is_rejected(): void
    {
        $project = $this->prepareWorkspace();
        $project->update(['workspace_path' => '../etc']);

        $this->expectException(WorkspaceEscapeException::class);
        app(WorkspaceGuard::class)->rootFor($project);
    }
}
