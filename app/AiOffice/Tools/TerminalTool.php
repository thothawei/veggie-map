<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Security\CommandAllowlist;
use App\AiOffice\Security\SandboxManager;
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
        private readonly SandboxManager $manager,
    ) {
        parent::__construct('execute_command', 'terminal', 'medium');
    }

    public function description(): string
    {
        return '在容器沙箱內、專案 workspace 目錄下執行白名單指令。沙箱未就緒時會拒絕，不會改在主機上跑。';
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
        // 白名單先擋：不管最後跑在沙箱還是 host，不在名單上的指令一律不執行。
        $this->allowlist->assertAllowed($command);

        $mode = $this->sandbox->mode();

        if ($mode === SandboxPolicy::REFUSE) {
            $this->sandbox->refuseHostExecution('指令');
        }

        $context->task->loadMissing('project');
        $project = $context->task->project;

        if ($project === null) {
            throw new \RuntimeException('任務沒有所屬專案，無法執行指令。');
        }

        $cwd = $this->workspace->rootFor($project);

        if ($mode === SandboxPolicy::SANDBOX) {
            $result = $this->manager->runCommand($command, $cwd);

            // 輸出一樣要截斷：沙箱裡的指令照樣能吐出幾 MB，塞進 context 只是燒 token。
            return [
                ...$result,
                'stdout' => $this->truncate($result['stdout']),
                'stderr' => $this->truncate($result['stderr']),
            ];
        }

        // 只有在沙箱被明確關掉時才會走到這裡（開發機）。
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
