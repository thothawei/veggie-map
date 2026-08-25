<?php

namespace App\AiOffice\Runtime;

/**
 * 規格第 26 節的硬上限：無限迴圈、工具呼叫爆炸、token 燒光。
 *
 * 抽成獨立類別是為了讓「撞到上限」這件事可以被單獨測試，而不是埋在 AgentRuntime
 * 的一堆 if 裡面。上限值來自 config，不寫死。
 */
class AgentLoopGuard
{
    private int $steps = 0;

    private int $toolCalls = 0;

    private int $tokens = 0;

    public function __construct(
        private readonly int $maxSteps,
        private readonly int $maxToolCalls,
        private readonly int $maxTokenBudget,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('ai_office.limits.max_agent_steps'),
            (int) config('ai_office.limits.max_tool_calls'),
            (int) config('ai_office.limits.max_token_budget'),
        );
    }

    public function canTakeStep(): bool
    {
        return $this->steps < $this->maxSteps;
    }

    public function recordStep(): void
    {
        $this->steps++;
    }

    public function recordToolCall(): void
    {
        $this->toolCalls++;
    }

    public function recordTokens(int $tokens): void
    {
        $this->tokens += $tokens;
    }

    /**
     * 撞到上限的原因，沒撞到就是 null。
     *
     * 回傳原因字串而不是布林值：任務失敗時要能在 task.error 裡說清楚是哪一道
     * 上限擋下來的，否則只會看到一個「失敗了」而不知道要調哪個設定。
     */
    public function breach(): ?string
    {
        return match (true) {
            $this->steps >= $this->maxSteps => "超過單次執行的步數上限（{$this->maxSteps}）",
            $this->toolCalls >= $this->maxToolCalls => "超過單次執行的工具呼叫上限（{$this->maxToolCalls}）",
            $this->tokens >= $this->maxTokenBudget => "超過單次執行的 token 預算（{$this->maxTokenBudget}）",
            default => null,
        };
    }

    public function steps(): int
    {
        return $this->steps;
    }

    public function toolCalls(): int
    {
        return $this->toolCalls;
    }

    public function tokens(): int
    {
        return $this->tokens;
    }
}
