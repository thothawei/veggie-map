<?php

namespace App\Services;

use App\Jobs\CalculateRestaurantScoreJob;
use App\Models\DietType;
use App\Models\Restaurant;
use App\Services\External\BoundingBox;
use App\Services\External\RestaurantData;
use App\Services\External\RestaurantProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestaurantSyncService
{
    public function __construct(
        private readonly RestaurantProviderInterface $provider,
        private readonly VerificationService $verifications,
    ) {}

    /**
     * @return array{created: int, updated: int, duplicates_flagged: int, skipped: int}
     */
    public function sync(BoundingBox $bbox): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'duplicates_flagged' => 0, 'skipped' => 0];
        $dietTypeIds = DietType::pluck('id', 'code');

        foreach ($this->provider->fetch($bbox) as $data) {
            if (trim($data->name) === '') {
                $stats['skipped']++;

                continue;
            }

            [$restaurant, $wasRecentlyCreated] = $this->upsert($data);
            $stats[$wasRecentlyCreated ? 'created' : 'updated']++;

            $this->syncDietTypes($restaurant, $data->dietCodes, $dietTypeIds);

            if ($this->flagPossibleDuplicates($restaurant)) {
                $stats['duplicates_flagged']++;
            }

            $this->verifications->record($restaurant, 'external_source');

            CalculateRestaurantScoreJob::dispatch($restaurant->id);
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
            'address' => $data->address ?? '',
            'city' => $data->city ?? '',
            'district' => $data->district ?? '',
            'latitude' => $data->latitude,
            'longitude' => $data->longitude,
            'location' => DB::raw("ST_SRID(POINT({$data->longitude}, {$data->latitude}), 4326)"),
            'phone' => $data->phone,
            'website' => $data->website,
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
     * `Str::slug()` 音譯不了純中文名稱（例如「清心蔬食」），會回傳空字串——多數台灣餐廳
     * 匯入後全部撞成同一個 fallback，slug 失去「人類看得懂的 URL」的意義（見
     * docs/database.md 對 `slug` 欄位的說明）。轉不出來就退回用來源＋來源 ID 當種子，
     * 至少每家餐廳的 slug 是不同且可追溯的，不是一堆 `restaurant-2`／`restaurant-3`。
     */
    private function uniqueSlug(string $name, string $sourceId): string
    {
        $base = Str::slug($name) ?: Str::slug($this->provider->sourceName().'-'.$sourceId) ?: 'restaurant';
        $slug = $base;
        $suffix = 1;

        while (Restaurant::where('slug', $slug)->exists()) {
            $slug = "{$base}-".(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  Collection<string, int>  $dietTypeIds
     */
    private function syncDietTypes(Restaurant $restaurant, array $dietCodes, $dietTypeIds): void
    {
        $ids = collect($dietCodes)
            ->map(fn (string $code) => $dietTypeIds->get($code))
            ->filter()
            ->values();

        if ($ids->isNotEmpty()) {
            $restaurant->dietTypes()->syncWithoutDetaching($ids);
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
