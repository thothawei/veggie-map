<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * 誰能在非 local 環境開 `/telescope`。白名單與 Horizon 共用同一份設定
     * （`DASHBOARD_ALLOWED_EMAILS`），預設空的＝沒有人。Telescope 記得到請求內文
     * 與 SQL bindings，比 Horizon 更敏感。
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user): bool {
            /** @var list<string> $allowed */
            $allowed = config('veggiemap.dashboards.allowed_emails', []);

            return in_array($user->email, $allowed, true);
        });
    }
}
