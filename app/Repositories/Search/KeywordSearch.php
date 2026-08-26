<?php

namespace App\Repositories\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /restaurants?keyword=` 的斷詞、比對範圍與相關性排序。
 *
 * 抽成獨立類別而不是留在 Repository：WHERE（決定「哪些店算命中」）與相關性
 * 運算式（決定「命中的排前面」）必須用**同一組欄位**，散在兩個地方遲早會漂移——
 * 例如加了菜色比對卻忘了給分，使用者搜「滷味」找得到店但排在第 40 名。
 *
 * 比對範圍：店名、地址、城市、行政區、描述、菜色名稱、料理種類標籤。
 * 之所以不是只有店名：素食使用者常用的搜尋詞是「拉麵」「滷味」「泰式」——
 * 那些是菜色與料理種類，不是店名。
 */
final class KeywordSearch
{
    /** 相關性權重。差距要夠大，讓「店名命中」永遠排在「地址剛好有這兩個字」前面。 */
    private const SCORE_NAME_EXACT = 100;

    private const SCORE_NAME_PREFIX = 60;

    private const SCORE_NAME_CONTAINS = 40;

    private const SCORE_MENU_ITEM = 25;

    private const SCORE_CUISINE = 20;

    private const SCORE_LOCALITY = 10;

    private const SCORE_DESCRIPTION = 5;

    /**
     * 斷詞。
     *
     * 空白／逗號分隔，多詞是 AND（「台中 拉麵」要兩個條件都命中）。中日文沒有
     * 空白斷詞，所以整串當一個詞——這裡刻意不做 n-gram 或分詞器：那需要 ngram
     * parser 與 FULLTEXT 索引，對這個資料量沒有效益。
     *
     * 長度門檻是用來砍掉多詞查詢裡的雜訊詞（「台中 拉麵 a」的 a），**不是**用來
     * 讓整個關鍵字消失：全部被砍光時退回用原字串當單一詞。否則使用者打一個字
     * 會拿到「全部餐廳」，看起來像搜尋壞掉。
     *
     * @return list<string>
     */
    public static function terms(string $keyword): array
    {
        $parts = preg_split('/[\s,，、]+/u', trim($keyword)) ?: [];
        $maxTerms = (int) config('veggiemap.search.keyword_max_terms', 5);

        $terms = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || mb_strlen($part) < self::minLength($part)) {
                continue;
            }

            $terms[] = $part;

            if (count($terms) >= $maxTerms) {
                break;
            }
        }

        if ($terms === []) {
            $fallback = trim($keyword);

            return $fallback === '' ? [] : [$fallback];
        }

        return $terms;
    }

    /**
     * 每個詞都必須命中（AND），但可以命中任一個欄位（OR）。
     *
     * @param  list<string>  $terms
     */
    public static function applyTo(Builder $query, array $terms): void
    {
        foreach ($terms as $term) {
            $like = '%'.self::escapeLike($term).'%';

            $query->where(function (Builder $q) use ($like, $term) {
                $q->where('restaurants.name', 'like', $like)
                    ->orWhere('restaurants.address', 'like', $like)
                    ->orWhere('restaurants.city', 'like', $like)
                    ->orWhere('restaurants.district', 'like', $like)
                    ->orWhere('restaurants.description', 'like', $like)
                    // 菜色與料理種類：搜「拉麵」要找得到有拉麵的店，即使店名沒有這兩個字。
                    ->orWhereHas('menuItems', fn (Builder $m) => $m->where('name', 'like', $like))
                    ->orWhereHas('features', fn (Builder $f) => $f
                        ->where('label', 'like', $like)
                        ->orWhere('code', 'like', $like))
                    // 完全相同的店名一定要命中，即使它短於斷詞門檻。
                    ->orWhere('restaurants.name', '=', $term);
            });
        }
    }

    /**
     * 相關性運算式。回傳可直接放進 selectRaw 的 SQL 與 bindings。
     *
     * 用 CASE 加總而不是 MySQL 全文檢索：全文檢索的中文斷詞需要 ngram parser 與
     * 額外的 FULLTEXT 索引，對這個資料量（數百筆）沒有效益，卻會讓權重不可控——
     * 這裡的重點是「店名命中排在地址命中前面」這種產品判斷，不是相似度演算法。
     *
     * @param  list<string>  $terms
     * @return array{0: string, 1: list<string>}
     */
    public static function relevanceExpression(array $terms): array
    {
        $pieces = [];
        $bindings = [];

        foreach ($terms as $term) {
            $escaped = self::escapeLike($term);
            $contains = '%'.$escaped.'%';
            $prefix = $escaped.'%';

            $pieces[] = '(CASE WHEN restaurants.name = ? THEN '.self::SCORE_NAME_EXACT
                .' WHEN restaurants.name LIKE ? THEN '.self::SCORE_NAME_PREFIX
                .' WHEN restaurants.name LIKE ? THEN '.self::SCORE_NAME_CONTAINS
                .' ELSE 0 END)';
            $bindings[] = $term;
            $bindings[] = $prefix;
            $bindings[] = $contains;

            $pieces[] = '(CASE WHEN EXISTS (SELECT 1 FROM menu_items mi'
                .' WHERE mi.restaurant_id = restaurants.id AND mi.name LIKE ?) THEN '
                .self::SCORE_MENU_ITEM.' ELSE 0 END)';
            $bindings[] = $contains;

            $pieces[] = '(CASE WHEN EXISTS (SELECT 1 FROM restaurant_features rf'
                .' JOIN features f ON f.id = rf.feature_id'
                .' WHERE rf.restaurant_id = restaurants.id AND (f.label LIKE ? OR f.code LIKE ?)) THEN '
                .self::SCORE_CUISINE.' ELSE 0 END)';
            $bindings[] = $contains;
            $bindings[] = $contains;

            $pieces[] = '(CASE WHEN restaurants.city LIKE ? OR restaurants.district LIKE ?'
                .' OR restaurants.address LIKE ? THEN '.self::SCORE_LOCALITY.' ELSE 0 END)';
            $bindings[] = $contains;
            $bindings[] = $contains;
            $bindings[] = $contains;

            $pieces[] = '(CASE WHEN restaurants.description LIKE ? THEN '
                .self::SCORE_DESCRIPTION.' ELSE 0 END)';
            $bindings[] = $contains;
        }

        return [implode(' + ', $pieces), $bindings];
    }

    /**
     * 中日文一個字就是一個有意義的詞（「麵」「粥」），拉丁字母一個字元不是。
     * 兩種門檻分開設在 config，不要用同一個數字。
     */
    private static function minLength(string $term): int
    {
        $isCjk = preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u', $term) === 1;

        return (int) config(
            $isCjk ? 'veggiemap.search.keyword_min_length_cjk' : 'veggiemap.search.keyword_min_length',
            $isCjk ? 1 : 2,
        );
    }

    /**
     * `%` 與 `_` 在 LIKE 裡是萬用字元。不跳脫的話，搜尋「100%純素」會退化成
     * 「1、0、0、任意字串、純素」——命中一堆不相干的店，而且使用者無從理解。
     *
     * public 是因為「命中原因」那段查詢（RestaurantRepository::attachMatchReasons）
     * 要用同一套規則。兩邊各寫一份的話，日後只改一邊就會出現「搜尋跳脫了、
     * 標示命中原因沒跳脫」這種只在特定關鍵字下才現形的不一致。
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
