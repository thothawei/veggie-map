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
        public readonly array $dietCodes = [],
    ) {}
}
