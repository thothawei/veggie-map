<?php

namespace App\AiOffice\Llm;

/**
 * 送給 LLM 的一次請求。刻意做成 provider 無關的值物件：Claude 與 Mock 收到的是
 * 同一份資料，差別只在怎麼送出去。
 */
readonly class LlmRequest
{
    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  list<array<string, mixed>>  $tools  工具定義（name／description／inputSchema）
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public array $tools = [],
        public ?string $model = null,
        public ?int $maxTokens = null,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */
    public function withMessages(array $messages): self
    {
        return new self($this->systemPrompt, $messages, $this->tools, $this->model, $this->maxTokens);
    }
}
