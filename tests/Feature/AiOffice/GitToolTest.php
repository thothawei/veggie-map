<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Security\WorkspaceEscapeException;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Tools\GitTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class GitToolTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    public function test_status_add_and_commit_run_inside_the_project_workspace(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $guard = app(WorkspaceGuard::class);
        $this->initRepo($project);

        file_put_contents($guard->rootFor($project).'/app.php', '<?php');

        $add = (new GitTool('git_add', $guard))->execute(['paths' => ['app.php']], $ctx);
        $this->assertSame(0, $add['exit_code'], $add['stderr']);

        $commit = (new GitTool('git_commit', $guard))->execute(['message' => 'init'], $ctx);
        $this->assertSame(0, $commit['exit_code'], $commit['stderr']);

        $log = (new GitTool('git_log', $guard))->execute([], $ctx);
        $this->assertSame(0, $log['exit_code']);
        $this->assertStringContainsString('init', $log['stdout']);
    }

    public function test_push_to_a_protected_branch_is_rejected_without_running_git(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('受保護');
        (new GitTool('git_push', app(WorkspaceGuard::class)))->execute(['branch' => 'main'], $ctx);
    }

    public function test_protected_branches_come_from_config(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config(['ai_office.tools.git.protected_branches' => ['develop']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('develop');
        (new GitTool('git_push', app(WorkspaceGuard::class)))->execute(['branch' => 'develop'], $ctx);
    }

    public function test_add_rejects_paths_outside_the_workspace(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $this->initRepo($project);

        $this->expectException(WorkspaceEscapeException::class);
        (new GitTool('git_add', app(WorkspaceGuard::class)))->execute([
            'paths' => ['../secret.txt'],
        ], $ctx);
    }

    private function initRepo($project): void
    {
        $root = app(WorkspaceGuard::class)->rootFor($project);
        $run = function (array $args) use ($root): void {
            $process = new Process(['git', ...$args], $root);
            $process->setEnv([
                'HOME' => $root,
                'GIT_DIR' => $root.'/.git',
                'GIT_WORK_TREE' => $root,
            ]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        };

        $run(['init']);
        $run(['config', 'user.email', 'agent@test']);
        $run(['config', 'user.name', 'agent']);
        $run(['config', 'commit.gpgsign', 'false']);
        $run(['config', 'safe.directory', $root]);
    }
}
