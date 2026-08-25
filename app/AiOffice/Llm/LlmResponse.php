<?php

namespace App\AiOffice\Llm;

/**
 * LLM 的一次回覆。
 *
 * `stopReason` 直接沿用 API 的值（end_turn／tool_use／max_tokens／refusal…），
 * 不自己重新命名——重新命名只會在對照官方文件除錯時多一層翻譯。
 */
readonly class LlmResponse
{
    /**
     * @param  list<LlmToolCall>  $toolCalls
     * @param  mixed  $rawContent  原始 content blocks。多輪工具對話時 assistant 那一輪
     *                             必須原封不動送回去（thinking 區塊也一樣），只送
     *                             萃取出來的文字會讓模型對不上自己剛才的 tool_use。
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public string $provider,
        public mixed $rawContent = null,
    ) {}

    /** 回給模型的 assistant 那一輪內容：有原始區塊就用原始區塊。 */
    public function assistantContent(): mixed
    {
        return $this->rawContent ?? $this->text;
    }

    public function wantsTool(): bool
    {
        return $this->toolCalls !== [];
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
