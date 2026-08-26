<?php

namespace App\Providers;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use App\Models\RestaurantVerification;
use App\Observers\MenuItemObserver;
use App\Observers\RestaurantConfidenceScoreObserver;
use App\Observers\RestaurantObserver;
use App\Observers\RestaurantVerificationObserver;
use App\Services\External\GeocodingProviderInterface;
use App\Services\External\MockRestaurantProvider;
use App\Services\External\NominatimGeocodingProvider;
use App\Services\External\OsmRestaurantProvider;
use App\Services\External\RestaurantProviderInterface;
use App\Services\Recommendation\RecommendationServiceInterface;
use App\Services\Recommendation\RuleBasedRecommendationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // EXTERNAL_API_RESTAURANT_PROVIDER=mock｜osm（見 config/services.php、
        // docs/external-apis.md）。預設 mock，避免開發／測試環境不小心打到真的 Overpass。
        $this->app->bind(RestaurantProviderInterface::class, function () {
            // 未知值必須 throw，不能靜默退回 mock：填 `overpass` 之類的值看起來
            // 成功、一筆真資料都沒進來。restaurants:sync 那條路徑已經這樣擋了。
            $name = config('services.restaurant_provider');

            return match ($name) {
                'osm' => new OsmRestaurantProvider,
                'mock' => new MockRestaurantProvider,
                default => throw new \InvalidArgumentException(
                    "Unknown restaurant provider [{$name}], expected mock or osm."
                ),
            };
        });

        // 地址搜尋只有 Nominatim 一種 provider（docs/external-apis.md 已核准），沒有像
        // restaurant provider 那樣需要在 mock/real 之間切換，綁死即可，不過度設計。
        $this->app->bind(GeocodingProviderInterface::class, NominatimGeocodingProvider::class);

        // 見 docs/architecture.md「AI 預留」：第一版只有 RuleBasedRecommendationService，
        // 未來要換 AIRecommendationService 只改這一行綁定，Controller 不用動。
        $this->app->bind(RecommendationServiceInterface::class, RuleBasedRecommendationService::class);

        // Telescope（debug 模式：檢視 request／SQL query／job／cache/exception）只在
        // local／testing 註冊，不進 `bootstrap/providers.php` 的固定清單——避免它在
        // production 多佔一份中介層開銷，也避免 `/telescope` 路由在 production 上存在
        // （即使 TelescopeServiceProvider 的 gate() 已經預設擋掉沒有列進白名單的使用者，
        // 兩層防護比只靠一層 gate 保守）。
        if ($this->app->environment(['local', 'testing'])) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Restaurant::observe(RestaurantObserver::class);
        RestaurantConfidenceScore::observe(RestaurantConfidenceScoreObserver::class);
        MenuItem::observe(MenuItemObserver::class);
        RestaurantVerification::observe(RestaurantVerificationObserver::class);

        // 見總體規劃第十六節：/api/v1/restaurants 用 Redis-based rate limiter（底層
        // Cache::store() 走 CACHE_STORE=redis，不用額外套件）。依登入使用者 id 或 IP 分桶，
        // 已登入使用者不會因為同一台 NAT 底下其他匿名使用者而被牽連。
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 自動完成天生就會比一般端點多打很多次：使用者每打幾個字就是一次請求
        // （前端已經 debounce 250ms，但打一句話仍然是好幾發）。跟一般 API 共用
        // 60/分鐘的話，正常打字幾輪就會撞上 429，搜尋建議整個消失。
        // 額度另外開，但仍然有上限——這條端點不帶認證，一樣會被打。
        RateLimiter::for('suggest', function (Request $request) {
            return Limit::perMinute((int) config('veggiemap.search.suggest_rate_limit', 180))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
