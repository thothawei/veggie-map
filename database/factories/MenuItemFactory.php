<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\DietCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 60, 480),
            'diet_type' => fake()->randomElement(DietCatalog::menuItemDietCodes()),
            'is_available' => true,
        ];
    }
}
