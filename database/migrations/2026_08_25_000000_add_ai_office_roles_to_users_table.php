<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI Office 需要規格第 52／53 節的四種角色（admin／manager／developer／viewer），
 * 但既有 users.role 只有 user／admin 兩種。
 *
 * 這裡是擴充不是取代：`user` 保留給只用餐廳地圖的一般消費者，`admin` 語意不變
 * （既有 Policy 全部靠 User::isAdmin()，不能動），另外三種只在 AI Office 子系統有意義。
 * 沒有任何既有資料需要轉換——enum 只是多了可選值。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['user', 'admin', 'manager', 'developer', 'viewer'])
                ->default('user')
                ->change();
        });
    }

    public function down(): void
    {
        // 先把新角色降回 user，否則欄位縮回兩個值時 MySQL 會把不合法的值截成空字串。
        DB::table('users')
            ->whereIn('role', ['manager', 'developer', 'viewer'])
            ->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['user', 'admin'])->default('user')->change();
        });
    }
};
