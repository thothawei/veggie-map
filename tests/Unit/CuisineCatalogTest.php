<?php

namespace Tests\Unit;

use App\Models\Feature;
use App\Support\CuisineCatalog;
use Tests\TestCase;

class CuisineCatalogTest extends TestCase
{
    public function test_known_osm_values_map_to_codes(): void
    {
        $this->assertEqualsCanonicalizing(
            ['japanese', 'thai'],
            CuisineCatalog::mapOsmCuisine('japanese;thai;vegetarian'),
        );
        $this->assertSame(['stir_fry'], CuisineCatalog::mapOsmCuisine('stir_fry'));
        $this->assertSame(['chinese', 'stir_fry'], CuisineCatalog::mapOsmCuisine('chinese, stir_fry'));
    }

    public function test_vegetarian_is_not_treated_as_a_cuisine(): void
    {
        $this->assertSame([], CuisineCatalog::mapOsmCuisine('vegetarian;vegan'));
    }

    public function test_unknown_values_are_dropped(): void
    {
        $this->assertSame([], CuisineCatalog::mapOsmCuisine('spaceship_food'));
        $this->assertSame([], CuisineCatalog::mapOsmCuisine(null));
        $this->assertSame([], CuisineCatalog::mapOsmCuisine('  '));
    }

    public function test_removing_a_value_from_config_stops_the_mapping(): void
    {
        $this->assertContains('thai', CuisineCatalog::mapOsmCuisine('thai'));

        config(['cuisine.types' => array_values(array_filter(
            config('cuisine.types'),
            fn (array $type) => $type['code'] !== 'thai',
        ))]);

        $this->assertSame([], CuisineCatalog::mapOsmCuisine('thai'));
        $this->assertSame(['japanese'], CuisineCatalog::mapOsmCuisine('japanese;thai'));
    }

    public function test_cuisine_codes_do_not_collide_with_amenity_features(): void
    {
        $overlap = array_intersect(CuisineCatalog::codes(), Feature::CODES);

        $this->assertSame([], $overlap);
    }
}
