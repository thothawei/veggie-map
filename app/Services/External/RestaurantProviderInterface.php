<?php

namespace App\Services\External;

/**
 * 見 docs/architecture.md 的 Adapter Pattern：`restaurants:sync` 只依賴這個介面，
 * 不管底下是 Overpass 還是本地 fixture，換 Provider 不用動呼叫端一行程式碼。
 */
interface RestaurantProviderInterface
{
    /**
     * @return RestaurantData[]
     */
    public function fetch(BoundingBox $bbox): array;

    public function sourceName(): string;
}
