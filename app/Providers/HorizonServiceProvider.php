<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * 誰能在非 local 環境開 `/horizon`。
     *
     * 白名單來自 `config/veggiemap.php` 的 `dashboards.allowed_emails`（env
     * `DASHBOARD_ALLOWED_EMAILS`），**預設空的＝沒有人**。Horizon 看得到佇列裡
     * 每個 job 的 payload，開錯人等於把使用者資料送出去。
     *
     * Horizon 這個 gate 收得到 null（未登入），所以要自己處理沒有 user 的情況。
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            $email = $user instanceof User ? $user->email : null;

            return is_string($email) && $email !== ''
                && in_array($email, self::allowedEmails(), true);
        });
    }

    /**
     * @return list<string>
     */
    private static function allowedEmails(): array
    {
        /** @var list<string> $emails */
        $emails = config('veggiemap.dashboards.allowed_emails', []);

        return $emails;
    }
}
