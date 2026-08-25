<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent（規格第 5、12 節）。
 *
 * role 不設唯一鍵：同一個角色可以有多個 Agent（例如兩個 backend 分擔工作量），
 * AgentSelector 會依角色 + 當下工作量挑人（規格第 29 節）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('role', ['ceo', 'frontend', 'backend', 'automation', 'qa', 'design', 'devops']);
            $table->string('avatar')->nullable();
            $table->text('description')->nullable();
            $table->text('system_prompt');
            $table->string('model_provider')->default('mock');
            $table->string('model_name')->nullable();
            // 規格第 7 節。這個欄位只能由 AgentRuntime 寫，前端一律唯讀
            // ——UI 不可以自己生假狀態（規格第 46、74 節）。
            $table->enum('status', ['idle', 'working', 'waiting_review', 'error', 'offline'])
                ->default('idle');
            $table->unsignedTinyInteger('max_concurrency')->default(1);
            $table->timestamps();

            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_agents');
    }
};
