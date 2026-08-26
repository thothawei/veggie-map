<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Message;
use App\AiOffice\Models\Task;

/**
 * Agent 之間的訊息（規格第 34 節）。
 *
 * 這張表從 Phase 2 就建好了，但在這之前**一行寫入都沒有**——規格第 34 節舉的例子
 * （CEO → Backend 派工、Backend → QA、QA → Backend 回報 bug、Backend → CEO 回報完成）
 * 一個都沒發生過。`AgentOrchestrator::handleFailure()` 甚至寫了一則
 * 「通知 CEO」的 Activity，但沒有任何東西真的送到 CEO 手上。
 *
 * 跟 Activity 的分工：
 *   Activity  「系統發生了什麼」——給人看的稽核軌跡，每個重要動作都有
 *   Message   「誰對誰說了什麼」——有明確的收件者，是協作的紀錄
 *
 * 兩者刻意不合併：Activity 沒有收件人（`TaskStarted` 不是說給誰聽的），而 Message
 * 沒有收件人就沒有意義。硬塞在一起會得到一張兩邊都不好查的表。
 *
 * 內容目前是**樣板字串**而不是 LLM 生成的自然語言：讓 Agent 互相寫信要多花一輪
 * token，而這裡真正要的是「誰在什麼時候通知了誰」這條線索。之後要接 LLM，
 * 換的是 `content` 怎麼來，不是這張表的形狀。
 */
class AgentMessenger
{
    public function __construct(private readonly ActivityRecorder $activities) {}

    /** CEO → 承接的 Agent：派工。 */
    public function taskAssigned(Task $task, Agent $assignee): ?Message
    {
        return $this->send(
            $task,
            $this->planner(),
            $assignee,
            "請處理「{$task->title}」。",
        );
    }

    /** 執行的 Agent → CEO：完成回報。 */
    public function taskCompleted(Task $task): ?Message
    {
        return $this->send(
            $task,
            $task->agent,
            $this->planner(),
            "「{$task->title}」已完成。",
        );
    }

    /** 執行的 Agent → CEO：重試用完了，這件事需要人介入。 */
    public function taskPermanentlyFailed(Task $task): ?Message
    {
        $error = trim((string) $task->error);
        $detail = $error === '' ? '' : ' 最後一次的錯誤：'.mb_substr($error, 0, 200);

        return $this->send(
            $task,
            $task->agent,
            $this->planner(),
            "「{$task->title}」重試 {$task->retry_count} 次仍然失敗，需要協助。{$detail}",
        );
    }

    /**
     * 收發雙方任一個不存在就不寫（例如 seeder 沒有建 CEO）。訊息的意義來自
     * 「誰對誰」，缺一邊的紀錄留著只會讓這張表變成第二份 Activity。
     */
    private function send(Task $task, ?Agent $from, ?Agent $to, string $content): ?Message
    {
        if ($from === null || $to === null || $from->id === $to->id) {
            return null;
        }

        $message = Message::create([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'from_agent_id' => $from->id,
            'to_agent_id' => $to->id,
            'content' => $content,
        ]);

        // 事件流也要看得到——不然使用者得同時盯兩個面板才知道發生了什麼。
        $this->activities->record(
            'MessageSent',
            "{$from->name} → {$to->name}：{$content}",
            $task,
            $from,
            ['message_id' => $message->id, 'to_agent_id' => $to->id],
        );

        return $message;
    }

    private function planner(): ?Agent
    {
        return Agent::query()
            ->where('role', (string) config('ai_office.planner.agent_role'))
            ->orderBy('id')
            ->first();
    }
}
