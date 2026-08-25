<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 工具權限（規格第 21 節）。
 *
 * effect 三種值對應規格的 YES／NO／APPROVAL：
 *   allow    直接執行
 *   deny     直接拒絕
 *   approval 開一筆 approval 並暫停任務，等人按下批准
 *
 * 沒有列在這張表裡的能力一律視為 deny（預設拒絕，不是預設放行）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_agent_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('ai_office_agents')->cascadeOnDelete();
            $table->string('ability');
            $table->enum('effect', ['allow', 'deny', 'approval'])->default('deny');
            $table->timestamps();

            $table->unique(['agent_id', 'ability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_agent_permissions');
    }
};
