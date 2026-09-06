<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 零結果查詢紀錄（A8）。
 *
 * 這個 repo 每一輪都是「先量再改」，唯獨同義詞表是憑猜的——A4 的錯字容錯上線後
 * 也沒有數字可以驗證 miss 有沒有下降。這張表補的就是這個缺口：下一輪要加哪些
 * 同義詞、A4 的效果好不好，都能從這裡的資料找答案，不必再靠猜。
 *
 * **這是產品訊號，不是使用者追蹤**：故意不記 IP、不記 user id、不記座標——
 * 這幾樣對「這個詞該不該進同義詞表」這個問題完全沒有幫助，記了只是白白
 * 擴大隱私風險。`keyword` 本身可能含地名，但不綁任何身分。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_misses', function (Blueprint $table) {
            $table->id();

            // 使用者原本打的字。沒有關鍵字（純瀏覽＋篩選也篩成 0 筆）時是 null——
            // 那種情況一樣值得記，但對「該加哪些同義詞」這個問題沒有幫助。
            $table->string('keyword', 255)->nullable();

            // trim＋collapse 空白＋大小寫統一過的版本，用來分組計數：「拉麵」「拉麵 」
            // 「拉麵  」不該算三個不同的詞，不然 top N 排行榜會被空白差異稀釋掉。
            $table->string('normalized', 255)->nullable();

            // 目前固定是 0（這張表只在零結果時才寫入），保留這個欄位是為了將來
            // 如果決定也要記「結果少到像是零」（例如 1～2 筆）時不用再改 schema。
            $table->unsignedInteger('result_count')->default(0);

            // 除了 keyword 之外還有沒有開別的篩選（venue_scope／diet／price_level／
            // confidence_min／open_now／features／bbox）。分得出「單純這個詞查不到」
            // 跟「詞查得到，但篩選太嚴」是兩個完全不同的處置方向。
            $table->boolean('had_filters')->default(false);

            $table->timestamp('created_at')->useCurrent();

            // top N 排行榜依 normalized 分組計數，這個索引正好覆蓋它；
            // 保留天數的清理則靠 created_at。
            $table->index(['normalized', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_misses');
    }
};
