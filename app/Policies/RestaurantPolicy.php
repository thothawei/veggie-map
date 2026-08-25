<?php

namespace App\Policies;

use App\Models\User;

/**
 * 餐廳目前沒有公開的寫入端點，所以這個 Policy 只管 Admin 的維運動作。
 * （在這之前 `docs/api.md` 就已經列了 RestaurantPolicy，但檔案不存在——
 * 現在是真的有了。）
 */
class RestaurantPolicy
{
    /** 重複標記的審核與處置。 */
    public function reviewDuplicates(User $user): bool
    {
        return $user->isAdmin();
    }
}
