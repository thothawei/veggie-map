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

    public function test_sandbox_enabled_refuses_even_allowlisted_commands(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config(['ai_office.sandbox.enabled' => true]);

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
