<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verification Score Weights
    |--------------------------------------------------------------------------
    |
    | restaurant_verifications.score 寫入時的權重來源（見 docs/database.md）。
    | 每種驗證類型貢獻的分數不寫死在程式碼裡，方便未來調整而不用改 migration／程式邏輯。
    | CalculateRestaurantScoreJob 把一家餐廳「每種驗證類型各取最高分」再加總，
    | 同一類型多筆（例如每日 sync 重複寫入的 external_source）只算一次，
    | 上限封頂在 100（對應 restaurant_confidence_scores.score 的定義）。
    |
    */

    'verification_weights' => [
        'restaurant_claim' => 15,
        'menu_verified' => 20,
        'user_report' => 10,
        'photo_verified' => 15,
        'external_source' => 10,
        'admin_verified' => 30,
    ],

];
