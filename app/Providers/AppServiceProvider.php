<?php

namespace App\Providers;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use App\Observers\RestaurantConfidenceScoreObserver;
use App\Observers\RestaurantObserver;
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
            return config('services.restaurant_provider') === 'osm'
                ? new OsmRestaurantProvider
                : new MockRestaurantProvider;
        });

        // 地址搜尋只有 Nominatim 一種 provider（docs/external-apis.md 已核准），沒有像
        // restaurant provider 那樣需要在 mock/real 之間切換，綁死即可，不過度設計。
        $this->app->bind(GeocodingProviderInterface::class, NominatimGeocodingProvider::class);

        // 見 docs/architecture.md「AI 預留」：第一版只有 RuleBasedRecommendationService，
        // 未來要換 AIRecommendationService 只改這一行綁定，Controller 不用動。
        $this->app->bind(RecommendationServiceInterface::class, RuleBasedRecommendationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Restaurant::observe(RestaurantObserver::class);
        RestaurantConfidenceScore::observe(RestaurantConfidenceScoreObserver::class);

        // 見總體規劃第十六節：/api/v1/restaurants 用 Redis-based rate limiter（底層
        // Cache::store() 走 CACHE_STORE=redis，不用額外套件）。依登入使用者 id 或 IP 分桶，
        // 已登入使用者不會因為同一台 NAT 底下其他匿名使用者而被牽連。
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
