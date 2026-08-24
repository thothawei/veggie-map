<?php

namespace Database\Seeders;

use App\Models\DietType;
use Illuminate\Database\Seeder;

class DietTypeSeeder extends Seeder
{
    /**
     * 固定的飲食類型清單（見 docs/database.md），不是隨機測試資料，
     * 所以用 seeder 逐筆 upsert 而不是 factory。
     */
    public function run(): void
    {
        $types = [
            'vegan' => '全素（Vegan）',
            'vegetarian' => '素食（Vegetarian）',
            'ovo_lacto' => '蛋奶素（Ovo-Lacto）',
            'lacto' => '奶素（Lacto）',
            'ovo' => '蛋素（Ovo）',
            'vegan_friendly' => '全素友善',
            'vegetarian_friendly' => '素食友善',
        ];

        foreach ($types as $code => $label) {
            DietType::updateOrCreate(['code' => $code], ['label' => $label]);
        }
    }
}
