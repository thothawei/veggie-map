<?php

namespace App\Policies;

use App\Models\User;

class MenuItemPolicy
{
    /**
     * Phase C 先最小：只有 admin 能寫菜單。使用者／店家認領之後再放寬。
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
