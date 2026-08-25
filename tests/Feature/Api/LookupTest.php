<?php

namespace Tests\Feature\Api;

use App\Models\Feature;
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

        $codes = array_column($this->getJson('/api/v1/features')->assertOk()->json('data'), 'code');

        $this->assertCount(8, $codes);
        $this->assertEqualsCanonicalizing(Feature::CODES, $codes);
        $this->assertContains('takeout', $codes);
        $this->assertContains('pet_friendly', $codes);
    }
}
