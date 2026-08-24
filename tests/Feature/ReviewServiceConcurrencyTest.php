<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ReviewService::submit() 宣稱靠 InnoDB REPEATABLE READ 下 UPDATE 對
 * (user_id, restaurant_id, status) 索引範圍的隱含 next-key lock做併發安全（見程式碼註解）。
 * 既有的 ReviewTest 只驗證「循序覆蓋」（一次送完再送下一次），從沒真的讓兩個交易重疊過，
 * 沒辦法證明這個鎖真的有效。這裡故意讓兩個交易真的重疊：背景 process 先 UPDATE 再
 * sleep 撐住鎖，主測試緊接著呼叫 submit()，驗證它是真的被鎖卡住等待，而不是自由競速。
 *
 * 不能用 RefreshDatabase：它把整個測試包在一個交易裡，背景 process 用的是另一條
 * PDO 連線，看不到未 commit 的資料，見 tests/Support/hold_review_lock.php 開頭的說明。
 * 所以這裡手動建資料、手動在 tearDown 清乾淨。
 */
class ReviewServiceConcurrencyTest extends TestCase
{
    private User $user;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->restaurant = Restaurant::factory()->create();
    }

    protected function tearDown(): void
    {
        Review::where('user_id', $this->user->id)->delete();
        $this->restaurant->delete();
        $this->user->delete();

        parent::tearDown();
    }

    public function test_concurrent_submissions_for_the_same_user_and_restaurant_never_leave_two_active_reviews(): void
    {
        // 背景 process：UPDATE（此時沒有任何 active review 可改，但仍會在這個
        // index range 上取得 gap lock）→ sleep 1.2s 撐住交易 → INSERT → commit。
        $background = Process::timeout(10)->start([
            'php',
            base_path('tests/Support/hold_review_lock.php'),
            (string) $this->user->id,
            (string) $this->restaurant->id,
            '5',
            '1.2',
        ]);

        // 給背景 process 一點時間真的執行完它的 UPDATE、拿到鎖，
        // 確保接下來 submit() 呼叫時兩個交易真的有重疊，不是背景 process 還沒啟動。
        usleep(300_000);

        $startedAt = microtime(true);
        $foregroundReview = app(ReviewService::class)->submit($this->user, $this->restaurant, 1, 'foreground process');
        $elapsedSeconds = microtime(true) - $startedAt;

        $result = $background->wait();
        if (! $result->successful()) {
            $this->fail('背景 process 失敗：'.$result->errorOutput().$result->output());
        }

        // 如果 submit() 的 UPDATE 真的被背景交易的鎖卡住，它至少要等到背景 process
        // sleep 完（1.2s 扣掉我們自己 sleep 掉的 0.3s）才能繼續往下跑；
        // 如果完全沒鎖、兩邊自由競速，這裡幾乎會是 0 秒內就跑完。
        $this->assertGreaterThan(
            0.7,
            $elapsedSeconds,
            'submit() 幾乎立刻回傳，代表沒有真的被背景交易的鎖卡住——兩個交易根本沒重疊，不是有效的併發測試。'
        );

        $reviews = Review::where('user_id', $this->user->id)
            ->where('restaurant_id', $this->restaurant->id)
            ->get();

        $this->assertCount(2, $reviews, '背景與前景各自 INSERT 一筆，應該總共 2 筆歷史紀錄。');
        $this->assertCount(
            1,
            $reviews->where('status', 'active'),
            '併發下仍然只能有一筆 active review——這是這個測試要保護的核心不變量。'
        );

        // 兩邊都可能因為 deadlock 重試，最後誰的 commit 排在最後誰就是 active——
        // 不保證一定是前景贏，這裡只驗證 rating 集合完整（1 和 5 都真的進了資料庫），
        // 不去猜測時序。
        $this->assertSame([1, 5], $reviews->pluck('rating')->sort()->values()->all());
        $this->assertNotNull($foregroundReview->fresh(), '前景那筆 review 本身必須存在於資料庫（不管最後是不是 active）。');
    }
}
