<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 記憶（規格第 41 節）。第一版就是關聯式資料表，不上 Vector DB。
 * content 用 text 而不是 json：這裡存的是給 LLM 讀的自然語言片段，
 * 未來要加 embedding 欄位再開新 migration，不影響現有資料。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('ai_office_agents')->cascadeOnDelete();
            // 專案層級的記憶會綁 project；跨專案的通則（例如使用者偏好）留 null。
            $table->foreignId('project_id')->nullable()
                ->constrained('ai_office_projects')->cascadeOnDelete();
            $table->enum('memory_type', [
                'project_context', 'technical_decision', 'user_preference', 'task_result', 'error_pattern',
            ]);
            $table->text('content');
            // 1–10，載入 context 時優先取高分的，避免把整個記憶庫塞進 prompt。
            $table->unsignedTinyInteger('importance')->default(5);
            $table->timestamps();

            $table->index(['agent_id', 'project_id']);
            $table->index('memory_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_agent_memories');
    }
};
