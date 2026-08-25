<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 在 workspace 產出的檔案索引（規格第 11、42 節）。
 * 只存路徑與中繼資料，不存檔案內容——內容在 workspace/{project_id}/ 裡。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('ai_office_projects')->cascadeOnDelete();
            // 相對於該專案 workspace 根目錄的路徑，永遠不存絕對路徑。
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->foreignId('last_modified_by_agent_id')->nullable()
                ->constrained('ai_office_agents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_project_files');
    }
};
