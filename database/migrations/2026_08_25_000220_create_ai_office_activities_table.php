<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事件流（規格第 35 節），也是 SSE（第 36 節）與 Command Center 即時動態的資料來源。
 * type 用字串不用 enum：規格列了二十幾種事件而且還會增加，enum 每加一種都要改 schema。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()
                ->constrained('ai_office_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->string('type');
            $table->string('description');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_activities');
    }
};
