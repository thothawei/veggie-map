<?php

namespace Tests\Feature\Api;

use App\Models\Feature;
use App\Support\DietCatalog;
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
            ->assertJsonFragment(['code' => 'vegan', 'kind' => 'exclusive'])
            ->assertJsonFragment(['code' => 'vegetarian_friendly', 'kind' => 'friendly'])
            ->assertJsonPath('meta.venue_scope.param', 'venue_scope')
            ->assertJsonPath('meta.venue_scope.default', 'exclusive');

        $this->assertSame(
            DietCatalog::menuItemDietCodes(),
            array_column($this->getJson('/api/v1/diets')->json('meta.menu_item_diets'), 'code'),
        );
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
