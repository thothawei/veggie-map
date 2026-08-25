<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task（規格第 9、13 節）。
 *
 * priority 用 0–100 的數字而不是 enum('low','normal','high')：排程要靠它排序，
 * enum 在 MySQL 得用 FIELD() 才排得對，數字直接 ORDER BY 就好。數字越大越優先。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_office_projects')->cascadeOnDelete();
            // 子任務：父任務刪掉時整串一起走，不留孤兒。
            $table->foreignId('parent_task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', [
                'pending', 'planning', 'assigned', 'running', 'waiting_review',
                'approved', 'rejected', 'completed', 'failed', 'cancelled',
            ])->default('pending');
            $table->unsignedTinyInteger('priority')->default(50);
            // Agent 被刪除時任務留著（歷史不能消失），只是變成沒人負責。
            $table->foreignId('assigned_agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // 派工時最常問的是「這個專案還有哪些任務卡在某個狀態」。
            $table->index(['project_id', 'status']);
            $table->index('assigned_agent_id');
            $table->index('parent_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_tasks');
    }
};
