<?php

namespace App\AiOffice\Security;

/**
 * 規格第 22 節：low／medium／high／critical。
 * 門檻與「沒有 Tool 的能力」的風險都讀 config，不在這裡寫死 git_push → high。
 */
class RiskLevel
{
    public const LEVELS = ['low', 'medium', 'high', 'critical'];

    public function rank(string $level): int
    {
        $index = array_search($level, self::LEVELS, true);

        // 不認識的等級當 critical：寧可多問一次人，不要默默放行。
        return $index === false ? 3 : $index;
    }

    public function requiresApproval(string $level): bool
    {
        if ($level === 'critical') {
            return true;
        }

        $threshold = (string) config('ai_office.approvals.threshold', 'high');

        // off：只強制 critical（規格第 24 節），其餘看 Agent 權限本身。
        if ($threshold === 'off') {
            return false;
        }

        if (! in_array($threshold, self::LEVELS, true)) {
            $threshold = 'high';
        }

        return $this->rank($level) >= $this->rank($threshold);
    }

    public function forAbility(string $ability, ?string $toolRisk = null): string
    {
        if (is_string($toolRisk) && $toolRisk !== '') {
            return $toolRisk;
        }

        $configured = config('ai_office.approvals.ability_risk.'.$ability);

        return is_string($configured) && $configured !== '' ? $configured : 'critical';
    }
}
