<?php

namespace App\Support;

use Illuminate\Support\Str;
use Overtrue\Pinyin\Pinyin;

/**
 * 餐廳 slug 的「人類看得懂」那一段。唯一性（撞名加 -2）仍由呼叫端用 DB 決定，
 * 這裡只負責把店名變成 ASCII。
 *
 * `Str::slug()` 音譯不了漢字（容器沒裝 intl），純中文店名會得到空字串，以前只能
 * 退回 `osm-node-123`。有漢字就走拼音。
 */
class RestaurantSlug
{
    /** varchar(255)，預留後綴 `-999` 的空間。 */
    private const MAX_BASE_LENGTH = 200;

    public static function base(string $name, string $source, string $sourceId): string
    {
        $candidate = self::ascii(self::transliterateHan($name));

        if ($candidate === '') {
            $candidate = self::ascii($source.'-'.$sourceId) ?: 'restaurant';
        }

        return self::truncate($candidate);
    }

    /**
     * 只把漢字段落換成拼音，其餘字元原樣留給 `Str::slug()`。
     *
     * 不能整串丟 `Pinyin::permalink()`：它把非漢字字元一律當分隔符移除再用自己的
     * delimiter 重組，`DuBuque-Erdman 全素` 會變成 `DuBuqueErdman-quan-su`——英文
     * 店名裡原本的連字號被吃掉，兩個字黏在一起。
     */
    private static function transliterateHan(string $name): string
    {
        $result = preg_replace_callback(
            '/\p{Han}+/u',
            fn (array $m): string => ' '.Pinyin::permalink($m[0]).' ',
            $name,
        );

        return $result ?? $name;
    }

    private static function ascii(string $text): string
    {
        return Str::slug($text);
    }

    private static function truncate(string $slug): string
    {
        if (strlen($slug) <= self::MAX_BASE_LENGTH) {
            return $slug;
        }

        return rtrim(substr($slug, 0, self::MAX_BASE_LENGTH), '-');
    }
}
