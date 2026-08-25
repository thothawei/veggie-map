<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Security\CommandDeniedException;
use App\AiOffice\Tools\TerminalTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class TerminalToolTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    /**
     * 規格第 43 節的硬規則：沙箱開著但 docker 不可用時**拒絕執行、不退回 host**。
     *
     * `docker_binary` 必須明確指向一個不存在的執行檔——先前這條測試只設
     * `sandbox.enabled = true`，等於假設「跑測試的機器上沒有 docker」。本機的 app
     * container 沒有 docker CLI 所以綠，GitHub Actions 的 runner 有，於是 CI 紅。
     * 測試要驗的是「不可用時拒絕」這個行為，不是機器上有沒有裝 docker。
     */
    public function test_sandbox_enabled_but_docker_unavailable_refuses_even_allowlisted_commands(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config([
            'ai_office.sandbox.enabled' => true,
            'ai_office.sandbox.docker_binary' => '/nonexistent/docker',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('沙箱尚未就緒');
        $this->terminal()->execute(['command' => 'echo hello'], $ctx);
    }

    public function test_sandbox_disabled_runs_an_allowlisted_command_in_the_workspace(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config(['ai_office.sandbox.enabled' => false]);

        $result = $this->terminal()->execute(['command' => 'echo hello'], $ctx);

        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('hello', $result['stdout']);
    }

    public function test_denylisted_commands_never_reach_the_shell(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config(['ai_office.sandbox.enabled' => false]);

        $this->expectException(CommandDeniedException::class);
        $this->terminal()->execute(['command' => 'rm -rf /'], $ctx);
    }

    private function terminal(): TerminalTool
    {
        return $this->app->make(TerminalTool::class);
    }
}
