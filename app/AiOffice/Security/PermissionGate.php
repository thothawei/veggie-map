<?php

namespace App\AiOffice\Security;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentPermission;

/**
 * 規格第 21 節的工具權限判定。
 *
 * **預設拒絕**：沒有在 ai_office_agent_permissions 明確寫成 allow／approval 的能力
 * 一律 deny。這個方向不能反過來——預設放行的話，未來新增一個工具就等於默默地
 * 把它發給每一個 Agent，而且沒有任何地方會提醒你。
 *
 * Phase 6 會在這之上加風險等級與 Approval 紀錄；目前 approval 的語意是
 * 「不執行、把任務轉成等待審核」，安全性上該擋的已經擋住了。
 */
class PermissionGate
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const APPROVAL = 'approval';

    public function effectFor(Agent $agent, string $ability): string
    {
        // PHPStan 認為 firstWhere() 一定回傳模型（它看不出集合可能沒有這一筆），
        // 所以用 instanceof 而不是 ?->：實際執行時找不到就是 null，必須回 deny。
        $permission = $agent->permissions->firstWhere('ability', $ability);

        return $permission instanceof AgentPermission ? $permission->effect : self::DENY;
    }

    public function allows(Agent $agent, string $ability): bool
    {
        return $this->effectFor($agent, $ability) === self::ALLOW;
    }
}
