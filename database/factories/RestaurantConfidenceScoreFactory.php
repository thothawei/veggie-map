<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantConfidenceScore>
 */
class RestaurantConfidenceScoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'score' => fake()->numberBetween(0, 100),
            'calculated_at' => now(),
        ];
    }
}
