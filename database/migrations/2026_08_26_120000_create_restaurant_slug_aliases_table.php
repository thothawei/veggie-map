<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 舊 slug 的轉址表。
 *
 * slug 一旦分享出去就是別人手上的網址，改名等於讓那條連結 404。回寫拼音 slug
 * （`restaurants:backfill-slugs`）時把舊值留在這裡，`/restaurants/{slug}` 找不到
 * 正牌 slug 就往這裡找，舊連結仍然打得開。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_slug_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            // unique：一個舊 slug 只能指向一家店，否則轉址是不確定的。
            $table->string('slug')->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_slug_aliases');
    }
};
