<?php

namespace App\Services\External;

/**
 * Provider 無關的中間格式：`OsmRestaurantProvider`／`MockRestaurantProvider` 都轉成這個
 * 形狀，`RestaurantSyncService` 只認這個 DTO，不用管資料原本長什麼樣子。
 */
final class RestaurantData
{
    /**
     * @param  string[]  $dietCodes  對應 diet_types.code（見 docs/database.md），
     *                               來源標籤對不上任何已知 code 的一律丟掉，不硬塞。
     * @param  string[]  $featureCodes  對應 features.code，同上。
     * @param  string[]  $cuisineCodes  OSM cuisine 對上 config/cuisine.php 的值。
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $name,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $phone = null,
        public readonly ?string $website = null,
        /** OSM `opening_hours` 原始字串，解析交給 App\Support\OpeningHours，provider 不解讀。 */
        public readonly ?string $openingHours = null,
        public readonly array $dietCodes = [],
        public readonly array $featureCodes = [],
        public readonly array $cuisineCodes = [],
    ) {}
}
