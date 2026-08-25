<?php

namespace App\AiOffice\Policies;

use App\AiOffice\Models\Task;
use App\Models\User;

class TaskPolicy
{
    private const WRITERS = ['admin', 'manager', 'developer'];

    public function viewAny(User $user): bool
    {
        return $user->canAccessAiOffice();
    }

    public function view(User $user, Task $task): bool
    {
        return $user->canAccessAiOffice();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::WRITERS);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasAnyRole(self::WRITERS);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
