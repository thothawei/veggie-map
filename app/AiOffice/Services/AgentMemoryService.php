<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentMemory;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use Illuminate\Support\Collection;

/**
 * 規格第 41 節的 Agent 記憶。表從 Phase 2 就在，但一直沒有人寫也沒有人讀——
 * 這裡把它接起來：任務結束時寫一則，下次執行時把重要度最高的幾則放進 prompt。
 *
 * 記憶是有成本的：每一則都會進 context，等於每次請求都要為它付 token。
 * 所以有兩道上限（`config/ai_office.php` 的 `memory`）：單則長度、每次取幾則。
 */
class AgentMemoryService
{
    public function enabled(): bool
    {
        return (bool) config('ai_office.memory.enabled', true);
    }

    public function remember(
        Agent $agent,
        ?Project $project,
        string $type,
        string $content,
        ?int $importance = null,
    ): ?AgentMemory {
        if (! $this->enabled() || trim($content) === '') {
            return null;
        }

        if (! in_array($type, AgentMemory::TYPES, true)) {
            // 型別是 enum 欄位，寫進去會被 DB 擋掉。早一步擋住並講清楚，
            // 不要讓呼叫端拿到一個看不懂的 SQL 例外。
            throw new \InvalidArgumentException("未知的記憶類型 [{$type}]。");
        }

        return AgentMemory::create([
            'agent_id' => $agent->id,
            'project_id' => $project?->id,
            'memory_type' => $type,
            'content' => $this->truncate($content),
            'importance' => $importance ?? (int) config("ai_office.memory.importance.{$type}", 5),
        ]);
    }

    /**
     * 這個 Agent 在這個專案裡該記得的事。
     *
     * 也會帶上 `project_id` 為 null 的通則（例如使用者偏好）——那是跨專案的知識，
     * 不會因為換一個專案就忘記。排序用重要度優先、其次時間新的優先。
     *
     * @return Collection<int, AgentMemory>
     */
    public function recall(Agent $agent, ?Project $project = null, ?int $limit = null): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        return AgentMemory::query()
            ->where('agent_id', $agent->id)
            ->where(function ($query) use ($project) {
                $query->whereNull('project_id');

                if ($project !== null) {
                    $query->orWhere('project_id', $project->id);
                }
            })
            ->orderByDesc('importance')
            ->orderByDesc('id')
            ->limit($limit ?? (int) config('ai_office.memory.recall_limit', 5))
            ->get();
    }

    /** 記憶要放進 prompt 的樣子；沒有記憶就回 null，不要塞一個空標題進去。 */
    public function contextBlock(Agent $agent, ?Project $project = null): ?string
    {
        $memories = $this->recall($agent, $project);

        if ($memories->isEmpty()) {
            return null;
        }

        $lines = $memories
            ->map(fn (AgentMemory $memory) => "- [{$memory->memory_type}] {$memory->content}")
            ->implode("\n");

        return "你先前記得的事（重要度高的在前）：\n{$lines}";
    }

    /** 任務完成後記一則結果摘要。內容取模型的最終輸出，截斷到設定長度。 */
    public function rememberTaskResult(Task $task, TaskRun $taskRun, string $output): ?AgentMemory
    {
        $agent = $task->agent;

        if ($agent === null) {
            return null;
        }

        return $this->remember(
            $agent,
            $task->project,
            'task_result',
            "任務「{$task->title}」（第 {$taskRun->run_number} 次執行）完成：{$output}",
        );
    }

    /**
     * 失敗記成 error_pattern 而不是 task_result：下次同一個 Agent 接到類似任務時，
     * 「上次為什麼掛掉」比「上次做了什麼」更值得先看到，所以預設重要度也比較高。
     */
    public function rememberFailure(Task $task, string $reason): ?AgentMemory
    {
        $agent = $task->agent;

        if ($agent === null) {
            return null;
        }

        return $this->remember(
            $agent,
            $task->project,
            'error_pattern',
            "任務「{$task->title}」失敗：{$reason}",
        );
    }

    private function truncate(string $content): string
    {
        $max = max(50, (int) config('ai_office.memory.max_content_length', 1000));

        return mb_strlen($content) <= $max ? $content : mb_substr($content, 0, $max - 1).'…';
    }
}
