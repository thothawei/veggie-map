<?php

namespace App\Services\External;

/**
 * Provider 無關的地理編碼結果，見 GeocodingProviderInterface。
 */
final class GeocodedPlace
{
    public function __construct(
        public readonly string $displayName,
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}
}
