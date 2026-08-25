<?php

namespace App\AiOffice\Demo;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\LlmRequest;
use App\AiOffice\Llm\LlmResponse;
use App\AiOffice\Llm\LlmToolCall;

/**
 * 規格第 79 節的 Todo API Demo 用的假模型。
 *
 * 它不是 MockProvider 的複製品：MockProvider 是一條先排好的佇列，誰來要都照順序拿，
 * 適合單一測試；Demo 會有 CEO 規劃 ＋ 四個 Agent 各自跑迴圈 ＋ 核准後重跑，
 * 順序取決於編排結果，用佇列很容易對不上。這裡改成**看請求內容決定回什麼**，
 * 所以任務被重跑（例如核准之後）時也拿得到「下一步」而不是重複第一步。
 *
 * 一個字都不會送到真的 Claude API（規格第 57 節）。
 */
class DemoScriptProvider implements LlmProviderInterface
{
    /** @var array<string, int> 每個腳本已經被取用幾次 */
    private array $cursor = [];

    /** @var list<LlmRequest> */
    private array $received = [];

    public function name(): string
    {
        return 'mock';
    }

    public function send(LlmRequest $request): LlmResponse
    {
        $this->received[] = $request;

        $content = $this->firstUserContent($request);
        $key = $this->scriptKeyFor($content);
        $step = $this->cursor[$key] ?? 0;
        $this->cursor[$key] = $step + 1;

        $script = $this->scripts()[$key] ?? [];

        // 腳本用完就給一句收尾，而不是丟例外：Demo 的重點是把整條鏈路走完，
        // 為了一個沒預料到的第 N 步炸掉整場示範不值得。
        return $script[$step] ?? $this->text('這一步已經完成，沒有其他要做的了。');
    }

    /** @return list<LlmRequest> */
    public function received(): array
    {
        return $this->received;
    }

    /**
     * 腳本鍵：規劃只有一種，其餘只認 prompt 第一行的「任務：<標題>」。
     *
     * 這裡刻意不用 `str_contains` 掃整段內容。Phase 10 之後 prompt 尾巴會附上
     * Agent 的記憶（「任務『設計 Todo 資料表』完成：…」），拿整段去比對的話，
     * 第二個任務會比中第一個任務的腳本、拿到它的收尾句，於是安靜地什麼都沒做就
     * 「完成」了——實測就是這樣少掉一個檔案。標題只認第一行才不會被記憶污染。
     */
    private function scriptKeyFor(string $content): string
    {
        if (str_contains($content, '只輸出一個 JSON 物件')) {
            return 'plan';
        }

        if (preg_match('/^任務：(.+)$/mu', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return 'unknown';
    }

    /**
     * @return array<string, list<LlmResponse>>
     */
    private function scripts(): array
    {
        return [
            'plan' => [$this->text($this->planJson())],

            '設計 Todo 資料表' => [
                $this->toolCall('write_file', [
                    'path' => 'docs/schema.md',
                    'content' => "# Todo 資料表\n\n- id\n- title\n- done (bool)\n- created_at\n",
                ]),
                $this->text('資料表設計完成，欄位寫在 docs/schema.md。'),
            ],

            '實作 Todo REST API' => [
                $this->toolCall('read_file', ['path' => 'docs/schema.md']),
                $this->toolCall('write_file', [
                    'path' => 'routes/todos.php',
                    'content' => "<?php\n// GET /todos, POST /todos, PATCH /todos/{id}, DELETE /todos/{id}\n",
                ]),
                $this->text('四個端點都寫好了，放在 routes/todos.php。'),
            ],

            '撰寫 Todo API 測試' => [
                $this->toolCall('read_file', ['path' => 'routes/todos.php']),
                $this->toolCall('write_file', [
                    'path' => 'tests/TodoApiTest.php',
                    'content' => "<?php\n// 建立、列出、標記完成、刪除各一條測試\n",
                ]),
                $this->text('四條測試涵蓋 CRUD，放在 tests/TodoApiTest.php。'),
            ],

            // 這個任務會撞到核准門檻（Demo 把門檻降到 medium），第一輪停在等待核准，
            // 人按下核准之後任務會被重新派工，這時拿到的是腳本的第二步。
            '撰寫上線說明' => [
                $this->toolCall('write_file', [
                    'path' => 'DEPLOY.md',
                    'content' => "# 上線步驟\n\n1. migrate\n2. 佈署\n3. 煙霧測試\n",
                ]),
                $this->text('上線說明已寫入 DEPLOY.md，等待核准的那一步已經完成。'),
            ],
        ];
    }

    private function planJson(): string
    {
        return <<<'JSON'
        {
          "project": { "name": "Todo API", "description": "一個最小可用的 Todo REST API" },
          "tasks": [
            { "title": "設計 Todo 資料表", "agent": "backend", "description": "定義欄位與型別", "priority": 90, "dependencies": [] },
            { "title": "實作 Todo REST API", "agent": "backend", "description": "CRUD 四個端點", "priority": 80, "dependencies": ["設計 Todo 資料表"] },
            { "title": "撰寫 Todo API 測試", "agent": "qa", "description": "涵蓋 CRUD 的測試", "priority": 70, "dependencies": ["實作 Todo REST API"] },
            { "title": "撰寫上線說明", "agent": "devops", "description": "部署步驟文件", "priority": 60, "dependencies": ["撰寫 Todo API 測試"] }
          ]
        }
        JSON;
    }

    private function firstUserContent(LlmRequest $request): string
    {
        $content = $request->messages[0]['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
    }

    private function text(string $text): LlmResponse
    {
        return new LlmResponse(
            text: $text,
            toolCalls: [],
            stopReason: 'end_turn',
            inputTokens: 900,
            outputTokens: 120,
            model: 'mock-1',
            provider: $this->name(),
            rawContent: [['type' => 'text', 'text' => $text]],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function toolCall(string $tool, array $input): LlmResponse
    {
        $id = 'toolu_demo_'.substr(md5($tool.json_encode($input)), 0, 8);

        return new LlmResponse(
            text: '',
            toolCalls: [new LlmToolCall($id, $tool, $input)],
            stopReason: 'tool_use',
            inputTokens: 1100,
            outputTokens: 180,
            model: 'mock-1',
            provider: $this->name(),
            rawContent: [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]],
        );
    }
}
