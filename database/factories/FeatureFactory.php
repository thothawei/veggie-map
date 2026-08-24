<?php

namespace Database\Factories;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->randomElement([
            'pet_friendly', 'parking', 'delivery', 'takeout',
            'reservation', 'wifi', 'outdoor_seating', 'family_friendly',
        ]);

        return [
            'code' => $code,
            'label' => str($code)->replace('_', ' ')->title(),
        ];
    }
}
