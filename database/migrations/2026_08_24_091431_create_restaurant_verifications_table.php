<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->enum('verification_type', [
                'restaurant_claim',
                'menu_verified',
                'user_report',
                'photo_verified',
                'external_source',
                'admin_verified',
            ]);
            $table->unsignedTinyInteger('score');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'verification_type'], 'restaurant_verifications_restaurant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_verifications');
    }
};
