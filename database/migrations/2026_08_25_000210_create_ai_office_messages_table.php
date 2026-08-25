<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 之間的對話（規格第 34 節）：QA → Backend → QA 這種來回。
 * from/to 都可以是 null，代表「來自使用者」或「廣播給整個專案」。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_office_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()
                ->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('from_agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->foreignId('to_agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->text('content');
            $table->timestamps();

            // SSE 增量拉取用：拿某專案 id 大於游標的訊息。
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_messages');
    }
};
