<?php

namespace App\AiOffice\Policies;

use App\AiOffice\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAiOffice();
    }

    public function view(User $user, Agent $agent): bool
    {
        return $user->canAccessAiOffice();
    }

    /**
     * 規格第 53 節把 agents 列在 manager 底下，developer 沒有。
     * Agent 的 system prompt 與權限設定改動影響整個平台的行為，不是一般開發者的範圍。
     */
    public function update(User $user, Agent $agent): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
