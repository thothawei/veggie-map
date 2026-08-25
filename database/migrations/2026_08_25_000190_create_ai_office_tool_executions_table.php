<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每次工具呼叫一筆（規格第 11、15 節）——包含被拒絕與等待核准的那些。
 * 「Agent 想做什麼但被擋下來」跟「Agent 做了什麼」一樣重要，denied 也要留紀錄。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->nullable()
                ->constrained('ai_office_task_runs')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->string('tool');
            $table->string('action');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical']);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->enum('status', ['pending_approval', 'running', 'succeeded', 'failed', 'denied'])
                ->default('running');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_tool_executions');
    }
};
