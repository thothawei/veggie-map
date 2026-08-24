<?php

namespace App\Providers;

use App\Services\External\GeocodingProviderInterface;
use App\Services\External\MockRestaurantProvider;
use App\Services\External\NominatimGeocodingProvider;
use App\Services\External\OsmRestaurantProvider;
use App\Services\External\RestaurantProviderInterface;
use App\Services\Recommendation\RecommendationServiceInterface;
use App\Services\Recommendation\RuleBasedRecommendationService;
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
        //
    }
}
