<?php

namespace App\AiOffice\Policies;

use App\AiOffice\Models\Project;
use App\Models\User;

/**
 * 規格第 53 節的 RBAC 落點：
 *   admin      everything
 *   manager    projects / tasks / agents / approvals
 *   developer  projects / tasks / logs
 *   viewer     read-only
 *
 * `user`（只用餐廳地圖的一般消費者）連讀都不行——EnsureAiOfficeRole 中介層
 * 已經在路由層擋掉，這裡是第二層，避免未來有人漏掛中介層就整片開放。
 */
class ProjectPolicy
{
    /** 可以寫入專案的角色。 */
    private const WRITERS = ['admin', 'manager', 'developer'];

    public function viewAny(User $user): bool
    {
        return $user->canAccessAiOffice();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->canAccessAiOffice();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::WRITERS);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasAnyRole(self::WRITERS);
    }

    /**
     * 刪除專案會連帶刪掉底下所有任務與執行紀錄（外鍵 cascade），
     * 不是 developer 該有的權限。
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
