<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每一次 LLM 請求都記一筆（規格第 40 節）。Dashboard 的今日／本週／本月／
 * 依 Agent／依專案／依模型統計全部從這張表算出來，不可以寫死（規格第 74 節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_token_usages', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->constrained('ai_office_projects')->nullOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->nullOnDelete();
            $table->foreignId('task_run_id')->nullable()
                ->constrained('ai_office_task_runs')->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->timestamps();

            // 時間區間統計是最常見的查詢，單獨建索引。
            $table->index('created_at');
            $table->index(['agent_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_token_usages');
    }
};
