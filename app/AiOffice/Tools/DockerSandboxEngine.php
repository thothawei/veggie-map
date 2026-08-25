<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Security\SandboxManager;
use RuntimeException;

/**
 * Phase 11 的真 Docker 引擎。名稱邊界（只能碰 `ai-office-project-{id}*`）已經由
 * DockerTool 擋在前面，這裡負責的是**執行時的硬限制**：跑起來的容器一樣沒有網路、
 * 沒有 capability、不能提權，而且永遠不掛 docker.sock。
 *
 * 預設不啟用（`AI_OFFICE_SANDBOX_DOCKER_TOOL=false`）：讓 Agent 能建立與啟動容器
 * 是比「跑一條白名單指令」更高一級的權限，要由人明確打開。
 */
class DockerSandboxEngine implements DockerEngine
{
    public function __construct(private readonly SandboxManager $sandbox) {}

    public function execute(string $action, array $input, string $workspaceRoot): array
    {
        if (! (bool) config('ai_office.sandbox.docker_tool_enabled', false)) {
            throw new RuntimeException(
                'Docker 工具未啟用（AI_OFFICE_SANDBOX_DOCKER_TOOL=false），拒絕操作。'
            );
        }

        if (! $this->sandbox->available()) {
            throw new RuntimeException('docker 不可用，拒絕操作。');
        }

        $argv = match ($action) {
            'docker_build' => $this->buildArgs($input, $workspaceRoot),
            'docker_run' => $this->runArgs($input),
            'docker_logs' => ['logs', '--tail', '200', $this->arg($input, 'container')],
            'docker_stop' => ['stop', $this->arg($input, 'container')],
            default => throw new RuntimeException("未知的 docker 動作 {$action}"),
        };

        $result = $this->sandbox->runDocker($argv);

        return [
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'timed_out' => $result->timedOut,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function buildArgs(array $input, string $workspaceRoot): array
    {
        $dockerfile = $input['dockerfile'] ?? 'Dockerfile';

        if (! is_string($dockerfile) || str_contains($dockerfile, '..')) {
            // 相對路徑往上跳就能拿專案目錄外的 Dockerfile 來建 image。
            throw new RuntimeException('Dockerfile 路徑必須在 workspace 內。');
        }

        return [
            'build',
            '--tag', $this->arg($input, 'tag'),
            '--file', $dockerfile,
            // build 期間也不給網路：Dockerfile 裡的 RUN 一樣是 LLM 產生的內容。
            '--network', (string) config('ai_office.sandbox.network', 'none'),
            $workspaceRoot,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function runArgs(array $input): array
    {
        $argv = [
            'run', '--rm', '--detach',
            '--network', (string) config('ai_office.sandbox.network', 'none'),
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--pids-limit', (string) max(16, (int) config('ai_office.sandbox.pids_limit', 128)),
            '--memory', max(64, (int) config('ai_office.sandbox.memory_limit_mb', 512)).'m',
            '--cpus', (string) config('ai_office.sandbox.cpu_limit', '1.0'),
            '--user', (string) config('ai_office.sandbox.user', '1000:1000'),
        ];

        $name = $input['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $argv[] = '--name';
            $argv[] = $name;
        }

        $argv[] = $this->arg($input, 'image');

        $command = $input['command'] ?? null;

        if (is_string($command) && $command !== '') {
            $argv[] = 'sh';
            $argv[] = '-c';
            $argv[] = $command;
        }

        return $argv;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function arg(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("缺少參數 {$key}。");
        }

        return $value;
    }
}
