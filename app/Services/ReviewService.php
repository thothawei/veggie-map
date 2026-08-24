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
        });

        // dispatchSync 而不是 dispatch()：這個專案目前沒有跑 queue worker（見 docs/progress.md
        // Phase 1 備註），QUEUE_CONNECTION=redis 下若真的用 dispatch() 丟到佇列，沒有 worker
        // 消化的話 rating 就永遠不會更新，變成一個「看起來寫對但實際上什麼都沒發生」的死路徑。
        // Job 本身仍實作 ShouldQueue，之後有 worker 了可以直接改回 dispatch()。
        RecalculateRestaurantRatingJob::dispatchSync($restaurant->id);

        return $review;
    }
}
