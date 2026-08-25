<?php

namespace App\AiOffice\Jobs;

use App\AiOffice\Models\Approval;
use App\AiOffice\Services\ApprovalService;

/**
 * 核准通過後才執行工具。不在 HTTP request 裡跑可能很慢的動作。
 */
class ProcessApprovalJob extends AiOfficeJob
{
    public function __construct(public int $approvalId)
    {
        parent::__construct();
    }

    public function handle(ApprovalService $approvals): void
    {
        $approval = Approval::query()->find($this->approvalId);

        if ($approval === null) {
            return;
        }

        $approvals->executeApproved($approval);
    }
}
