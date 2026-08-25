<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_selects_the_idle_agent_of_the_requested_role(): void
    {
        Agent::factory()->role('frontend')->create(['status' => 'idle', 'name' => '前端小王']);
        $backend = Agent::factory()->role('backend')->create(['status' => 'idle', 'name' => '後端阿明']);
        Agent::factory()->role('backend')->create(['status' => 'offline', 'name' => '離線後端']);

        $selected = app(AgentSelector::class)->select('backend');

        $this->assertNotNull($selected);
        $this->assertSame($backend->id, $selected->id);
    }

    public function test_prefers_the_agent_with_the_lowest_workload(): void
    {
        $busy = Agent::factory()->role('backend')->create(['status' => 'working']);
        $free = Agent::factory()->role('backend')->create(['status' => 'idle']);

        Task::factory()->create(['assigned_agent_id' => $busy->id, 'status' => 'running']);
        Task::factory()->create(['assigned_agent_id' => $busy->id, 'status' => 'assigned']);

        $selected = app(AgentSelector::class)->select('backend');

        $this->assertSame($free->id, $selected?->id);
    }

    public function test_does_not_guess_role_from_the_task_title(): void
    {
        Agent::factory()->role('frontend')->create(['status' => 'idle']);

        // 標題再怎麼像後端工作，沒有 backend Agent 就不能硬派給前端。
        $this->assertNull(app(AgentSelector::class)->select('backend'));
    }

    public function test_unknown_role_returns_null_instead_of_picking_anyone(): void
    {
        Agent::factory()->role('backend')->create();

        $this->assertNull(app(AgentSelector::class)->select('wizard'));
    }
}
