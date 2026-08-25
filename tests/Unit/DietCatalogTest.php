<?php

namespace Tests\Unit;

use App\Support\DietCatalog;
use Tests\TestCase;

class DietCatalogTest extends TestCase
{
    public function test_only_maps_to_exclusive_codes_and_yes_maps_to_friendly_codes(): void
    {
        $this->assertEqualsCanonicalizing(
            ['vegan'],
            DietCatalog::mapOsmTags(['diet:vegan' => 'only']),
        );
        $this->assertEqualsCanonicalizing(
            ['vegetarian'],
            DietCatalog::mapOsmTags(['diet:vegetarian' => 'only']),
        );
        $this->assertEqualsCanonicalizing(
            ['vegan_friendly'],
            DietCatalog::mapOsmTags(['diet:vegan' => 'yes']),
        );
        $this->assertEqualsCanonicalizing(
            ['vegetarian_friendly'],
            DietCatalog::mapOsmTags(['diet:vegetarian' => 'yes']),
        );
        $this->assertEqualsCanonicalizing(
            ['vegetarian', 'vegan_friendly'],
            DietCatalog::mapOsmTags([
                'diet:vegetarian' => 'only',
                'diet:vegan' => 'yes',
            ]),
        );
    }

    public function test_removing_an_osm_value_from_config_stops_the_mapping(): void
    {
        $types = config('diet.types');
        $this->assertContains('vegan_friendly', DietCatalog::mapOsmTags(['diet:vegan' => 'yes']));

        config([
            'diet.types' => array_map(function (array $type) {
                if ($type['code'] === 'vegan_friendly') {
                    $type['osm_values'] = [];
                }

                return $type;
            }, $types),
        ]);

        $this->assertSame([], DietCatalog::mapOsmTags(['diet:vegan' => 'yes']));
        $this->assertSame(['vegan'], DietCatalog::mapOsmTags(['diet:vegan' => 'only']));
    }

    public function test_venue_kind_prefers_exclusive_when_both_are_present(): void
    {
        $this->assertSame('exclusive', DietCatalog::venueKindFromCodes(['vegan']));
        $this->assertSame('friendly', DietCatalog::venueKindFromCodes(['vegetarian_friendly']));
        $this->assertSame('exclusive', DietCatalog::venueKindFromCodes(['vegetarian', 'vegan_friendly']));
        $this->assertSame('exclusive', DietCatalog::venueKindFromCodes(['ovo_lacto']));
        $this->assertNull(DietCatalog::venueKindFromCodes([]));
        $this->assertNull(DietCatalog::venueKindFromCodes(['not_a_real_code']));
    }

    public function test_unknown_sync_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DietCatalog::resolveSyncMode('maybe');
    }

    public function test_venue_scope_keys_come_from_config(): void
    {
        $this->assertContains('exclusive', DietCatalog::venueScopeKeys());
        $this->assertContains('friendly', DietCatalog::venueScopeKeys());
        $this->assertContains('all', DietCatalog::venueScopeKeys());
        $this->assertSame('venue_scope', DietCatalog::venueScopeParam());
    }
}
