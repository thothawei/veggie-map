<?php

namespace App\Console\Commands;

use App\Services\External\BoundingBox;
use App\Services\External\MockRestaurantProvider;
use App\Services\External\OsmRestaurantProvider;
use App\Services\External\RestaurantProviderInterface;
use App\Services\RestaurantSyncService;
use App\Services\VerificationService;
use Illuminate\Console\Command;

class SyncRestaurants extends Command
{
    protected $signature = 'restaurants:sync
        {--bbox= : "minLat,minLng,maxLat,maxLng"，必填——一次只查一個小範圍，不要撈全台灣}
        {--provider= : 覆蓋 EXTERNAL_API_RESTAURANT_PROVIDER，mock 或 osm，僅供這次執行使用}';

    protected $description = '從外部資料源（Overpass／本地 fixture）批次匯入餐廳，見 docs/architecture.md';

    public function handle(): int
    {
        $bboxOption = $this->option('bbox');

        if (! $bboxOption) {
            $this->error('--bbox 是必填參數，格式 "minLat,minLng,maxLat,maxLng"。範例（台北市中心約 5km）：');
            $this->line('  php artisan restaurants:sync --bbox=25.00,121.51,25.07,121.58');

            return self::FAILURE;
        }

        try {
            $bbox = BoundingBox::fromString($bboxOption);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $provider = $this->resolveProvider();
        $service = new RestaurantSyncService($provider, app(VerificationService::class));

        $this->info("Syncing restaurants via [{$provider->sourceName()}] provider (".class_basename($provider).')...');

        $stats = $service->sync($bbox);

        $this->table(
            ['created', 'updated', 'duplicates_flagged', 'skipped'],
            [[$stats['created'], $stats['updated'], $stats['duplicates_flagged'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }

    private function resolveProvider(): RestaurantProviderInterface
    {
        $override = $this->option('provider');

        return match ($override) {
            'osm' => new OsmRestaurantProvider,
            'mock' => new MockRestaurantProvider,
            null => app(RestaurantProviderInterface::class),
            default => throw new \InvalidArgumentException("Unknown provider [{$override}], expected mock or osm."),
        };
    }
}
