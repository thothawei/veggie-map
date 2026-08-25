<?php

namespace App\AiOffice\Security;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentPermission;

/**
 * 規格第 21、22 節的工具權限判定。
 *
 * 順序：Agent 權限 deny → 拒絕；權限 approval → 要核准；權限 allow 再看風險門檻。
 * 預設拒絕：權限表沒寫的能力一律 deny，新增工具才不會默默發給每一個 Agent。
 */
class PermissionGate
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const APPROVAL = 'approval';

    public function __construct(private readonly RiskLevel $risk) {}

    public function effectFor(Agent $agent, string $ability): string
    {
        // PHPStan 認為 firstWhere() 一定回傳模型（它看不出集合可能沒有這一筆），
        // 所以用 instanceof 而不是 ?->：實際執行時找不到就是 null，必須回 deny。
        $permission = $agent->permissions->firstWhere('ability', $ability);

        return $permission instanceof AgentPermission ? $permission->effect : self::DENY;
    }

    public function decide(Agent $agent, string $ability, string $riskLevel): string
    {
        $effect = $this->effectFor($agent, $ability);

        if ($effect === self::DENY) {
            return self::DENY;
        }

        if ($effect === self::APPROVAL || $this->risk->requiresApproval($riskLevel)) {
            return self::APPROVAL;
        }

        return self::ALLOW;
    }

    public function allows(Agent $agent, string $ability): bool
    {
        return $this->effectFor($agent, $ability) === self::ALLOW;
    }
}
