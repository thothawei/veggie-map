<?php

namespace Tests\Unit;

use App\Services\ReportConsequenceService;
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

    public function test_menu_item_diet_codes_come_from_config(): void
    {
        $codes = DietCatalog::menuItemDietCodes();

        $this->assertSame(array_column(config('diet.menu_item_diets'), 'code'), $codes);
        $this->assertContains('vegan', $codes);
        $this->assertContains('non_vegetarian', $codes);
        $this->assertSame('全素', DietCatalog::menuItemDietLabel('vegan'));
        $this->assertSame('not_in_config', DietCatalog::menuItemDietLabel('not_in_config'));
    }

    public function test_removing_a_menu_item_diet_from_config_drops_it_from_the_catalog(): void
    {
        $this->assertContains('unknown', DietCatalog::menuItemDietCodes());

        config([
            'diet.menu_item_diets' => array_values(array_filter(
                config('diet.menu_item_diets'),
                fn (array $item) => $item['code'] !== 'unknown',
            )),
        ]);

        $this->assertNotContains('unknown', DietCatalog::menuItemDietCodes());
        $this->assertContains('vegan', DietCatalog::menuItemDietCodes());
    }

    public function test_friendly_counterpart_is_derived_from_osm_tag_not_hardcoded_codes(): void
    {
        $this->assertSame('vegan_friendly', DietCatalog::friendlyCounterpart('vegan'));
        $this->assertSame('vegetarian_friendly', DietCatalog::friendlyCounterpart('vegetarian'));
        $this->assertNull(DietCatalog::friendlyCounterpart('ovo_lacto'));
        $this->assertNull(DietCatalog::friendlyCounterpart('vegan_friendly'));
    }

    public function test_menu_empty_message_uses_osm_copy_only_for_osm_source(): void
    {
        $this->assertSame(
            'OSM 標示此店有素食選項，菜單尚未建檔。',
            DietCatalog::menuEmptyMessage('friendly', 'osm'),
        );
        $this->assertSame(
            '標示有素食選項，菜單尚未建檔。',
            DietCatalog::menuEmptyMessage('friendly', 'manual'),
        );
        $this->assertSame(
            '此店為素食餐廳，菜單尚未建檔。',
            DietCatalog::menuEmptyMessage('exclusive', 'manual'),
        );
        $this->assertSame(
            (string) config('diet.copy.menu_empty_fallback'),
            DietCatalog::menuEmptyMessage(null, 'osm'),
        );
    }

    public function test_report_action_reads_config_and_falls_back_to_star_or_noop(): void
    {
        $this->assertSame('demote_to_friendly', DietCatalog::reportAction('not_vegetarian', 'exclusive'));
        $this->assertSame('remove_exclusive_codes', DietCatalog::reportAction('not_vegetarian', 'friendly'));
        $this->assertSame('clear_menu_items', DietCatalog::reportAction('menu_changed', 'exclusive'));
        $this->assertSame('clear_menu_items', DietCatalog::reportAction('menu_changed', null));
        $this->assertSame('noop', DietCatalog::reportAction('closed', 'exclusive'));
        $this->assertSame('noop', DietCatalog::reportAction('wrong_info', null));
    }

    public function test_configured_report_actions_are_all_known_to_the_service(): void
    {
        foreach (DietCatalog::reportActions() as $byKind) {
            foreach ($byKind as $action) {
                $this->assertContains($action, ReportConsequenceService::ACTIONS);
            }
        }
    }

    public function test_yes_sync_mode_includes_the_yes_osm_value(): void
    {
        $this->assertTrue(DietCatalog::syncModeIncludes('yes', 'yes'));
        $this->assertTrue(DietCatalog::syncModeIncludes('yes', 'only'));
        $this->assertFalse(DietCatalog::syncModeIncludes('only', 'yes'));
    }
}
