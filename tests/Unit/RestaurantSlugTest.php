<?php

namespace Tests\Unit;

use App\Support\RestaurantSlug;
use Tests\TestCase;

class RestaurantSlugTest extends TestCase
{
    public function test_chinese_name_becomes_pinyin(): void
    {
        $this->assertSame('qing-xin-shu-shi', RestaurantSlug::base('清心蔬食', 'osm', 'node-1'));
        $this->assertSame('shi-fang-zhai', RestaurantSlug::base('十方齋', 'osm', 'node-1'));
    }

    public function test_ascii_name_does_not_need_pinyin(): void
    {
        $this->assertSame('shi-fang-zhai', RestaurantSlug::base('Shi Fang Zhai', 'osm', 'node-1'));
    }

    public function test_name_with_no_transliteration_falls_back_to_source_seed(): void
    {
        $this->assertSame('osm-node-1', RestaurantSlug::base('😊', 'osm', 'node-1'));
    }

    /**
     * 反向：若漢字沒走拼音，Str::slug 是空的，會掉進 osm-node-1。
     * 這條鎖住的是「清心蔬食不要再變成 osm-node-1」。
     */
    public function test_chinese_name_is_not_the_source_id_fallback(): void
    {
        $this->assertNotSame('osm-node-1', RestaurantSlug::base('清心蔬食', 'osm', 'node-1'));
    }
}
