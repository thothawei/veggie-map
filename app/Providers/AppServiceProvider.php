<?php

namespace App\Providers;

use App\Services\External\MockRestaurantProvider;
use App\Services\External\OsmRestaurantProvider;
use App\Services\External\RestaurantProviderInterface;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
