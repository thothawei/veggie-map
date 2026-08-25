<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Models\Project;
use App\AiOffice\Models\ProjectFile;
use App\AiOffice\Security\WorkspaceGuard;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 規格第 16 節：read_file／write_file／list_files／search_files。
 * 所有路徑都經 WorkspaceGuard，Agent 碰不到系統檔與其他專案。
 */
class FileTool extends ActionTool
{
    public function __construct(
        string $action,
        private readonly WorkspaceGuard $workspace,
    ) {
        parent::__construct($action, 'file', $action === 'write_file' ? 'medium' : 'low');
    }

    public function description(): string
    {
        return match ($this->actionName) {
            'read_file' => '讀取專案 workspace 內的一個檔案。',
            'write_file' => '寫入專案 workspace 內的一個檔案。路徑不可逃出該專案目錄。',
            'list_files' => '列出專案 workspace 內某個目錄的檔案。',
            'search_files' => '在專案 workspace 內搜尋檔名或檔案內容。',
            default => '檔案工具',
        };
    }

    public function inputSchema(): array
    {
        return match ($this->actionName) {
            'write_file' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                ],
                'required' => ['path', 'content'],
            ],
            'search_files' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
            default => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => $this->actionName === 'read_file' ? ['path'] : [],
            ],
        };
    }

    public function execute(array $input, ToolContext $context): array
    {
        $project = $this->project($context);

        return match ($this->actionName) {
            'read_file' => $this->read($project, $this->stringArg($input, 'path')),
            'write_file' => $this->write(
                $project,
                $context,
                $this->stringArg($input, 'path'),
                $this->stringArg($input, 'content'),
            ),
            'list_files' => $this->list($project, $this->stringArg($input, 'path', required: false) ?? '.'),
            'search_files' => $this->search(
                $project,
                $this->stringArg($input, 'query'),
                $this->stringArg($input, 'path', required: false) ?? '.',
            ),
            default => throw new \InvalidArgumentException("未知的檔案動作 {$this->actionName}"),
        };
    }

    private function project(ToolContext $context): Project
    {
        $context->task->loadMissing('project');
        $project = $context->task->project;

        if ($project === null) {
            throw new \RuntimeException('任務沒有所屬專案，無法使用檔案工具。');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Project $project, string $path): array
    {
        $absolute = $this->workspace->resolve($project, $path);
        $max = (int) config('ai_office.tools.file.max_read_bytes', 512_000);
        $size = filesize($absolute);
        $size = $size === false ? 0 : $size;
        $truncated = $size > $max;
        $content = file_get_contents($absolute, false, null, 0, $max);

        return [
            'path' => $this->workspace->relativeToRoot($project, $absolute),
            'content' => $content === false ? '' : $this->truncate($content),
            'size_bytes' => $size,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function write(Project $project, ToolContext $context, string $path, string $content): array
    {
        $max = (int) config('ai_office.tools.file.max_write_bytes', 1_048_576);

        if (strlen($content) > $max) {
            throw new \InvalidArgumentException("寫入內容超過 {$max} bytes。");
        }

        $absolute = $this->workspace->resolve($project, $path, mustExist: false);

        if (file_put_contents($absolute, $content) === false) {
            throw new \RuntimeException('寫入失敗。');
        }

        $relative = $this->workspace->relativeToRoot($project, $absolute);
        $size = filesize($absolute) ?: strlen($content);

        ProjectFile::query()->updateOrCreate(
            ['project_id' => $project->id, 'path' => $relative],
            [
                'size_bytes' => $size,
                'checksum' => hash_file('sha256', $absolute) ?: null,
                'last_modified_by_agent_id' => $context->agent->id,
            ],
        );

        return ['path' => $relative, 'size_bytes' => $size];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(Project $project, string $path): array
    {
        $absolute = $this->workspace->resolve($project, $path);

        if (! is_dir($absolute)) {
            throw new \InvalidArgumentException('list_files 的目標必須是目錄。');
        }

        $entries = [];
        $iterator = new FilesystemIterator($absolute, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            $entries[] = [
                'path' => $this->workspace->relativeToRoot($project, $file->getPathname()),
                'type' => $file->isDir() ? 'directory' : ($file->isLink() ? 'link' : 'file'),
            ];
        }

        usort($entries, fn (array $a, array $b) => $a['path'] <=> $b['path']);

        return ['entries' => $entries];
    }

    /**
     * @return array<string, mixed>
     */
    private function search(Project $project, string $query, string $path): array
    {
        $absolute = $this->workspace->resolve($project, $path);
        $limit = (int) config('ai_office.tools.file.max_search_results', 50);
        $matches = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = $this->workspace->relativeToRoot($project, $file->getPathname());

            if (str_contains($relative, '/.git/')) {
                continue;
            }

            $hitName = str_contains($relative, $query);
            $hitContent = false;

            if (! $hitName && $file->getSize() <= (int) config('ai_office.tools.file.max_read_bytes', 512_000)) {
                $contents = file_get_contents($file->getPathname());
                $hitContent = is_string($contents) && str_contains($contents, $query);
            }

            if ($hitName || $hitContent) {
                $matches[] = [
                    'path' => $relative,
                    'match' => $hitName ? 'name' : 'content',
                ];
            }

            if (count($matches) >= $limit) {
                break;
            }
        }

        return ['query' => $query, 'matches' => $matches];
    }
}
