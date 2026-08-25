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

    /*
    |--------------------------------------------------------------------------
    | Admin 可以手動寫入的驗證類型
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/restaurants/{id}/verifications 只收這裡列的類型。
    | 沒列的三種各有自己的來源，不該讓 admin 用手打：`external_source` 由 OSM 同步
    | 依 venue kind 算分（見 VerificationService::syncExternalSource）、
    | `restaurant_claim` 要等店家認領、`photo_verified` 要等照片上傳，
    | 兩者都在 Roadmap，硬開手動入口等於讓可信度分數失去它宣稱的意義。
    |
    */

    'admin_verifiable_types' => [
        ['code' => 'admin_verified', 'label' => 'Admin 已查證'],
        ['code' => 'menu_verified', 'label' => '菜單已查證'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 回報核准後要寫哪一種驗證
    |--------------------------------------------------------------------------
    |
    | key 是 restaurant_reports.type，值是 verification_type 或 null（不寫）。
    | 語意是「有真人到過現場、回報內容經 admin 核准」——資料因此更貼近事實，
    | 所以列在這裡的 type 核准後寫一筆 `user_report`。CalculateRestaurantScoreJob
    | 依類型取最高分，同一家店被回報再多次也只算一次 +10，不會被灌分。
    |
    | 兩個例外：`closed` 是說這家店已經不存在，替一家倒閉的店加素食可信度沒有意義；
    | `other` 的內容不固定，無法自動當成對素食資訊的查證。要改判斷改這張表，
    | 不要在 Controller 或 Service 裡加 switch。
    |
    */

    'report_verifications' => [
        'closed' => null,
        'not_vegetarian' => 'user_report',
        'wrong_info' => 'user_report',
        'menu_changed' => 'user_report',
        'wrong_address' => 'user_report',
        'wrong_hours' => 'user_report',
        'other' => null,
    ],

];
