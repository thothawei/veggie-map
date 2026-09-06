<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 的量體評估發現：`had_filters` 只是布林值，回答不出「是哪一個篩選」，
 * 所以就算之後有大量真實流量，也決定不了「哪三個是最常用的 quick filter」
 * （B3 現在的三個是規劃暫定的，不是量出來的）。這裡補記錄實際開了哪些
 * 篩選鍵，未來累積到真實流量後才有資料可以回答這個問題。
 *
 * 只記**鍵名**（例如 `["open_now","confidence_min"]`），不記值——跟這張表
 * 一貫的原則一樣，這是產品訊號不是使用者追蹤，鍵名已經足夠回答「哪個
 * 篩選最常跟零結果一起出現」，不需要知道使用者選的是哪個門檻值。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_misses', function (Blueprint $table) {
            $table->json('active_filters')->nullable()->after('had_filters');
        });
    }

    public function down(): void
    {
        Schema::table('search_misses', function (Blueprint $table) {
            $table->dropColumn('active_filters');
        });
    }
};
