<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人工核准（規格第 23 節）。CRITICAL 等級的操作沒有 approved 就不准執行。
 * payload 存的是「批准之後要做的那件事」的完整參數，讓核准與執行可以拆成兩個時間點。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()
                ->constrained('ai_office_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->foreignId('tool_execution_id')->nullable()
                ->constrained('ai_office_tool_executions')->cascadeOnDelete();
            $table->string('action');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical']);
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            // 逾時未處理就過期，避免一個月前的部署請求還能被按下去。
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_approvals');
    }
};
