<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「疑似歇業」的訊號。
 *
 * 為什麼是一張表而不是 restaurants 上的一個布林欄位（像 is_possible_duplicate）：
 * Admin 要判斷的不是「有沒有被標記」，而是「憑什麼說它歇業了」。一家店可能同時
 * 有好幾個訊號（OSM 節點不見了 ＋ 官網掛掉），累積起來才夠說服人按下下架。
 * 布林欄位存不下這個。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_closure_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // 訊號種類（App\Models\RestaurantClosureSignal::SIGNALS）。
            $table->string('signal', 40);

            // 這個訊號當下看到什麼——節點 id、HTTP 狀態碼之類的，給 Admin 判斷用。
            $table->json('metadata')->nullable();

            $table->timestamp('detected_at');

            /*
             * 審核結果。null = 還沒人看過（那正是待審清單的條件）。
             * confirmed = 確認歇業並下架；dismissed = 誤報，店還在。
             */
            $table->string('resolution', 20)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /*
             * 待審清單的查詢就是 `where resolution is null order by detected_at`，
             * 這個複合索引正好覆蓋它。
             */
            $table->index(['resolution', 'detected_at']);

            /*
             * 同一家店的同一種訊號只留一筆：排程每天跑，沒有這個限制的話
             * 一家店三個月後會累積九十筆一模一樣的「節點不見了」，
             * Admin 的待審清單會被同一家店洗版。重複偵測時更新 detected_at 即可。
             */
            $table->unique(['restaurant_id', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_closure_signals');
    }
};
