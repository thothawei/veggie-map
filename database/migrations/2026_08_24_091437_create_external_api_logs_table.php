<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('endpoint');
            $table->smallInteger('status');
            $table->unsignedInteger('response_time_ms');
            $table->boolean('success');
            $table->string('error_code', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at'], 'external_api_logs_provider_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_api_logs');
    }
};
