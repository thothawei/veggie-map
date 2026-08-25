<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Process\ProcessResult;
use App\AiOffice\Process\ProcessRunner;
use App\AiOffice\Security\CommandDeniedException;
use App\AiOffice\Security\SandboxManager;
use App\AiOffice\Security\SandboxPolicy;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Tools\DockerEngine;
use App\AiOffice\Tools\DockerSandboxEngine;
use App\AiOffice\Tools\DockerTool;
use App\AiOffice\Tools\TerminalTool;
use App\AiOffice\Tools\UnavailableDockerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AiOffice\FakeProcessRunner;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

/**
 * Phase 11：指令真的丟進容器跑。
 *
 * 這裡的斷言幾乎都在「送給 docker 的那串參數」上——沙箱的安全性就是那串旗標，
 * 而且它在容器跑起來之後就看不見了，只有測試盯得住。
 */
class SandboxTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    private FakeProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
        config(['ai_office.sandbox.enabled' => true]);
    }

    private function terminal(): TerminalTool
    {
        return $this->app->make(TerminalTool::class);
    }

    private function runEcho(): array
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        return [$this->terminal()->execute(['command' => 'echo hello'], $ctx), $project];
    }

    public function test_commands_run_inside_a_container_not_on_the_host(): void
    {
        [$result] = $this->runEcho();

        $argv = $this->runner->lastArgv();

        $this->assertSame(['docker', 'run', '--rm'], array_slice($argv, 0, 3));
        $this->assertSame(['sh', '-c', 'echo hello'], array_slice($argv, -3));
        $this->assertSame('alpine:3.20', $result['sandbox']);
    }

    public function test_the_container_gets_every_hard_limit(): void
    {
        $this->runEcho();
        $argv = $this->runner->lastArgv();

        // 每一項都對應一種具體的逃脫或破壞方式，見 SandboxManager 的說明。
        $this->assertContainsPair($argv, '--network', 'none');
        $this->assertContainsPair($argv, '--cap-drop', 'ALL');
        $this->assertContainsPair($argv, '--security-opt', 'no-new-privileges');
        $this->assertContainsPair($argv, '--pids-limit', '128');
        $this->assertContainsPair($argv, '--memory', '512m');
        $this->assertContainsPair($argv, '--memory-swap', '512m');
        $this->assertContainsPair($argv, '--cpus', '1.0');
        $this->assertContainsPair($argv, '--user', '1000:1000');
        $this->assertContains('--read-only', $argv);
        $this->assertContainsPair($argv, '--tmpfs', '/tmp:rw,noexec,nosuid,size=64m');
    }

    public function test_only_the_project_workspace_is_mounted_and_never_the_docker_socket(): void
    {
        [, $project] = $this->runEcho();

        $argv = $this->runner->lastArgv();
        $root = rtrim($this->app->make(WorkspaceGuard::class)->rootFor($project), '/');

        $this->assertContainsPair($argv, '--volume', "{$root}:/workspace:rw");
        $this->assertContainsPair($argv, '--workdir', '/workspace');

        // 掛 docker.sock 等於把 host 的 root 交出去；掛 / 等於整台機器。
        $blob = implode(' ', $argv);
        $this->assertStringNotContainsString('docker.sock', $blob);
        $this->assertStringNotContainsString('--privileged', $blob);
        $this->assertStringNotContainsString('/:/', $blob);
    }

    public function test_docker_unavailable_still_refuses_instead_of_falling_back_to_the_host(): void
    {
        $this->app->instance(ProcessRunner::class, new FakeProcessRunner(dockerAvailable: false));

        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        // 這是整個子系統最重要的一條防線：沒有沙箱就不執行，不會改在 host 上跑。
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('拒絕在 host 上執行');
        $this->terminal()->execute(['command' => 'echo hello'], $ctx);
    }

    public function test_the_allowlist_still_applies_inside_the_sandbox(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        try {
            $this->terminal()->execute(['command' => 'curl http://evil.example'], $ctx);
            $this->fail('白名單外的指令不該被執行。');
        } catch (CommandDeniedException) {
            // 白名單擋在最前面：連 docker 都不該被呼叫。
            $this->assertSame([], $this->runner->argvList());
        }
    }

    public function test_a_timed_out_container_is_force_removed(): void
    {
        $this->runner
            // 第一次呼叫是 docker info（可用性偵測）
            ->push(new ProcessResult(0, '27.0.3', ''))
            ->push(new ProcessResult(null, '', '', timedOut: true));

        [$result] = $this->runEcho();

        $this->assertTrue($result['timed_out']);

        // --rm 只在正常結束時生效，逾時的容器要自己收掉，否則會一直吃資源。
        $last = $this->runner->lastArgv();
        $this->assertSame(['docker', 'rm', '--force'], array_slice($last, 0, 3));
        $this->assertStringStartsWith('ai-office-sandbox-', $last[3]);
    }

    public function test_availability_is_probed_once_per_manager_instance(): void
    {
        $manager = $this->app->make(SandboxManager::class);

        $manager->available();
        $manager->available();
        $manager->available();

        $probes = array_filter(
            $this->runner->argvList(),
            fn (array $argv) => ($argv[1] ?? null) === 'info',
        );

        // 一次任務執行內會問很多次，每次都 fork 一個 docker info 太浪費。
        $this->assertCount(1, $probes);
    }

    public function test_policy_reports_host_mode_only_when_the_sandbox_is_explicitly_disabled(): void
    {
        $policy = $this->app->make(SandboxPolicy::class);
        $this->assertSame(SandboxPolicy::SANDBOX, $policy->mode());

        config(['ai_office.sandbox.enabled' => false]);
        $this->assertSame(SandboxPolicy::HOST, $this->app->make(SandboxPolicy::class)->mode());

        config(['ai_office.sandbox.enabled' => true]);
        $this->app->instance(ProcessRunner::class, new FakeProcessRunner(dockerAvailable: false));
        $this->assertSame(SandboxPolicy::REFUSE, $this->app->make(SandboxPolicy::class)->mode());
    }

    public function test_docker_tool_stays_unavailable_unless_explicitly_enabled(): void
    {
        // 讓 Agent 能建立與啟動容器，比跑一條白名單指令高一級，不會因為升級就自動生效。
        $this->assertInstanceOf(UnavailableDockerEngine::class, $this->app->make(DockerEngine::class));

        config(['ai_office.sandbox.docker_tool_enabled' => true]);
        $this->app->forgetInstance(DockerEngine::class);

        $this->assertInstanceOf(DockerSandboxEngine::class, $this->app->make(DockerEngine::class));
    }

    public function test_docker_run_from_the_tool_gets_the_same_hard_limits(): void
    {
        config(['ai_office.sandbox.docker_tool_enabled' => true]);
        $this->app->forgetInstance(DockerEngine::class);

        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        $tool = new DockerTool(
            'docker_run',
            $this->app->make(WorkspaceGuard::class),
            $this->app->make(SandboxPolicy::class),
            $this->app->make(DockerEngine::class),
        );

        $tool->execute(['image' => "ai-office-project-{$project->id}"], $ctx);

        $argv = $this->runner->lastArgv();

        $this->assertContainsPair($argv, '--network', 'none');
        $this->assertContainsPair($argv, '--cap-drop', 'ALL');
        $this->assertContainsPair($argv, '--security-opt', 'no-new-privileges');
        $this->assertStringNotContainsString('docker.sock', implode(' ', $argv));
    }

    public function test_docker_build_refuses_a_dockerfile_outside_the_workspace(): void
    {
        config(['ai_office.sandbox.docker_tool_enabled' => true]);
        $this->app->forgetInstance(DockerEngine::class);

        $engine = $this->app->make(DockerEngine::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dockerfile 路徑必須在 workspace 內');
        $engine->execute('docker_build', ['tag' => 'ai-office-project-1', 'dockerfile' => '../../etc/Dockerfile'], '/tmp/ws');
    }

    /**
     * @param  list<string>  $argv
     */
    private function assertContainsPair(array $argv, string $flag, string $value): void
    {
        $index = array_search($flag, $argv, true);

        $this->assertNotFalse($index, "argv 少了 {$flag}");
        $this->assertSame($value, $argv[$index + 1] ?? null, "{$flag} 的值不是 {$value}");
    }
}
