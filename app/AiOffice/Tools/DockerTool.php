<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Models\Project;
use App\AiOffice\Security\SandboxPolicy;
use App\AiOffice\Security\WorkspaceGuard;

/**
 * 規格第 19 節：只能操作 AI Office 管理、且屬於這個專案的 container／image。
 * 禁止 host filesystem、docker.sock、privileged。沙箱未就緒時直接拒絕。
 */
class DockerTool extends ActionTool
{
    public function __construct(
        string $action,
        private readonly WorkspaceGuard $workspace,
        private readonly SandboxPolicy $sandbox,
        private readonly DockerEngine $engine,
    ) {
        parent::__construct($action, 'docker', 'medium');
    }

    public function description(): string
    {
        return match ($this->actionName) {
            'docker_build' => '建置屬於這個專案的 image。名稱必須符合專案邊界。',
            'docker_run' => '啟動屬於這個專案的 container。禁止 privileged 與掛載 docker.sock。',
            'docker_logs' => '讀取屬於這個專案的 container 日誌。',
            'docker_stop' => '停止屬於這個專案的 container。',
            default => 'Docker 工具',
        };
    }

    public function inputSchema(): array
    {
        return match ($this->actionName) {
            'docker_build' => [
                'type' => 'object',
                'properties' => [
                    'tag' => ['type' => 'string'],
                    'dockerfile' => ['type' => 'string'],
                ],
                'required' => ['tag'],
            ],
            'docker_run' => [
                'type' => 'object',
                'properties' => [
                    'image' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'command' => ['type' => 'string'],
                ],
                'required' => ['image'],
            ],
            default => [
                'type' => 'object',
                'properties' => ['container' => ['type' => 'string']],
                'required' => ['container'],
            ],
        };
    }

    public function execute(array $input, ToolContext $context): array
    {
        $this->assertNoDeniedSubstrings($input);

        $context->task->loadMissing('project');
        $project = $context->task->project;

        if ($project === null) {
            throw new \RuntimeException('任務沒有所屬專案，無法使用 Docker 工具。');
        }

        match ($this->actionName) {
            'docker_build' => $this->assertManagedName($project, $this->stringArg($input, 'tag')),
            'docker_run' => $this->assertRunNames($project, $input),
            'docker_logs', 'docker_stop' => $this->assertManagedName($project, $this->stringArg($input, 'container')),
            default => throw new \InvalidArgumentException("未知的 docker 動作 {$this->actionName}"),
        };

        // Phase 11 之後「沙箱開著」不再等於「不能動 docker」——真的引擎自己會帶上
        // 全部硬限制。只有沙箱開著而 docker 不可用時才拒絕，那條規則沒有放寬。
        if ($this->sandbox->mode() === SandboxPolicy::REFUSE) {
            $this->sandbox->refuseHostExecution('Docker');
        }

        $cwd = $this->workspace->rootFor($project);

        return $this->engine->execute($this->actionName, $input, $cwd);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertRunNames(Project $project, array $input): void
    {
        $this->assertManagedName($project, $this->stringArg($input, 'image'));

        $name = $this->stringArg($input, 'name', required: false);

        if ($name !== null) {
            $this->assertManagedName($project, $name);
        }
    }

    private function assertManagedName(Project $project, string $name): void
    {
        $pattern = (string) config('ai_office.tools.docker.name_pattern', '');
        $pattern = str_replace('{id}', preg_quote((string) $project->id, '/'), $pattern);

        if ($pattern === '' || preg_match($pattern, $name) !== 1) {
            throw new \RuntimeException('只能操作名稱符合專案邊界的 image／container。');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertNoDeniedSubstrings(array $input): void
    {
        $blob = json_encode($input, JSON_UNESCAPED_SLASHES) ?: '';
        $needles = config('ai_office.tools.docker.denied_substrings', []);

        if (! is_array($needles)) {
            return;
        }

        foreach ($needles as $needle) {
            $needle = (string) $needle;

            if ($needle !== '' && stripos($blob, $needle) !== false) {
                throw new \RuntimeException("Docker 參數含有禁止的片段：{$needle}");
            }
        }
    }
}
