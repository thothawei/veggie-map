<?php

namespace Tests\Feature\Api;

use Database\Seeders\DietTypeSeeder;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_diets_returns_seeded_list(): void
    {
        $this->seed(DietTypeSeeder::class);

        $this->getJson('/api/v1/diets')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonFragment(['code' => 'vegan']);
    }

    public function test_features_returns_seeded_list(): void
    {
        $this->seed(FeatureSeeder::class);

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonFragment(['code' => 'pet_friendly']);
    }
}
