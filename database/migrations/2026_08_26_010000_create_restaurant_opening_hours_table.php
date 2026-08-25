<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 營業時間（總 Prompt 第八、二十八節的 `open_now`）。
     *
     * 為什麼是獨立資料表而不是在 restaurants 存一個字串然後用 PHP 判斷：`open_now`
     * 是搜尋條件，跟 diet／feature 一樣要能下在 SQL 裡；把原始字串撈出來在 PHP
     * 逐筆解析，等於總 Prompt 第九節明講禁止的「全部撈出來再算」。所以解析在寫入端
     * 做一次，查詢端只剩 `day = ? AND opens_at <= ? AND closes_at > ?` 這種吃得到
     * 索引的比較。原始字串仍然留在 restaurants.opening_hours，一方面給詳情頁顯示，
     * 一方面解析器之後改進時可以重跑，不必重打 Overpass。
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('opening_hours', 255)->nullable()->after('website');

            // 台北與東京差一小時，「現在是否營業」必須用該店所在地的當地時間判斷。
            // 存在餐廳上（而不是查詢時依城市推算）才能讓 SQL 一次比完所有時區。
            $table->string('timezone', 40)->nullable()->after('opening_hours');
        });

        Schema::create('restaurant_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // 0=週一 … 6=週日。分鐘數而不是 TIME 欄位：跨午夜切段後上限是 1440
            // （＝24:00），TIME 表達得了但比較時要處理 '24:00:00' 這種邊界值，
            // 整數比較單純且索引行為明確。
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedSmallInteger('opens_at');
            $table->unsignedSmallInteger('closes_at');
            $table->timestamps();

            // open_now 查詢的形狀就是這個：先鎖星期，再用時間夾出區間。
            $table->index(['day_of_week', 'opens_at', 'closes_at'], 'roh_day_time_index');
            $table->index(['restaurant_id', 'day_of_week'], 'roh_restaurant_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_opening_hours');

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['opening_hours', 'timezone']);
        });
    }
};
