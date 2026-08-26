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
        $candidate = self::containsHan($name)
            ? self::ascii(Pinyin::permalink($name))
            : self::ascii($name);

        if ($candidate === '') {
            $candidate = self::ascii($name);
        }

        if ($candidate === '') {
            $candidate = self::ascii($source.'-'.$sourceId) ?: 'restaurant';
        }

        return self::truncate($candidate);
    }

    private static function containsHan(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
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
