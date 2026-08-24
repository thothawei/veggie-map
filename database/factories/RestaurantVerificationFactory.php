<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantVerification>
 */
class RestaurantVerificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'verification_type' => fake()->randomElement([
                'restaurant_claim', 'menu_verified', 'user_report',
                'photo_verified', 'external_source', 'admin_verified',
            ]),
            'score' => fake()->numberBetween(10, 20),
            'verified_by' => null,
            'verified_at' => now(),
            'expires_at' => null,
            'metadata' => null,
        ];
    }
}
