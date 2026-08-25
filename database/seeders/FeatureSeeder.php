<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Support\CuisineCatalog;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * 固定的餐廳特色清單（見 docs/database.md），不是隨機測試資料，
     * 所以用 seeder 逐筆 upsert 而不是 factory。
     */
    public function run(): void
    {
        $features = [
            'pet_friendly' => '寵物友善',
            'parking' => '停車',
            'delivery' => '外送',
            'takeout' => '外帶',
            'reservation' => '可預約',
            'wifi' => 'WiFi',
            'outdoor_seating' => '戶外座位',
            'family_friendly' => '親子友善',
        ];

        foreach ($features as $code => $label) {
            Feature::updateOrCreate(['code' => $code], ['label' => $label]);
        }

        foreach (CuisineCatalog::types() as $type) {
            Feature::updateOrCreate(['code' => $type['code']], ['label' => $type['label']]);
        }
    }
}
