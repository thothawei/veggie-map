<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每次 Agent 執行一筆（規格第 14 節）：Attempt #1 FAILED／#2 FAILED／#3 COMPLETED
 * 要能完整重現，所以失敗的執行不覆蓋、不刪除。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->unsignedInteger('run_number');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->enum('status', ['running', 'completed', 'failed', 'cancelled'])->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('token_input')->default(0);
            $table->unsignedInteger('token_output')->default(0);
            // 金額用 decimal 不用 float：累加報表不能有浮點誤差。
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            // 同一個任務的第 N 次執行只能有一筆，重試競態不該生出兩個 #2。
            $table->unique(['task_id', 'run_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_task_runs');
    }
};
