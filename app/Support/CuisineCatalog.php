<?php

namespace App\Support;

/**
 * config/cuisine.php 的讀取層。OSM `cuisine` 標籤對應、API 是否把某個 feature
 * 當成菜系，都從這裡問，不要在 Provider／Resource 再寫一份清單。
 */
class CuisineCatalog
{
    /**
     * @return list<array{code: string, label: string}>
     */
    public static function types(): array
    {
        /** @var list<array<string, mixed>> $types */
        $types = config('cuisine.types', []);

        return array_map(fn (array $type): array => [
            'code' => (string) $type['code'],
            'label' => (string) $type['label'],
        ], $types);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::types(), 'code');
    }

    public static function isCuisine(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    public static function label(string $code): ?string
    {
        foreach (self::types() as $type) {
            if ($type['code'] === $code) {
                return $type['label'];
            }
        }

        return null;
    }

    /**
     * OSM cuisine 是分號或逗號分隔。對不上 config 的值丟掉，不拿店名去猜。
     *
     * @return list<string>
     */
    public static function mapOsmCuisine(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $labels = [];
        foreach (self::types() as $type) {
            $labels[$type['code']] = $type['label'];
        }

        $codes = [];

        foreach (preg_split('/[;,]/', strtolower($raw)) ?: [] as $token) {
            $token = trim($token);

            if ($token !== '' && isset($labels[$token])) {
                $codes[] = $token;
            }
        }

        return array_values(array_unique($codes));
    }
}
