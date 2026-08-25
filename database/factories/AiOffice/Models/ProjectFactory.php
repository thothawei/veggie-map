<?php

namespace Database\Factories\AiOffice\Models;

use App\AiOffice\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 命名空間刻意是 Database\Factories\AiOffice\Models：Laravel 解析 factory 時，
 * 對於不在 App\Models\ 底下的 Model 會取 App\ 之後的整段命名空間（AiOffice\Models\Project）
 * 接在 Database\Factories\ 後面。放錯層 HasFactory 就找不到。
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->sentence(3),
            'description' => fake()->paragraph(),
            'repository_url' => null,
            'workspace_path' => null,
            'status' => 'planning',
            'created_by' => null,
        ];
    }
}
