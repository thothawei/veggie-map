<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 指派歷程（規格第 11 節）。tasks.assigned_agent_id 只記「現在是誰」，
 * 這張表記「換過幾次手、為什麼換」——QA 退件重派時要看得出來。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('ai_office_tasks')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('ai_office_agents')->cascadeOnDelete();
            // 由人指派時記 user，由 AgentSelector 自動指派時留 null。
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_task_assignments');
    }
};
