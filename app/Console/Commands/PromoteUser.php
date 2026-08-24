<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUser extends Command
{
    protected $signature = 'users:promote {email : 要升為 admin 的使用者 email}';

    protected $description = '把指定 email 的使用者 role 改成 admin（原本只能手動改 DB）';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("找不到 email 為 [{$email}] 的使用者。");

            return self::FAILURE;
        }

        if ($user->role === 'admin') {
            $this->info("[{$email}] 已經是 admin，不需要變更。");

            return self::SUCCESS;
        }

        $user->update(['role' => 'admin']);

        $this->info("已將 [{$email}] 升級為 admin。");

        return self::SUCCESS;
    }
}
