<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 身上掛了哪些工具（規格第 5、11 節）。
 * 「掛著」不等於「可以用」——實際能不能執行由 ai_office_agent_permissions 決定。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_agent_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('ai_office_agents')->cascadeOnDelete();
            $table->string('tool');
            $table->timestamps();

            $table->unique(['agent_id', 'tool']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_agent_tools');
    }
};
