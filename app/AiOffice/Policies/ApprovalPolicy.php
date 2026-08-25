<?php

namespace App\AiOffice\Policies;

use App\AiOffice\Models\Approval;
use App\Models\User;

/**
 * 規格第 53 節：manager 管 approvals；developer 可以看但不能按核准。
 */
class ApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAiOffice();
    }

    public function view(User $user, Approval $approval): bool
    {
        return $user->canAccessAiOffice();
    }

    public function review(User $user, Approval $approval): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
