<?php

namespace App\AiOffice\Tools;

use RuntimeException;

/**
 * 預設引擎：即使有人把 SANDBOX_ENABLED 關掉，也不要去碰 host 的 docker.sock。
 * 真的要跑容器是 Phase 11 的 SandboxManager。
 */
class UnavailableDockerEngine implements DockerEngine
{
    public function execute(string $action, array $input, string $workspaceRoot): array
    {
        throw new RuntimeException('Docker 引擎尚未接上沙箱（Phase 11），拒絕操作。');
    }
}
