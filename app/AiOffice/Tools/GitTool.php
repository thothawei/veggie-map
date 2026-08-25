<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Models\Project;
use App\AiOffice\Security\WorkspaceGuard;
use Symfony\Component\Process\Process;

/**
 * 規格第 17、62 節：git 動作分級；push 受保護分支一律拒絕。
 * 所有操作的 cwd 是該專案 workspace，不是 host 上的 veggie-map repo。
 */
class GitTool extends ActionTool
{
    public function __construct(
        string $action,
        private readonly WorkspaceGuard $workspace,
    ) {
        parent::__construct($action, 'git', match ($action) {
            'git_push' => 'high',
            'git_add', 'git_checkout', 'git_commit' => 'medium',
            default => 'low',
        });
    }

    public function description(): string
    {
        return match ($this->actionName) {
            'git_status' => '查看專案 workspace 的 git status。',
            'git_diff' => '查看專案 workspace 的 git diff。',
            'git_log' => '查看專案 workspace 的 git log。',
            'git_branch' => '列出專案 workspace 的 git 分支。',
            'git_checkout' => '在專案 workspace 內切換分支或還原檔案。',
            'git_add' => '在專案 workspace 內 git add 指定路徑。',
            'git_commit' => '在專案 workspace 內建立 commit。',
            'git_push' => '推送到遠端。受保護分支（例如 main）一律拒絕。',
            default => 'Git 工具',
        };
    }

    public function inputSchema(): array
    {
        return match ($this->actionName) {
            'git_diff' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
            ],
            'git_log' => [
                'type' => 'object',
                'properties' => ['max_count' => ['type' => 'integer']],
            ],
            'git_checkout' => [
                'type' => 'object',
                'properties' => [
                    'ref' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                ],
            ],
            'git_add' => [
                'type' => 'object',
                'properties' => [
                    'paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['paths'],
            ],
            'git_commit' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => ['message'],
            ],
            'git_push' => [
                'type' => 'object',
                'properties' => [
                    'remote' => ['type' => 'string'],
                    'branch' => ['type' => 'string'],
                ],
            ],
            default => ['type' => 'object', 'properties' => new \stdClass],
        };
    }

    public function execute(array $input, ToolContext $context): array
    {
        $project = $this->project($context);

        return match ($this->actionName) {
            'git_status' => $this->run($project, ['status', '--short', '--branch']),
            'git_diff' => $this->diff($project, $input),
            'git_log' => $this->log($project, $input),
            'git_branch' => $this->run($project, ['branch', '-a']),
            'git_checkout' => $this->checkout($project, $input),
            'git_add' => $this->add($project, $input),
            'git_commit' => $this->commit($project, $input),
            'git_push' => $this->push($project, $input),
            default => throw new \InvalidArgumentException("未知的 git 動作 {$this->actionName}"),
        };
    }

    private function project(ToolContext $context): Project
    {
        $context->task->loadMissing('project');
        $project = $context->task->project;

        if ($project === null) {
            throw new \RuntimeException('任務沒有所屬專案，無法使用 git 工具。');
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function diff(Project $project, array $input): array
    {
        $args = ['diff'];
        $path = $this->stringArg($input, 'path', required: false);

        if ($path !== null) {
            $args[] = '--';
            $args[] = $this->workspace->relativeToRoot($project, $this->workspace->resolve($project, $path, mustExist: false));
        }

        return $this->run($project, $args);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function log(Project $project, array $input): array
    {
        $max = isset($input['max_count']) ? (int) $input['max_count'] : 10;
        $max = max(1, min($max, 50));

        return $this->run($project, ['log', '-'.$max, '--oneline']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function checkout(Project $project, array $input): array
    {
        $path = $this->stringArg($input, 'path', required: false);
        $ref = $this->stringArg($input, 'ref', required: false);

        if ($path !== null) {
            $relative = $this->workspace->relativeToRoot(
                $project,
                $this->workspace->resolve($project, $path, mustExist: false),
            );

            return $this->run($project, ['checkout', '--', $relative]);
        }

        if ($ref === null || str_starts_with($ref, '-') || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            throw new \InvalidArgumentException('git_checkout 需要安全的 ref 或 path。');
        }

        return $this->run($project, ['checkout', $ref]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function add(Project $project, array $input): array
    {
        $paths = $input['paths'] ?? null;

        if (! is_array($paths) || $paths === []) {
            throw new \InvalidArgumentException('git_add 需要 paths 陣列。');
        }

        $relative = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                throw new \InvalidArgumentException('git_add 的每個 path 必須是字串。');
            }

            $relative[] = $this->workspace->relativeToRoot(
                $project,
                $this->workspace->resolve($project, $path, mustExist: false),
            );
        }

        return $this->run($project, ['add', '--', ...$relative]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function commit(Project $project, array $input): array
    {
        $message = $this->stringArg($input, 'message');

        return $this->run($project, ['commit', '-m', $message]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function push(Project $project, array $input): array
    {
        $branch = $this->stringArg($input, 'branch', required: false) ?? $this->currentBranch($project);
        $protected = config('ai_office.tools.git.protected_branches', []);

        if (is_array($protected) && in_array($branch, $protected, true)) {
            throw new \RuntimeException("禁止 push 受保護的分支 {$branch}。");
        }

        $remote = $this->stringArg($input, 'remote', required: false) ?? 'origin';
        $this->assertRemoteSafe($project, $remote);

        return $this->run($project, ['push', $remote, $branch]);
    }

    private function currentBranch(Project $project): string
    {
        $result = $this->run($project, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $branch = trim((string) ($result['stdout'] ?? ''));

        return $branch !== '' ? $branch : 'HEAD';
    }

    private function assertRemoteSafe(Project $project, string $remote): void
    {
        if (preg_match('#^[A-Za-z0-9._-]+$#', $remote) === 1) {
            $lookup = $this->run($project, ['remote', 'get-url', $remote]);
            $url = trim((string) ($lookup['stdout'] ?? ''));

            if ($url !== '') {
                $this->assertRemoteUrlSafe($project, $url);
            }

            return;
        }

        $this->assertRemoteUrlSafe($project, $remote);
    }

    private function assertRemoteUrlSafe(Project $project, string $url): void
    {
        if (preg_match('#^(https?|git|ssh)://#i', $url) === 1 || str_contains($url, '@')) {
            return;
        }

        $this->workspace->resolve($project, $url, mustExist: false);
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function run(Project $project, array $args): array
    {
        $cwd = $this->workspace->rootFor($project);
        $process = new Process(['git', '-c', 'safe.directory=*', ...$args], $cwd);
        $process->setTimeout((int) config('ai_office.sandbox.timeout_seconds', 60));
        $process->setEnv([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $cwd,
            'GIT_DIR' => $cwd.'/.git',
            'GIT_WORK_TREE' => $cwd,
            'GIT_SSH_COMMAND' => (string) config('ai_office.tools.git.ssh_command', 'false'),
        ]);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $this->truncate($process->getOutput()),
            'stderr' => $this->truncate($process->getErrorOutput()),
        ];
    }
}
