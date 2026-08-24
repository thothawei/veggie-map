<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('address');
            $table->string('city', 100);
            $table->string('district', 100);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->geometry('location', 'point', 4326);
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->unsignedTinyInteger('price_level')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->enum('source', ['manual', 'osm', 'external_api', 'user']);
            $table->string('source_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->boolean('is_possible_duplicate')->default(false);
            $table->timestamps();

            $table->index(['city', 'district'], 'restaurants_city_district_idx');
            $table->index('status', 'restaurants_status_idx');
            $table->index('rating', 'restaurants_rating_idx');
            $table->index('price_level', 'restaurants_price_level_idx');
            $table->unique(['source', 'source_id'], 'restaurants_source_source_id_unique');
            $table->spatialIndex('location', 'restaurants_location_spatial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
