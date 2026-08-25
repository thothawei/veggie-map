<?php

namespace App\AiOffice\Services;

use App\AiOffice\Llm\LlmResponse;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\TokenUsage;

/**
 * 規格第 40 節：每一次 LLM 請求都記一筆。Dashboard 的所有用量統計都從這張表算，
 * 不可以寫死（規格第 74 節）。
 */
class TokenUsageService
{
    public function record(LlmResponse $response, Task $task, TaskRun $taskRun): TokenUsage
    {
        return TokenUsage::create([
            'provider' => $response->provider,
            'model' => $response->model,
            'agent_id' => $taskRun->agent_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'task_run_id' => $taskRun->id,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'total_tokens' => $response->totalTokens(),
            'estimated_cost' => $this->estimateCost($response),
        ]);
    }

    /**
     * 依 config('ai_office.llm.pricing') 的每百萬 token 單價估算。
     *
     * 價格放設定檔不寫在程式裡：模型價格會變、也會有新模型，改價不該動到程式碼。
     * 找不到對應模型時回 0 而不是猜一個數字——寧可少報，也不要在成本報表裡放
     * 一個沒有來源的數字。
     */
    public function estimateCost(LlmResponse $response): string
    {
        $pricing = config('ai_office.llm.pricing.'.$response->model);

        if ($pricing === null) {
            return '0.000000';
        }

        $cost = $response->inputTokens / 1_000_000 * (float) $pricing['input']
            + $response->outputTokens / 1_000_000 * (float) $pricing['output'];

        return number_format($cost, 6, '.', '');
    }
}
