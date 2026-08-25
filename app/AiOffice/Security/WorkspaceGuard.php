<?php

namespace App\AiOffice\Security;

use App\AiOffice\Models\Project;

/**
 * 規格第 16、42 節：所有檔案路徑 realpath() 之後必須落在該專案 workspace 內。
 *
 * 拒絕 symlink 逃逸、`..`、空位元組、跨 Project。路徑規則讀 config 的
 * workspace_root，不寫死 `/workspace`。
 */
class WorkspaceGuard
{
    public function rootFor(Project $project): string
    {
        $base = $this->baseRoot();
        $relative = $project->workspace_path ?: 'project-'.$project->id;

        if (preg_match('/^[A-Za-z0-9._-]+$/', $relative) !== 1) {
            throw new WorkspaceEscapeException('workspace_path 含有非法字元。');
        }

        $dir = $base.DIRECTORY_SEPARATOR.$relative;

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new WorkspaceEscapeException('無法建立專案 workspace。');
        }

        $resolved = realpath($dir);

        if ($resolved === false || ! $this->isInside($resolved, $base)) {
            throw new WorkspaceEscapeException('專案 workspace 不在允許的根目錄內。');
        }

        return $resolved;
    }

    /**
     * 把 Agent 給的路徑收成 workspace 內的絕對路徑。
     *
     * $mustExist=false 時允許尚不存在的檔案（write_file），改檢查父目錄。
     */
    public function resolve(Project $project, string $userPath, bool $mustExist = true): string
    {
        if ($userPath === '' || str_contains($userPath, "\0")) {
            throw new WorkspaceEscapeException('路徑無效。');
        }

        $root = $this->rootFor($project);
        $normalized = str_replace('\\', '/', $userPath);

        if ($this->isAbsolute($normalized)) {
            $candidate = $normalized;
        } else {
            $candidate = $root.'/'.ltrim($normalized, '/');
        }

        if ($mustExist) {
            $resolved = realpath($candidate);

            if ($resolved === false) {
                throw new WorkspaceEscapeException("路徑不存在或無法解析：{$userPath}");
            }

            $this->assertInside($resolved, $root);

            return $resolved;
        }

        $parent = dirname($candidate);

        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new WorkspaceEscapeException('無法建立目標目錄。');
        }

        $resolvedParent = realpath($parent);

        if ($resolvedParent === false) {
            throw new WorkspaceEscapeException('目標目錄無法解析。');
        }

        $this->assertInside($resolvedParent, $root);

        $final = $resolvedParent.DIRECTORY_SEPARATOR.basename($candidate);
        $this->assertInside($final, $root);

        return $final;
    }

    public function relativeToRoot(Project $project, string $absolute): string
    {
        $root = $this->rootFor($project);
        $this->assertInside($absolute, $root);

        $relative = ltrim(substr($absolute, strlen($root)), DIRECTORY_SEPARATOR);

        return str_replace('\\', '/', $relative === '' ? '.' : $relative);
    }

    public function assertInside(string $path, string $root): void
    {
        if (! $this->isInside($path, $root)) {
            throw new WorkspaceEscapeException('路徑超出專案 workspace 邊界。');
        }
    }

    public function isInside(string $path, string $root): bool
    {
        $path = $this->canonical($path);
        $root = rtrim($this->canonical($root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function baseRoot(): string
    {
        $configured = config('ai_office.workspace_root');

        if (! is_string($configured) || $configured === '') {
            throw new WorkspaceEscapeException('workspace_root 未設定。');
        }

        if (! is_dir($configured) && ! mkdir($configured, 0755, true) && ! is_dir($configured)) {
            throw new WorkspaceEscapeException('無法建立 workspace 根目錄。');
        }

        $resolved = realpath($configured);

        if ($resolved === false) {
            throw new WorkspaceEscapeException('workspace_root 無法解析。');
        }

        return $resolved;
    }

    private function canonical(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }
}
