<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 錯誤（規格第 32 節）：重試 3 次都失敗時建立一筆並通知 CEO。
 * context 存的是可重現問題的最小資訊，不含任何金鑰（規格第 54、55 節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_agent_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->constrained('ai_office_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->string('type');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_agent_errors');
    }
};
