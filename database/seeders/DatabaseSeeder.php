<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            DietTypeSeeder::class,
            FeatureSeeder::class,
            RestaurantSeeder::class,
            // AI Office 子系統的初始 Agent 陣容（規格第 67 節）。可重複執行。
            AiOfficeAgentSeeder::class,
        ]);
    }
}
