<?php

namespace App\Services;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantSlugAlias;
use App\Services\External\BoundingBox;
use App\Services\External\RestaurantData;
use App\Services\External\RestaurantProviderInterface;
use App\Support\CityCatalog;
use App\Support\DietCatalog;
use App\Support\RestaurantSlug;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RestaurantSyncService
{
    public function __construct(
        private readonly RestaurantProviderInterface $provider,
        private readonly VerificationService $verifications,
        private readonly OpeningHoursService $openingHours,
    ) {}

    /**
     * @return array{created: int, updated: int, duplicates_flagged: int, skipped: int}
     */
    public function sync(BoundingBox $bbox): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'duplicates_flagged' => 0, 'skipped' => 0];
        $dietTypeIds = DietType::pluck('id', 'code');
        $featureIds = Feature::pluck('id', 'code');

        foreach ($this->provider->fetch($bbox) as $data) {
            if (trim($data->name) === '') {
                $stats['skipped']++;

                continue;
            }

            [$restaurant, $wasRecentlyCreated] = $this->upsert($data);
            $stats[$wasRecentlyCreated ? 'created' : 'updated']++;

            // 解析在寫入端做一次，查詢端才能用 SQL 篩 open_now（見 OpeningHoursService）。
            $this->openingHours->sync($restaurant);

            $this->syncDietTypes($restaurant, $data->dietCodes, $dietTypeIds);
            $this->syncFeatures($restaurant, [...$data->featureCodes, ...$data->cuisineCodes], $featureIds);

            $restaurant->load('dietTypes');
            $this->verifications->syncExternalSource($restaurant);

            // pivot 寫入不會觸發 Restaurant saved event。同一筆餐廳重跑同步時若欄位
            // 沒變，observer 也不會清快取——detail cache 會繼續吐沒有新特色的舊資料。
            RestaurantCacheInvalidator::invalidate($restaurant->id);

            if ($this->flagPossibleDuplicates($restaurant)) {
                $stats['duplicates_flagged']++;
            }

            // 分數重算改由 RestaurantVerificationObserver 統一觸發（上面的
            // syncExternalSource 一定會寫這張表），這裡不再各自 dispatch 一次。
        }

        return $stats;
    }

    /**
     * @return array{0: Restaurant, 1: bool} [restaurant, wasRecentlyCreated]
     */
    private function upsert(RestaurantData $data): array
    {
        $restaurant = Restaurant::where('source', $this->provider->sourceName())
            ->where('source_id', $data->sourceId)
            ->first();

        $attributes = [
            'name' => $data->name,
            // 來源沒有這個標籤就是 NULL，不是空字串——空字串是一個值，等於宣稱
            // 「這家店的地址是空的」。
            'address' => $data->address,
            'city' => $data->city,
            'district' => $data->district,
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'location' => DB::raw("ST_SRID(POINT({$data->longitude}, {$data->latitude}), 4326)"),
            'phone' => $data->phone,
            'website' => $data->website,
            'opening_hours' => $data->openingHours,
            // 時區依座標落在哪個城市 bbox 決定（config/cities.php）。open_now 要用
            // 該店的當地時間比對，台北與東京差一小時。
            'timezone' => CityCatalog::timezoneFor($data->latitude, $data->longitude),
            'source' => $this->provider->sourceName(),
            'source_id' => $data->sourceId,
            'status' => 'active',
        ];

        if ($restaurant) {
            $restaurant->update($attributes);

            return [$restaurant, false];
        }

        $attributes['slug'] = $this->uniqueSlug($data->name, $data->sourceId);

        return [Restaurant::create($attributes), true];
    }

    /**
     * 漢字走拼音（見 RestaurantSlug），其餘 Str::slug。仍撞名才加 -2。
     * 只在 create 時寫入——重跑 sync 不改 slug，避免已分享的網址失效。
     */
    private function uniqueSlug(string $name, string $sourceId): string
    {
        $base = RestaurantSlug::base($name, $this->provider->sourceName(), $sourceId);
        $slug = $base;
        $suffix = 1;

        // alias 也要避開：舊 slug 仍然解析得到另一家店，新店拿去用的話同一個網址
        // 會有兩個主人，轉址變成不確定的。
        while (Restaurant::where('slug', $slug)->exists() || RestaurantSlugAlias::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  Collection<string, int>  $dietTypeIds
     */
    private function syncDietTypes(Restaurant $restaurant, array $dietCodes, $dietTypeIds): void
    {
        $incomingIds = collect($dietCodes)
            ->map(fn (string $code) => $dietTypeIds->get($code))
            ->filter()
            ->values();

        $managedIds = collect(DietCatalog::osmManagedCodes())
            ->map(fn (string $code) => $dietTypeIds->get($code))
            ->filter()
            ->values();

        // OSM 管得到的 diet 這次算出什麼就同步成什麼（否則 yes→friendly 的修正
        // 永遠拔不掉先前錯掛的 vegetarian）。手動加的、不在 OSM 對應表裡的 code 留下。
        $manualIds = $restaurant->dietTypes()
            ->whereNotIn('diet_types.id', $managedIds->all() ?: [0])
            ->pluck('diet_types.id');

        $restaurant->dietTypes()->sync($manualIds->merge($incomingIds)->unique()->values()->all());
    }

    /**
     * 跟 diet types 同一套做法：對不上任何已知 code 的一律丟掉，不硬塞。用
     * `syncWithoutDetaching` 而不是 `sync`——使用者或 Admin 手動加上的特色不該被
     * 每天的自動同步洗掉，OSM 只負責補充它知道的部分。
     *
     * @param  string[]  $featureCodes
     * @param  Collection<string, int>  $featureIds
     */
    private function syncFeatures(Restaurant $restaurant, array $featureCodes, $featureIds): void
    {
        $ids = collect($featureCodes)
            ->map(fn (string $code) => $featureIds->get($code))
            ->filter()
            ->values();

        if ($ids->isNotEmpty()) {
            $restaurant->features()->syncWithoutDetaching($ids);
        }
    }

    /**
     * 「同名 + 距離 < 100m」視為可能重複，寫入時把新舊兩筆都標記，不自動合併/刪除，
     * 交給 Admin 審核（見 docs/database.md）。
     */
    private function flagPossibleDuplicates(Restaurant $restaurant): bool
    {
        $duplicateIds = Restaurant::query()
            ->where('id', '!=', $restaurant->id)
            ->where('name', $restaurant->name)
            ->whereRaw(
                'ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) < 100',
                [$restaurant->longitude, $restaurant->latitude],
            )
            ->pluck('id');

        if ($duplicateIds->isEmpty()) {
            return false;
        }

        Restaurant::whereIn('id', $duplicateIds->push($restaurant->id))
            ->update(['is_possible_duplicate' => true]);

        return true;
    }
}
