<?php

namespace App\AiOffice\Orchestration;

use App\AiOffice\Models\Agent;

/**
 * 規格第 29 節：依 role／workload／availability 選一個 Agent。
 *
 * 不解析任務標題裡的「Laravel」「React」去猜角色——那會變成寫死的關鍵字表，
 * 跟規劃 JSON 裡已經帶的 agent role 重複而且會漂。角色由 CEO 的 schema 決定，
 * 這裡只負責在該 role 裡挑一個現在最不忙、且沒有 offline 的人。
 *
 * 並行上限不在這裡擋：沒人可跑時仍要留下指派（否則任務連要找誰都忘了）。
 * 要不要真的 dispatch ExecuteTaskJob，由 AgentOrchestrator 看 running 數決定。
 */
class AgentSelector
{
    public function select(string $role): ?Agent
    {
        $candidates = Agent::query()
            ->where('role', $role)
            ->where('status', '!=', 'offline')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortBy([
                fn (Agent $agent) => $agent->status === 'idle' ? 0 : 1,
                fn (Agent $agent) => $agent->activeTaskCount(),
                fn (Agent $agent) => $agent->id,
            ])
            ->first();
    }
}
