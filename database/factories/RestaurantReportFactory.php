<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantReport>
 */
class RestaurantReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'type' => fake()->randomElement([
                'closed', 'not_vegetarian', 'wrong_info',
                'menu_changed', 'wrong_address', 'wrong_hours', 'other',
            ]),
            'description' => fake()->optional()->sentence(),
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }
}
