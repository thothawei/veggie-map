<?php

namespace Database\Factories;

use App\Models\ExternalApiLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalApiLog>
 */
class ExternalApiLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(['overpass', 'nominatim']),
            'endpoint' => fake()->randomElement(['/api/interpreter', '/search']),
            'status' => 200,
            'response_time_ms' => fake()->numberBetween(50, 2000),
            'success' => true,
            'error_code' => null,
        ];
    }
}
