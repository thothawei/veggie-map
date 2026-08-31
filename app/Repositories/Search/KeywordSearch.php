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
     * 飲食類型命中的分數。
     *
     * 刻意是最低的一級：每一家店都有 diet 標籤，所以「命中 diet」幾乎不帶資訊——
     * 搜「素食」會對到全部的店。它的價值在於**讓那些詞不再是死路**
     * （打「蛋奶素」以前一筆都沒有），而不是把結果排前面。
     * 排序仍然由店名、菜色、料理種類決定。
     */
    private const SCORE_DIET = 2;

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
     * 把每個查詢詞展開成一組同義變體。
     *
     * 回傳的是「群組的列表」：群組**之間**仍然是 AND（「台中 拉麵」兩個條件都要中），
     * 群組**之內**是 OR（「拉麵」或「ramen」中一個就算）。這個形狀是刻意的——
     * 把同義詞攤平成一維陣列會讓多詞查詢從 AND 變成 OR，「台中 拉麵」會開始
     * 回傳所有台中的店。
     *
     * 詞表在 config/veggiemap.php 的 search.synonyms，一個詞可能落在多個群組
     * （「外賣」在台灣多指外帶、別處指外送），這時把所有含它的群組合併。
     *
     * 原詞永遠是群組的第一個元素，而且不會被 max_variants 截掉——展開是為了
     * 多找到東西，不能反過來把使用者真正打的那個詞弄丟。
     *
     * @param  list<string>  $terms
     * @return list<list<string>>
     */
    public static function expand(array $terms): array
    {
        $groups = [];

        foreach ($terms as $term) {
            $variants = [$term];
            $needles = self::lookupForms($term);

            // 剝掉詞綴後的字本身也是變體：「素食滷味」的「滷味」不在任何同義詞群組裡，
            // 但資料庫有一家「滷味」——不加它就等於這個查詢沒救。
            foreach ($needles as $needle) {
                if ($needle !== mb_strtolower($term)) {
                    $variants[] = $needle;
                }
            }

            foreach (self::synonymGroups() as $group) {
                $lowered = array_map(mb_strtolower(...), $group);

                if (array_intersect($needles, $lowered) === []) {
                    continue;
                }

                foreach ($group as $word) {
                    if (! in_array(mb_strtolower($word), $needles, true)) {
                        $variants[] = $word;
                    }
                }
            }

            $max = max(1, (int) config('veggiemap.search.max_variants', 8));
            $groups[] = array_slice(array_values(array_unique($variants)), 0, $max);
        }

        return $groups;
    }

    /**
     * 一個查詢詞的「查表用形態」：原詞，加上剝掉詞綴後的字。
     *
     * 為什麼需要它：同義詞是查**整個詞**，所以「素食便當」「麵店」「珍珠奶茶店」
     * 這種複合寫法一個群組都對不到。2026-08-31 在台中 bbox 實測，基本詞有結果、
     * 複合詞是 0：便當 0 ／ 早餐 7 但早餐店 0 ／ 麵 14 但麵店 0 ／
     * 珍珠奶茶 1 但珍珠奶茶店 0 ／ 火鍋 7 但素食火鍋 0。這在台灣不是偶發——
     * 台灣使用者本來就會打「素食＋品項」或「品項＋店」。
     *
     * 刻意用「剝詞綴」而不是「群組詞有被包含就算命中」：後者會讓「麵包」對到
     * 麵／noodle 那一組，麵包店的搜尋結果混進一堆麵店。
     *
     * @return list<string> 一律小寫，原詞永遠是第一個
     */
    private static function lookupForms(string $term): array
    {
        $term = mb_strtolower($term);
        $forms = [$term];

        // 詞表裡本來就有的詞不剝：「素食」剝掉「素」會剩一個「食」，那會對到所有
        // 名字裡有「食」的店；「拉麵」剝成「麵」則會把一家拉麵店的搜尋擴散成所有麵店。
        foreach (self::synonymGroups() as $group) {
            if (in_array($term, array_map(mb_strtolower(...), $group), true)) {
                return $forms;
            }
        }

        /** @var array{prefixes?: list<string>, suffixes?: list<string>} $affixes */
        $affixes = config('veggiemap.search.affixes', []);

        $stripped = [$term];

        foreach (['prefixes', 'suffixes'] as $kind) {
            $next = $stripped;

            foreach ($stripped as $candidate) {
                foreach ($affixes[$kind] ?? [] as $affix) {
                    $affix = mb_strtolower($affix);
                    $trimmed = $kind === 'prefixes'
                        ? (str_starts_with($candidate, $affix) ? mb_substr($candidate, mb_strlen($affix)) : null)
                        : (str_ends_with($candidate, $affix) ? mb_substr($candidate, 0, -mb_strlen($affix)) : null);

                    // 剝到只剩空字串就不是一個查詢詞了（「素食」剝掉「素食」）。
                    if ($trimmed !== null && $trimmed !== '') {
                        $next[] = $trimmed;

                        break;
                    }
                }
            }

            $stripped = array_values(array_unique($next));
        }

        foreach ($stripped as $form) {
            if ($form !== $term) {
                $forms[] = $form;
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * @return list<list<string>>
     */
    private static function synonymGroups(): array
    {
        /** @var list<list<string>> $groups */
        $groups = config('veggiemap.search.synonyms', []);

        return $groups;
    }

    /**
     * 每個詞都必須命中（AND），但可以命中任一個欄位（OR）。
     *
     * 吃的是 expand() 產出的群組：群組之間 AND，群組之內（同義變體）OR。
     *
     * @param  list<list<string>>  $groups
     */
    public static function applyTo(Builder $query, array $groups): void
    {
        foreach ($groups as $variants) {
            $query->where(function (Builder $outer) use ($variants) {
                foreach ($variants as $term) {
                    $like = '%'.self::escapeLike($term).'%';

                    $outer->orWhere(function (Builder $q) use ($like, $term) {
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
                            // 飲食類型：打「蛋奶素」「vegan」要找得到，在這之前
                            // 那些詞一筆都回不來——標籤明明就在資料裡。
                            ->orWhereHas('dietTypes', fn (Builder $d) => $d
                                ->where('label', 'like', $like)
                                ->orWhere('code', 'like', $like))
                            // 飲食類型：打「蛋奶素」「vegan」要找得到，在這之前
                            // 那些詞一筆都回不來——標籤明明就在資料裡。

                            // 完全相同的店名一定要命中，即使它短於斷詞門檻。
                            ->orWhere('restaurants.name', '=', $term);
                    });
                }
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
     * 同義變體與原詞**同分**。刻意不給同義詞打折：使用者搜「珍珠奶茶」時，
     * 一家標成「手搖飲」的店就是他要找的店，排在後面沒有道理。分數的階梯要表達的是
     * 「命中店名比命中地址重要」，不是「你用對詞了沒」。
     *
     * @param  list<list<string>>  $groups  見 expand()
     * @return array{0: string, 1: list<string>}
     */
    public static function relevanceExpression(array $groups): array
    {
        $pieces = [];
        $bindings = [];

        foreach ($groups as $variants) {
            $exact = [];
            $prefix = [];
            $contains = [];

            foreach ($variants as $term) {
                $escaped = self::escapeLike($term);
                $exact[] = $term;
                $prefix[] = $escaped.'%';
                $contains[] = '%'.$escaped.'%';
            }

            $pieces[] = '(CASE WHEN '.self::anyOf('restaurants.name = ?', count($exact))
                .' THEN '.self::SCORE_NAME_EXACT
                .' WHEN '.self::anyOf('restaurants.name LIKE ?', count($prefix))
                .' THEN '.self::SCORE_NAME_PREFIX
                .' WHEN '.self::anyOf('restaurants.name LIKE ?', count($contains))
                .' THEN '.self::SCORE_NAME_CONTAINS
                .' ELSE 0 END)';
            $bindings = [...$bindings, ...$exact, ...$prefix, ...$contains];

            $pieces[] = '(CASE WHEN EXISTS (SELECT 1 FROM menu_items mi'
                .' WHERE mi.restaurant_id = restaurants.id AND '
                .self::anyOf('mi.name LIKE ?', count($contains)).') THEN '
                .self::SCORE_MENU_ITEM.' ELSE 0 END)';
            $bindings = [...$bindings, ...$contains];

            $pieces[] = '(CASE WHEN EXISTS (SELECT 1 FROM restaurant_features rf'
                .' JOIN features f ON f.id = rf.feature_id'
                .' WHERE rf.restaurant_id = restaurants.id AND ('
                .self::anyOf('f.label LIKE ?', count($contains)).' OR '
                .self::anyOf('f.code LIKE ?', count($contains)).')) THEN '
                .self::SCORE_CUISINE.' ELSE 0 END)';
            $bindings = [...$bindings, ...$contains, ...$contains];

            $pieces[] = '(CASE WHEN '.self::anyOf('restaurants.city LIKE ?', count($contains))
                .' OR '.self::anyOf('restaurants.district LIKE ?', count($contains))
                .' OR '.self::anyOf('restaurants.address LIKE ?', count($contains))
                .' THEN '.self::SCORE_LOCALITY.' ELSE 0 END)';
            $bindings = [...$bindings, ...$contains, ...$contains, ...$contains];

            $pieces[] = '(CASE WHEN '.self::anyOf('restaurants.description LIKE ?', count($contains))
                .' THEN '.self::SCORE_DESCRIPTION.' ELSE 0 END)';
            $bindings = [...$bindings, ...$contains];

            $pieces[] = '(CASE WHEN EXISTS (SELECT 1 FROM restaurant_diet_types rdt'
                .' JOIN diet_types dt ON dt.id = rdt.diet_type_id'
                .' WHERE rdt.restaurant_id = restaurants.id AND ('
                .self::anyOf('dt.label LIKE ?', count($contains)).' OR '
                .self::anyOf('dt.code LIKE ?', count($contains)).')) THEN '
                .self::SCORE_DIET.' ELSE 0 END)';
            $bindings = [...$bindings, ...$contains, ...$contains];
        }

        return [implode(' + ', $pieces), $bindings];
    }

    /**
     * 把同一個條件重複 n 次用 OR 串起來：`(a LIKE ? OR a LIKE ?)`。
     *
     * bindings 的順序必須跟這裡產生的 `?` 順序完全一致，所以呼叫端每接一段
     * SQL 就立刻把對應的那組值 append 上去，不要最後才一次補。
     */
    private static function anyOf(string $condition, int $times): string
    {
        return '('.implode(' OR ', array_fill(0, max(1, $times), $condition)).')';
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
