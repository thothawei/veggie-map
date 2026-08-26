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

    /**
     * 整串丟 Pinyin::permalink 會把英文段落原本的連字號吃掉
     * （`DuBuque-Erdman 全素` → `DuBuqueErdman-quan-su`），兩個字黏在一起。
     * 只轉漢字段落才留得住。
     */
    public function test_mixed_name_keeps_the_hyphen_inside_the_ascii_part(): void
    {
        $this->assertSame(
            'dubuque-erdman-quan-su',
            RestaurantSlug::base('DuBuque-Erdman 全素', 'osm', 'node-1'),
        );
    }

    public function test_mixed_name_keeps_the_original_word_order(): void
    {
        $this->assertSame(
            'qing-xin-shu-shi-vegan',
            RestaurantSlug::base('清心蔬食 Vegan', 'osm', 'node-1'),
        );
    }
}
