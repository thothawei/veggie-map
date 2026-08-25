<?php

namespace App\AiOffice\Llm;

use RuntimeException;

/**
 * 測試用 provider（規格第 4、57 節）：整條 CEO → Planner → Agent → QA 流程要能在
 * 不呼叫真 Claude API 的情況下跑完。
 *
 * 也是本專案的預設 provider——開發環境忘了設定時，寧可什麼都不做，也不要不小心
 * 打出去燒 token（跟既有 EXTERNAL_API_RESTAURANT_PROVIDER 預設 mock 同一個理由）。
 *
 * 回覆是先排好的佇列，依序取用。刻意在用完時 throw 而不是回一句罐頭答案：
 * 排的回覆數量與實際請求次數對不上，代表測試的假設跟程式的行為不一致，
 * 這件事應該炸出來，不該被一句「好的」蓋掉。
 */
class MockProvider implements LlmProviderInterface
{
    /** @var list<LlmResponse> */
    private array $queue = [];

    /** @var list<LlmRequest> */
    private array $received = [];

    public function name(): string
    {
        return 'mock';
    }

    /**
     * 排一則純文字回覆（模型認為任務做完了）。
     */
    public function pushText(string $text, int $inputTokens = 100, int $outputTokens = 50): self
    {
        $this->queue[] = new LlmResponse(
            text: $text,
            toolCalls: [],
            stopReason: 'end_turn',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: 'mock-1',
            provider: $this->name(),
            rawContent: [['type' => 'text', 'text' => $text]],
        );

        return $this;
    }

    /**
     * 排一則要求呼叫工具的回覆。
     *
     * @param  array<string, mixed>  $input
     */
    public function pushToolCall(string $tool, array $input = [], ?string $id = null): self
    {
        $id ??= 'toolu_'.count($this->queue);

        $this->queue[] = new LlmResponse(
            text: '',
            toolCalls: [new LlmToolCall($id, $tool, $input)],
            stopReason: 'tool_use',
            inputTokens: 100,
            outputTokens: 50,
            model: 'mock-1',
            provider: $this->name(),
            rawContent: [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
        );

        return $this;
    }

    public function push(LlmResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function send(LlmRequest $request): LlmResponse
    {
        $this->received[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException(
                'MockProvider 的回覆佇列已用完，但程式又送了一次請求'
                .'——排的回覆數量與實際迴圈次數對不上，請檢查測試的假設。'
            );
        }

        return array_shift($this->queue);
    }

    /** @return list<LlmRequest> 依序記下所有收到的請求，供測試斷言 prompt 內容。 */
    public function received(): array
    {
        return $this->received;
    }

    public function pendingCount(): int
    {
        return count($this->queue);
    }
}
