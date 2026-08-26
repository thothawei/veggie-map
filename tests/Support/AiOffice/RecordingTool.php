<?php

namespace Tests\Support\AiOffice;

use App\AiOffice\Tools\ToolContext;
use App\AiOffice\Tools\ToolInterface;
use RuntimeException;

/**
 * 測試用的假工具：記下自己被呼叫了幾次、收到什麼輸入。
 *
 * 「被呼叫了幾次」是關鍵——驗證「需要核准的工具沒有被執行」時，只斷言任務狀態
 * 是不夠的，必須證明 execute() 真的一次都沒跑到。
 */
class RecordingTool implements ToolInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(
        private readonly string $name = 'read_file',
        private readonly string $toolset = 'file',
        private readonly string $riskLevel = 'low',
        private readonly bool $shouldThrow = false,
        /** 執行當下要做的額外動作，用來模擬「工具跑的時候外面發生了別的事」。 */
        private readonly ?\Closure $onExecute = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function toolset(): string
    {
        return $this->toolset;
    }

    public function description(): string
    {
        return '測試用工具';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string']],
            'required' => ['path'],
        ];
    }

    public function riskLevel(): string
    {
        return $this->riskLevel;
    }

    public function execute(array $input, ToolContext $context): array
    {
        $this->calls[] = $input;

        if ($this->onExecute !== null) {
            ($this->onExecute)($input, $context);
        }

        if ($this->shouldThrow) {
            throw new RuntimeException('工具壞掉了');
        }

        return ['ok' => true, 'echo' => $input];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
