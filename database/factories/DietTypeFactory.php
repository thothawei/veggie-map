<?php

namespace Database\Factories;

use App\Models\DietType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DietType>
 */
class DietTypeFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->randomElement([
            'vegan', 'vegetarian', 'ovo_lacto', 'lacto', 'ovo', 'vegan_friendly', 'vegetarian_friendly',
        ]);

        return [
            'code' => $code,
            'label' => str($code)->replace('_', ' ')->title(),
        ];
    }
}
