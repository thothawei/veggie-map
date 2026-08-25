<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任務相依（規格第 10 節）：task_id 必須等 depends_on_task_id 完成後才能執行。
 *
 * 唯一鍵擋掉重複登記同一條邊；循環相依（A→B→A）沒辦法用資料庫約束表達，
 * 由 TaskGraph 在寫入前做 DFS 偵測（見 App\AiOffice\Orchestration\TaskGraph）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
            // 反向查詢：「某個任務完成後，可以解鎖哪些任務」
            $table->index('depends_on_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_task_dependencies');
    }
};
