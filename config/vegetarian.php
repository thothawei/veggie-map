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

    /*
    |--------------------------------------------------------------------------
    | 驗證類型的顯示標籤
    |--------------------------------------------------------------------------
    |
    | 餐廳詳情的「可信度依據」列出每一種已成立的驗證。標籤放這裡而不是 Vue：
    | 類型清單是後端定義的（verification_weights 的 key），兩邊各寫一份遲早對不上。
    |
    | 說明用「這一分是怎麼來的」的語氣寫，不是把 code 翻成中文就算——使用者要判斷
    | 的是「我能不能相信這家店是素食」，而不是我們內部怎麼分類。
    |
    */

    'verification_labels' => [
        'restaurant_claim' => '店家自己標示為素食',
        'menu_verified' => '菜單經人工查證',
        'user_report' => '有使用者到過現場並回報',
        'photo_verified' => '有照片佐證',
        'external_source' => '外部資料來源（OpenStreetMap）標示',
        'admin_verified' => '管理員已查證',
    ],

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

    /*
    |--------------------------------------------------------------------------
    | 可信度篩選門檻（前端晶片）
    |--------------------------------------------------------------------------
    |
    | GET /diets 的 meta.confidence_filters 會回這份，FilterDrawer 依它渲染
    | 「素食可信度」晶片，送出時變成 GET /restaurants?confidence_min=N。
    |
    | 放 config 而不是寫死在 Vue：門檻是產品判斷（多少分算「有查證」），會隨著
    | verification_weights 調整而變。兩份數字分開維護遲早會對不上——例如
    | admin_verified 從 30 調成 40 之後，「已查證」的門檻就該跟著動。
    |
    | 目前的取值理由：external_source(10) 是每家 OSM 匯入的店都有的底分，所以
    | 門檻必須高於它才有意義；30 ＝ 至少有一筆 admin_verified 或多種來源佐證，
    | 60 ＝ 需要好幾種驗證同時成立。
    |
    */

    'confidence_filters' => [
        ['value' => 30, 'label' => '有查證'],
        ['value' => 60, 'label' => '高度可信'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 可信度的畫面標籤（三段）
    |--------------------------------------------------------------------------
    |
    | 卡片與詳情顯示這裡的 label，**不顯示裸分數**。0–100 的數字看起來像評分，
    | 而這個產品刻意不做評分制度（2026-08-26 產品決定）——兩者混淆是體驗上的傷害：
    | 使用者會把「素食可信度 5」讀成「這家店只有 5 分，很爛」，但它的實際意思是
    | 「只有 OSM 標示，還沒有人查證過」。
    |
    | 2026-09-06 實測 1148 家有分數的店：589 家 5 分、559 家 10 分，**沒有任何一家
    | 超過 10 分**。也就是說裸分數在現況下幾乎沒有區辨力，卻天天在誤導。
    |
    | `min` 必須跟上面的 confidence_filters 用同一組數字：使用者按「有查證」篩出來
    | 的店，卡片卻寫「待確認」的話就是自打嘴巴。有測試釘住兩者不會漂移
    | （tests/Feature/Api/ConfidenceLevelTest.php）。最低那一段的 min 是 0，
    | 沒有對應的篩選晶片——「待確認」不是一個使用者會想篩的條件。
    |
    | 由高到低排列，取第一個 score >= min 的即是答案。
    |
    */

    'confidence_levels' => [
        ['min' => 60, 'code' => 'high', 'label' => '高度可信'],
        ['min' => 30, 'code' => 'verified', 'label' => '有查證'],
        ['min' => 0, 'code' => 'unverified', 'label' => '素食資訊待確認'],
    ],

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
