<?php

namespace App\Services\External;

/**
 * `restaurants:sync` 一律用 bounding box 分批查詢，不能一次撈全台灣
 * （見 docs/external-apis.md），這個 value object 就是那個分批單位。
 */
final class BoundingBox
{
    public function __construct(
        public readonly float $minLat,
        public readonly float $minLng,
        public readonly float $maxLat,
        public readonly float $maxLng,
    ) {
    }

    public static function fromString(string $csv): self
    {
        $parts = array_map('trim', explode(',', $csv));

        if (count($parts) !== 4 || array_filter($parts, fn ($p) => ! is_numeric($p))) {
            throw new \InvalidArgumentException('bbox 必須是 "minLat,minLng,maxLat,maxLng" 四個數字，用逗號分隔。');
        }

        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);

        return new self($minLat, $minLng, $maxLat, $maxLng);
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat >= $this->minLat && $lat <= $this->maxLat
            && $lng >= $this->minLng && $lng <= $this->maxLng;
    }
}
