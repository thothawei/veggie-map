<?php

namespace Tests\Feature;

use Tests\TestCase;

class CitiesTest extends TestCase
{
    public function test_cities_endpoint_returns_the_configured_list(): void
    {
        $response = $this->getJson('/api/v1/cities');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(count(config('cities')), 'data')
            ->assertJsonStructure([
                'data' => [['slug', 'label', 'country', 'center', 'zoom', 'bbox']],
            ]);
    }

    /**
     * 這是整個多城市功能的防線：sync_regions 決定「匯入哪些範圍的資料」，config/cities.php
     * 決定「使用者能切到哪些範圍」。兩邊各自能改，一旦不同步，使用者就會切到一個
     * 從來沒有匯入過資料的城市（空地圖），或反過來有資料卻沒有入口。
     */
    public function test_every_city_bbox_has_a_matching_sync_region(): void
    {
        $syncBboxes = array_column(config('services.sync_regions'), 'bbox');

        $this->assertNotEmpty($syncBboxes, 'sync_regions 是空的，城市清單無從對照');

        foreach (config('cities') as $city) {
            $this->assertContains(
                $city['bbox'],
                $syncBboxes,
                "城市 [{$city['slug']}] 的 bbox 不在 sync_regions 裡——切過去會是空地圖，"
                .'請同步 .env.example 的 EXTERNAL_API_SYNC_BBOXES'
            );
        }
    }

    public function test_every_sync_region_is_reachable_from_the_city_switcher(): void
    {
        $cityBboxes = array_column(config('cities'), 'bbox');

        foreach (config('services.sync_regions') as $region) {
            $this->assertContains(
                $region['bbox'],
                $cityBboxes,
                "sync_regions 有 [{$region['bbox']}] 但城市切換器沒有對應項目——"
                .'每天匯入資料卻沒有任何入口可以看到它們，請補上 config/cities.php'
            );
        }
    }

    public function test_city_centers_fall_inside_their_own_bbox(): void
    {
        foreach (config('cities') as $city) {
            [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', explode(',', $city['bbox']));
            [$lat, $lng] = $city['center'];

            // 開場中心點落在自己的 bbox 外，等於一進去就看不到任何匯入的餐廳。
            $this->assertTrue(
                $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng,
                "城市 [{$city['slug']}] 的 center ({$lat}, {$lng}) 落在自己的 bbox 之外"
            );
        }
    }

    public function test_city_slugs_are_unique(): void
    {
        $slugs = array_column(config('cities'), 'slug');

        $this->assertSame(array_unique($slugs), $slugs, 'slug 重複會讓 ?city= 參數指向不確定的城市');
    }
}
