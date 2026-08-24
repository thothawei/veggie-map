<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_confidence_scores', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->timestamp('calculated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_confidence_scores');
    }
};
