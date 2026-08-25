<?php

namespace App\Policies;

use App\Models\User;

class RestaurantVerificationPolicy
{
    /**
     * 手動驗證只有 admin 能寫。店家認領（restaurant_claim）與照片驗證屬 Roadmap，
     * 到時候再依類型放寬，不要現在先開一個誰都能加分的洞。
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
