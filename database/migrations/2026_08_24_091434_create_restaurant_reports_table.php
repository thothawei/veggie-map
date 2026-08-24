<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'closed',
                'not_vegetarian',
                'wrong_info',
                'menu_changed',
                'wrong_address',
                'wrong_hours',
                'other',
            ]);
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status'], 'restaurant_reports_restaurant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_reports');
    }
};
