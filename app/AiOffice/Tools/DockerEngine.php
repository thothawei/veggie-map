<?php

namespace App\AiOffice\Tools;

/**
 * Docker 實際執行器。Phase 5 預設不接 host docker socket；測試可換成假引擎。
 */
interface DockerEngine
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $action, array $input, string $workspaceRoot): array;
}
