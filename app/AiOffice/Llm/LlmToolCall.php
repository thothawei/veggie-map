<?php

namespace App\AiOffice\Llm;

/**
 * LLM 要求呼叫某個工具。`id` 是回傳 tool_result 時必須帶回去的關聯鍵，
 * 少了它模型分不出哪個結果對應哪次呼叫（平行工具呼叫時尤其重要）。
 */
readonly class LlmToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {}
}
