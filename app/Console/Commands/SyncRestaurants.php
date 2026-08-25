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
        {--provider= : 覆蓋 EXTERNAL_API_RESTAURANT_PROVIDER，mock 或 osm，僅供這次執行使用}
        {--diet=only : 收錄規則名稱，必須是 config/diet.php sync_modes 的 key（目前 only／yes）。見 config/services.php 的 sync_regions}';

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

        try {
            $provider = $this->resolveProvider();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $service = new RestaurantSyncService($provider, app(VerificationService::class));

        $this->info("Syncing restaurants via [{$provider->sourceName()}] provider (".class_basename($provider).')...');
        $this->line("收錄規則：diet={$this->option('diet')}");

        $stats = $service->sync($bbox);

        $this->table(
            ['created', 'updated', 'duplicates_flagged', 'skipped'],
            [[$stats['created'], $stats['updated'], $stats['duplicates_flagged'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }

    private function resolveProvider(): RestaurantProviderInterface
    {
        // --provider 沒帶就讀 config。這裡刻意對未知值 throw 而不是靜默退回 mock：
        // AppServiceProvider 的綁定是 `=== 'osm' ? Osm : Mock`，把 EXTERNAL_API_RESTAURANT_PROVIDER
        // 打成 "overpass" 之類的值會安靜地跑 mock，看起來成功卻一筆真資料都沒進來。
        $name = $this->option('provider') ?? config('services.restaurant_provider');

        return match ($name) {
            'osm' => new OsmRestaurantProvider((string) $this->option('diet')),
            'mock' => new MockRestaurantProvider,
            default => throw new \InvalidArgumentException("Unknown provider [{$name}], expected mock or osm."),
        };
    }
}
