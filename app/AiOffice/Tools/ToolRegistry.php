<?php

namespace App\AiOffice\Tools;

/**
 * 可用工具的登記處。AgentRuntime 只把「這個 Agent 身上掛著、而且登記過」的工具
 * 送給 LLM——沒登記的工具連 schema 都不會出現在 prompt 裡，模型就不會去想著用它。
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * 把指定工具組底下已登記的動作，轉成 LLM 看得懂的定義。
     *
     * 工具組裡沒有任何已實作的動作時就是空陣列，不 throw——Agent 身上掛著一個
     * 還沒實作的工具組（Phase 5 之前全部都是）不該讓整個任務跑不動，只是那次
     * 對話裡沒有工具可用而已。
     *
     * @param  list<string>  $toolsets
     * @return list<array<string, mixed>>
     */
    public function definitionsFor(array $toolsets): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if (! in_array($tool->toolset(), $toolsets, true)) {
                continue;
            }

            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return $definitions;
    }
}
