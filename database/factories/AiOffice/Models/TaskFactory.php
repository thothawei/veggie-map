<?php

namespace Database\Factories\AiOffice\Models;

use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_task_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => 'pending',
            'priority' => 50,
            'assigned_agent_id' => null,
            'created_by' => null,
            'retry_count' => 0,
            'max_retries' => 3,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
