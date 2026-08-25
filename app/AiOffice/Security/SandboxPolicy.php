<?php

namespace App\AiOffice\Security;

/**
 * 規格第 43 節：SANDBOX_ENABLED=true 而 SandboxManager 尚未就緒時，
 * Terminal／Docker 必須拒絕執行，不可以退回在 host 上跑。
 */
class SandboxPolicy
{
    public function hostExecutionAllowed(): bool
    {
        return ! (bool) config('ai_office.sandbox.enabled');
    }

    public function refuseHostExecution(string $what): never
    {
        throw new \RuntimeException("沙箱尚未就緒，拒絕在 host 上執行{$what}。");
    }
}
