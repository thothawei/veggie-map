<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Security\CommandAllowlist;
use App\AiOffice\Security\SandboxPolicy;
use App\AiOffice\Security\WorkspaceGuard;
use Symfony\Component\Process\Process;

/**
 * 規格第 18、43 節：只跑 allowlist 內的指令；沙箱未就緒就拒絕，不退回 host。
 */
class TerminalTool extends ActionTool
{
    public function __construct(
        private readonly CommandAllowlist $allowlist,
        private readonly WorkspaceGuard $workspace,
        private readonly SandboxPolicy $sandbox,
    ) {
        parent::__construct('execute_command', 'terminal', 'medium');
    }

    public function description(): string
    {
        return '在專案 workspace 內執行白名單指令。沙箱未就緒時會拒絕，不會改在主機上跑。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['command' => ['type' => 'string']],
            'required' => ['command'],
        ];
    }

    public function execute(array $input, ToolContext $context): array
    {
        $command = $this->stringArg($input, 'command');
        $this->allowlist->assertAllowed($command);

        if (! $this->sandbox->hostExecutionAllowed()) {
            $this->sandbox->refuseHostExecution('指令');
        }

        $context->task->loadMissing('project');
        $project = $context->task->project;

        if ($project === null) {
            throw new \RuntimeException('任務沒有所屬專案，無法執行指令。');
        }

        $cwd = $this->workspace->rootFor($project);
        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout((int) config('ai_office.sandbox.timeout_seconds', 60));
        $process->setEnv([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $cwd,
        ]);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $this->truncate($process->getOutput()),
            'stderr' => $this->truncate($process->getErrorOutput()),
        ];
    }
}
