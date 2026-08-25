<?php

namespace App\AiOffice\Security;

/**
 * 「這個指令要在哪裡跑」只有這裡能決定，三種答案：
 *
 *   host     沙箱被明確關掉（`AI_OFFICE_SANDBOX_ENABLED=false`）——開發機上的選擇，
 *            不是預設值
 *   sandbox  沙箱開著而且 docker 真的可用（Phase 11）
 *   refuse   沙箱開著但 docker 不可用——**拒絕執行，不退回 host**
 *
 * 最後那一條是規格第 43 節的硬規則，也是這個子系統最重要的一條防線：
 * 退回 host 等於把主機的 shell 交給 LLM。Phase 11 把 sandbox 這條路實作出來，
 * 但沒有放寬 refuse。
 */
class SandboxPolicy
{
    public const HOST = 'host';

    public const SANDBOX = 'sandbox';

    public const REFUSE = 'refuse';

    public function __construct(private readonly SandboxManager $sandbox) {}

    public function mode(): string
    {
        if (! $this->sandbox->enabled()) {
            return self::HOST;
        }

        return $this->sandbox->available() ? self::SANDBOX : self::REFUSE;
    }

    /** 舊呼叫端沿用：沙箱關掉時才允許在 host 上跑。 */
    public function hostExecutionAllowed(): bool
    {
        return $this->mode() === self::HOST;
    }

    public function refuseHostExecution(string $what): never
    {
        throw new \RuntimeException(
            "沙箱尚未就緒（docker 不可用），拒絕在 host 上執行{$what}。"
            .'請確認執行環境有 docker CLI 與 socket，或明確把 AI_OFFICE_SANDBOX_ENABLED 設成 false。'
        );
    }
}
