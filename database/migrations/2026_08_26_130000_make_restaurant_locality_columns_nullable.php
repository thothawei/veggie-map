<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `address`／`city`／`district` 改成 nullable，既有的空字串轉成 NULL。
 *
 * OSM 有大量餐廳沒有這三個標籤（開發庫 1159 家裡超過一半），匯入時填空字串等於
 * 宣稱「這家店的地址是空的」——那是一個值，不是「不知道」。兩者在查詢上也不同：
 * `WHERE city = ''` 找得到空字串、找不到 NULL，聚合函式也只跳過 NULL。
 *
 * 讀取端本來就多半用 `?:`／`COALESCE(col, '')` 兩邊都擋，所以這次改的是語意，
 * 不是修一個壞掉的畫面。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
            $table->string('city', 100)->nullable()->change();
            $table->string('district', 100)->nullable()->change();
        });

        // 先改欄位再轉值：欄位還是 NOT NULL 的時候寫不進 NULL。
        DB::table('restaurants')->where('address', '')->update(['address' => null]);
        DB::table('restaurants')->where('city', '')->update(['city' => null]);
        DB::table('restaurants')->where('district', '')->update(['district' => null]);
    }

    public function down(): void
    {
        DB::table('restaurants')->whereNull('address')->update(['address' => '']);
        DB::table('restaurants')->whereNull('city')->update(['city' => '']);
        DB::table('restaurants')->whereNull('district')->update(['district' => '']);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
            $table->string('city', 100)->nullable(false)->change();
            $table->string('district', 100)->nullable(false)->change();
        });
    }
};
