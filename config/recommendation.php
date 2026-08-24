<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recommendation Score Weights
    |--------------------------------------------------------------------------
    |
    | 見 docs/architecture.md「AI 預留」與總體規劃第三十節：第一版用 Rule Based
    | （RuleBasedRecommendationService），權重集中在這裡，不寫死在程式碼裡，方便未來調整
    | 或替換成 AIRecommendationService 時比對。六個分量各自介於 0~1，加權總和封頂 1（換算成
    | 百分比時再乘 100，見 RuleBasedRecommendationService::score()）。
    |
    */

    'weights' => [
        'distance' => 0.25,
        'rating' => 0.20,
        'vegetarian_confidence' => 0.25,
        'feature_match' => 0.15,
        'popularity' => 0.10,
        'freshness' => 0.05,
    ],

    // feature_match：diet_types 數 + features 數的加總，除以這個上限後封頂 1.0。
    // 資料越豐富（掛的 diet/feature 標籤越多）分數越高，是「素食資訊完整度」的簡單代理指標。
    'max_features_expected' => 4,

    // freshness：距今建立天數超過這個視窗直接歸零，視窗內線性遞減。
    'freshness_window_days' => 90,

    // 候選集大小：從半徑搜尋結果裡先撈這麼多筆再算分排序，不是撈全部再排序。
    'candidate_pool_size' => 30,

];
