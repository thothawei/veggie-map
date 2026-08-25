<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Security\CommandAllowlist;
use App\AiOffice\Security\SandboxManager;
use App\AiOffice\Security\SandboxPolicy;
use App\AiOffice\Security\SqlReadGuard;
use App\AiOffice\Security\WorkspaceGuard;

/**
 * 把五個工具組登記進 ToolRegistry。動作名稱與 agent_permissions.ability 同一套。
 */
class ToolRegistrar
{
    public function __construct(
        private readonly WorkspaceGuard $workspace,
        private readonly CommandAllowlist $allowlist,
        private readonly SandboxPolicy $sandbox,
        private readonly SandboxManager $manager,
        private readonly SqlReadGuard $sql,
        private readonly DockerEngine $docker,
    ) {}

    public function register(ToolRegistry $registry): void
    {
        foreach (['read_file', 'write_file', 'list_files', 'search_files'] as $action) {
            $registry->register(new FileTool($action, $this->workspace));
        }

        foreach ([
            'git_status', 'git_diff', 'git_log', 'git_branch',
            'git_checkout', 'git_add', 'git_commit', 'git_push',
        ] as $action) {
            $registry->register(new GitTool($action, $this->workspace));
        }

        $registry->register(new TerminalTool($this->allowlist, $this->workspace, $this->sandbox, $this->manager));

        foreach (['docker_build', 'docker_run', 'docker_logs', 'docker_stop'] as $action) {
            $registry->register(new DockerTool($action, $this->workspace, $this->sandbox, $this->docker));
        }

        $registry->register(new DatabaseTool($this->sql));
    }
}
