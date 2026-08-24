<?php

namespace App\Services\External;

/**
 * Overpass 斷線、改政策、或本機無網路時的保底（規則第 14 條：無法穩定使用的外部服務要有
 * Mock Provider）。跟 OsmRestaurantProvider 用同一個 `sourceName()`（'osm'），因為這是
 * 「假裝自己是 OSM 資料源」的替身，不是另一種資料源，下游（dedup／source_id 命名空間）
 * 不用知道資料實際上是從 fixture 檔讀出來的。
 */
class MockRestaurantProvider implements RestaurantProviderInterface
{
    public function fetch(BoundingBox $bbox): array
    {
        $path = storage_path('app/mock/restaurants.json');

        if (! file_exists($path)) {
            return [];
        }

        $rows = json_decode(file_get_contents($path), true) ?? [];

        return collect($rows)
            ->filter(fn (array $row) => $bbox->contains((float) $row['latitude'], (float) $row['longitude']))
            ->map(fn (array $row) => new RestaurantData(
                sourceId: (string) $row['source_id'],
                name: $row['name'],
                latitude: (float) $row['latitude'],
                longitude: (float) $row['longitude'],
                address: $row['address'] ?? null,
                city: $row['city'] ?? null,
                district: $row['district'] ?? null,
                phone: $row['phone'] ?? null,
                website: $row['website'] ?? null,
                dietCodes: $row['diet_codes'] ?? [],
            ))
            ->values()
            ->all();
    }

    public function sourceName(): string
    {
        return 'osm';
    }
}
