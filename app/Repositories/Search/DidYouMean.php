<?php

namespace App\Repositories\Search;

/**
 * 「你是不是要找…」——真的打錯字時的建議。
 *
 * 只在搜尋結果為 0 筆時才跑（見 RestaurantRepository::didYouMean），而且**不自動
 * 改寫查詢**：自動改寫會讓使用者不知道自己看的是別的查詢的結果，改錯的時候也沒有
 * 回頭路。Google 那樣做是因為它有信心分數，我們沒有。所以只給建議，讓使用者自己點。
 *
 * ## 為什麼不用內建的 levenshtein()
 *
 * `levenshtein()` 是 **byte-based** 的，而 UTF-8 的中日文一個字佔 3 bytes，
 * 所以它給出的距離跟「差幾個字」完全無關。2026-09-06 實測：
 *
 *   素時 vs 素食  → levenshtein() 說 3（其實只差一個字）
 *   拉麵 vs 拉面  → levenshtein() 說 2（也只差一個字）
 *   素時 vs 時蔬  → levenshtein() 說 6（兩個字都不同）
 *
 * 同樣「差一個字」卻拿到 3 跟 2 兩種距離，無法設一個有意義的門檻。
 *
 * ## 為什麼不是規劃原本寫的「字元 bigram 的 Jaccard 相似度」
 *
 * 規劃（plan-2026-09-search-ux.md A4）指出 levenshtein() 的問題是對的，但開的藥方
 * 對**短詞無效**：兩個字的詞只有一個 bigram，一字之差交集就是空集合。2026-09-06
 * 對照實測（同一組候選、兩種算法）：
 *
 *   查詢    候選                bigram Jaccard   字元層級 Levenshtein
 *   素時    素食                    0.00              0.50
 *   拉麵    拉面                    0.00              0.50
 *   ラーメソ ラーメン                0.50              0.75
 *   素時    時蔬異理義大利麵        0.00              0.00
 *   拉麵    咖哩                    0.00              0.00
 *
 * 中文使用者最常打錯的正是兩三個字的詞，bigram 在那個長度上一律回 0——照規劃字面
 * 實作的話，連規劃自己寫的驗收條件（「素時」要建議「素食」）都達不到。
 *
 * 所以這裡用**字元層級**的編輯距離：先把字串切成字元陣列再算，順序資訊因此保留，
 * 而且對 ASCII 的行為跟內建 levenshtein() 完全一致（ASCII 一字元就是一 byte），
 * 不需要中英文兩條路徑。
 */
final class DidYouMean
{
    /**
     * 從候選清單裡挑出「使用者可能是要找這個」的詞。
     *
     * @param  list<string>  $candidates  店名／料理種類 label／行政區名（見 Repository）
     * @return list<array{term: string, score: float}> 分數高的在前
     */
    public static function suggest(string $keyword, array $candidates): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $threshold = (float) config('veggiemap.search.did_you_mean.min_score', 0.5);
        $limit = (int) config('veggiemap.search.did_you_mean.limit', 3);
        $maxLengthRatio = (float) config('veggiemap.search.did_you_mean.max_length_ratio', 2.0);

        $keywordChars = self::chars(mb_strtolower($keyword));
        $keywordLength = count($keywordChars);
        $scored = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '' || mb_strtolower($candidate) === mb_strtolower($keyword)) {
                continue;
            }

            $candidateChars = self::chars(mb_strtolower($candidate));

            // 長度差太多的直接跳過：一個兩字的錯字不可能是想打「時蔬異理義大利麵」，
            // 而且這一刀砍掉大部分候選，省下真正的距離計算（那才是貴的部分）。
            if (count($candidateChars) > $keywordLength * $maxLengthRatio) {
                continue;
            }

            $score = self::similarity($keywordChars, $candidateChars);

            if ($score >= $threshold) {
                $scored[] = ['term' => $candidate, 'score' => round($score, 2)];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        // 同一個詞可能同時是店名與行政區，只留一次。
        $seen = [];
        $unique = [];

        foreach ($scored as $row) {
            $key = mb_strtolower($row['term']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $row;

            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    /**
     * 0（毫不相干）到 1（完全一樣）。用字元數正規化編輯距離，這樣門檻才跟詞長無關：
     * 五個字裡錯一個跟兩個字裡錯一個，前者顯然比較可能是筆誤。
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function similarity(array $a, array $b): float
    {
        $longest = max(count($a), count($b));

        if ($longest === 0) {
            return 0.0;
        }

        return 1 - self::distance($a, $b) / $longest;
    }

    /**
     * 字元層級的 Levenshtein 距離。
     *
     * 只保留兩列（前一列與當前列）而不是完整的 m×n 矩陣：候選有上千個，
     * 每個都配一個矩陣是不必要的記憶體壓力。
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function distance(array $a, array $b): int
    {
        $m = count($a);
        $n = count($b);

        if ($m === 0) {
            return $n;
        }

        if ($n === 0) {
            return $m;
        }

        $row = range(0, $n);

        for ($i = 1; $i <= $m; $i++) {
            $previousDiagonal = $row[0];
            $row[0] = $i;

            for ($j = 1; $j <= $n; $j++) {
                $previousRow = $row[$j];

                $row[$j] = min(
                    $row[$j] + 1,                                                   // 刪除
                    $row[$j - 1] + 1,                                               // 插入
                    $previousDiagonal + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),        // 取代
                );

                $previousDiagonal = $previousRow;
            }
        }

        return $row[$n];
    }

    /**
     * 切成字元陣列。`str_split()` 會把一個中文字切成三段亂碼，必須用 `//u`。
     *
     * @return list<string>
     */
    private static function chars(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
