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
     *
     * @throws GeocodingUnavailableException 上游失敗時丟例外，讓呼叫端不要把失敗結果快取一天。
     */
    public function search(string $query): array;
}
