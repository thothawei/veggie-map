<?php

namespace App\Policies;

use App\Models\User;

class ReviewPolicy
{
    /**
     * 任何已登入使用者都能對任何餐廳評論（一人一店限一筆 active review 由
     * ReviewService 的交易鎖保證，不是授權層的職責）。目前沒有 update/delete
     * 端點，之後加了才會需要「只有本人能改」的判斷。
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 隱藏不當評論只限 Admin。
     */
    public function moderate(User $user): bool
    {
        return $user->isAdmin();
    }
}
