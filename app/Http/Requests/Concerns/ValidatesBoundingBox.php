<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesBoundingBox
{
    protected function validateBboxIfPresent(Validator $validator): void
    {
        if (! $this->filled('bbox')) {
            return;
        }

        $parts = array_map('trim', explode(',', (string) $this->input('bbox')));

        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            $validator->errors()->add('bbox', 'bbox must be "minLat,minLng,maxLat,maxLng".');

            return;
        }

        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);

        if ($minLat < -90 || $maxLat > 90 || $minLng < -180 || $maxLng > 180) {
            $validator->errors()->add('bbox', 'bbox coordinates are out of range.');

            return;
        }

        // 顛倒的角落會產生一個面積為零或負的矩形，MBRContains 只會安靜地回傳零筆，
        // 看起來像「這個範圍沒有餐廳」而不是「參數寫反了」。
        if ($minLat >= $maxLat || $minLng >= $maxLng) {
            $validator->errors()->add('bbox', 'bbox min corner must be south-west of the max corner.');
        }
    }
}
