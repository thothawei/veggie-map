<?php

namespace App\Policies;

use App\Models\User;

class RestaurantReportPolicy
{
    /**
     * 任何已登入使用者都能回報任何餐廳。
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 審核（列表／approve／reject）只限 Admin。
     */
    public function review(User $user): bool
    {
        return $user->isAdmin();
    }
}
