<?php

namespace Database\Seeders;

use App\Models\DietType;
use App\Support\DietCatalog;
use Illuminate\Database\Seeder;

class DietTypeSeeder extends Seeder
{
    /**
     * 清單來源是 config/diet.php，這裡只負責 upsert 進 diet_types。
     */
    public function run(): void
    {
        foreach (DietCatalog::types() as $type) {
            DietType::updateOrCreate(
                ['code' => $type['code']],
                ['label' => $type['label']],
            );
        }
    }
}
