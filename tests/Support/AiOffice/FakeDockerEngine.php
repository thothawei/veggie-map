<?php

namespace Tests\Support\AiOffice;

use App\AiOffice\Tools\DockerEngine;

class FakeDockerEngine implements DockerEngine
{
    /** @var list<array{action: string, input: array<string, mixed>, workspace: string}> */
    public array $calls = [];

    public function execute(string $action, array $input, string $workspaceRoot): array
    {
        $this->calls[] = [
            'action' => $action,
            'input' => $input,
            'workspace' => $workspaceRoot,
        ];

        return ['ok' => true, 'action' => $action];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
