<?php

namespace App\Services;

use App\Jobs\RecalculateRestaurantRatingJob;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    /**
     * `reviews` 的「同一使用者對同一餐廳只能有一筆 active review」無法用 DB unique
     * constraint 表達（MySQL 不支援 partial/conditional unique index，見 docs/database.md），
     * 改用交易：把該使用者對這家餐廳現有的 active review（若有）改成 hidden，
     * 再建立新的 active review——等於「重新評論＝覆蓋上一筆」，同時保留歷史紀錄。
     * 併發安全靠 InnoDB REPEATABLE READ 下 UPDATE/INSERT 對
     * `(user_id, restaurant_id, status)` 索引範圍隱含的 next-key lock，不需要額外顯式鎖。
     *
     * 這個鎖會擋住重疊的交易，但擋住不等於優雅序列化——兩個交易各自對同一個空 range
     * 取得 gap lock 後又都想 INSERT 進那個 gap，會被 InnoDB 判定成 deadlock（1213）
     * 直接丟例外，不是誰乖乖排隊等誰。這是寫真正重疊的併發測試才抓到的：
     * tests/Feature/ReviewServiceConcurrencyTest.php。`DB::transaction()` 的第二個參數
     * 是重試次數，預設 1（=不重試），deadlock 會直接炸給呼叫端；帶 3 次讓 Laravel
     * 自動 catch「Deadlock found」訊息重跑整個 closure，使用者端感覺到的只是慢一點，
     * 不會收到 500。
     */
    public function submit(User $user, Restaurant $restaurant, int $rating, ?string $comment): Review
    {
        $review = DB::transaction(function () use ($user, $restaurant, $rating, $comment) {
            Review::where('user_id', $user->id)
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'active')
                ->update(['status' => 'hidden']);

            return Review::create([
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'rating' => $rating,
                'comment' => $comment,
                'status' => 'active',
            ]);
        }, 3);

        // dispatchSync 而不是 dispatch()：這個專案目前沒有跑 queue worker（見 docs/progress.md
        // Phase 1 備註），QUEUE_CONNECTION=redis 下若真的用 dispatch() 丟到佇列，沒有 worker
        // 消化的話 rating 就永遠不會更新，變成一個「看起來寫對但實際上什麼都沒發生」的死路徑。
        // Job 本身仍實作 ShouldQueue，之後有 worker 了可以直接改回 dispatch()。
        RecalculateRestaurantRatingJob::dispatchSync($restaurant->id);

        return $review;
    }
}
