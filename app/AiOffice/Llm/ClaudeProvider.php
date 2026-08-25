<?php

namespace App\AiOffice\Llm;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;

/**
 * 官方 Anthropic PHP SDK（anthropic-ai/sdk）的薄封裝。
 *
 * 為什麼用官方 SDK 而不是自己用 Laravel 的 Http client 打：request/response 的欄位
 * 命名、thinking 區塊、tool_use 區塊的形狀都會隨 API 演進，自己拼 JSON 等於把這些
 * 變動的維護責任攬到本專案身上。SDK 的 camelCase 具名參數會自動對應到線上的
 * snake_case。
 *
 * API key 只從設定讀、絕不寫進 log 或資料庫（規格第 54、55 節）；本類別也不把
 * request/response 原文寫 log，需要追蹤時看 task_runs 的 input/output 欄位。
 */
class ClaudeProvider implements LlmProviderInterface
{
    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return 'claude';
    }

    public function send(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?? config('ai_office.llm.providers.claude.model');
        $maxTokens = $request->maxTokens ?? (int) config('ai_office.llm.providers.claude.max_tokens');

        $params = [
            'model' => $model,
            'maxTokens' => $maxTokens,
            'system' => $request->systemPrompt,
            'messages' => $request->messages,
            // 目前模型世代用 adaptive thinking：由模型自己決定要想多久。
            // budgetTokens 那組舊參數在這一代會直接被 API 以 400 拒絕。
            'thinking' => ['type' => 'adaptive'],
        ];

        if ($request->tools !== []) {
            $params['tools'] = $request->tools;
        }

        $message = $this->client->messages->create(...$params);

        $text = '';
        $toolCalls = [];

        foreach ($message->content as $block) {
            // thinking 區塊不進 $text：那是推理過程，不是要交付給任務的結果。
            if ($block instanceof ToolUseBlock) {
                $toolCalls[] = new LlmToolCall($block->id, $block->name, (array) $block->input);
            } elseif ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new LlmResponse(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $message->stopReason ?? 'end_turn',
            inputTokens: $message->usage->inputTokens ?? 0,
            outputTokens: $message->usage->outputTokens ?? 0,
            model: $message->model ?? $model,
            provider: $this->name(),
            rawContent: $message->content,
        );
    }
}
