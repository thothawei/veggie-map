<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->enum('status', ['active', 'hidden'])->default('active');
            $table->timestamps();

            // MySQL 沒有條件式 unique index，「同一使用者對同一餐廳只能有一筆 active review」
            // 由 Service 層搭配交易保證，見 docs/database.md。
            $table->index(['user_id', 'restaurant_id', 'status'], 'reviews_user_restaurant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
