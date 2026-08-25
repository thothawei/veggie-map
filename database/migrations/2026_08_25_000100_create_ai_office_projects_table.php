<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Office 的 Project（規格第 8 節）。
 *
 * 表名一律加 ai_office_ 前綴：這個 repo 同時住著餐廳領域，`projects`／`tasks`／
 * `messages`／`activities` 這種通用字遲早會撞名，加前綴比事後改表便宜。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_office_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('repository_url')->nullable();
            // Agent 的檔案沙盒目錄，相對於 config('ai_office.workspace_root')。
            $table->string('workspace_path')->nullable();
            $table->enum('status', ['planning', 'active', 'paused', 'completed', 'failed', 'archived'])
                ->default('planning');
            // 建立者被刪除時保留專案，只是不知道是誰開的——專案本身跟任務歷史比帳號重要。
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_office_projects');
    }
};
