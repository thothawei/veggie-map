<?php

namespace Database\Factories\AiOffice\Models;

use App\AiOffice\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'role' => fake()->randomElement(Agent::ROLES),
            'avatar' => null,
            'description' => fake()->sentence(),
            'system_prompt' => 'You are a helpful agent.',
            'model_provider' => 'mock',
            'model_name' => 'mock-1',
            'status' => 'idle',
            'max_concurrency' => 1,
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
