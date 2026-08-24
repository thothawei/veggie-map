<?php

namespace App\Services\External;

/**
 * 見 docs/architecture.md 的 Adapter Pattern：使用者在搜尋框輸入地名/地標時走這個介面，
 * 換成 Google/Mapbox 等付費地理編碼商不需要動呼叫端（GeocodeController）。
 */
interface GeocodingProviderInterface
{
    /**
     * @return GeocodedPlace[]
     */
    public function search(string $query): array;
}
