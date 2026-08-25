<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Agent;
use App\Models\User;
use Database\Seeders\AiOfficeAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_list_is_readable_by_any_ai_office_role_and_hides_the_prompt(): void
    {
        Agent::factory()->role('backend')->create(['name' => '後端阿明']);
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson('/api/v1/ai-office/agents')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '後端阿明')
            ->assertJsonPath('data.0.status', 'idle')
            // 列表不帶 system prompt：動輒上千字，列表用不到。
            ->assertJsonMissingPath('data.0.system_prompt');
    }

    public function test_agent_list_filters_by_role(): void
    {
        Agent::factory()->role('backend')->create();
        Agent::factory()->role('qa')->create();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/ai-office/agents?role=qa')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', 'qa');
    }

    public function test_seeder_creates_the_initial_roster_with_its_permissions(): void
    {
        $this->seed(AiOfficeAgentSeeder::class);

        // 規格第 6 節六個角色 + 第 67 節的設計小花。
        $this->assertSame(7, Agent::count());

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $devops = Agent::where('role', 'devops')->firstOrFail();

        $response = $this->getJson("/api/v1/ai-office/agents/{$devops->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', '維運小陳');

        $permissions = $response->json('data.permissions');

        // 規格第 21 節：DevOps 可以 push，但部署要人工核准。
        $this->assertSame('allow', $permissions['git_push']);
        $this->assertSame('approval', $permissions['deploy_production']);

        $backend = Agent::where('role', 'backend')->firstOrFail();
        $backendPermissions = $this->getJson("/api/v1/ai-office/agents/{$backend->id}")
            ->json('data.permissions');

        // 後端可以讀資料庫但不可以 push（規格第 21、62 節）。
        $this->assertSame('allow', $backendPermissions['database_read']);
        $this->assertSame('deny', $backendPermissions['git_push']);
    }

    public function test_seeder_is_idempotent_and_prunes_removed_permissions(): void
    {
        $this->seed(AiOfficeAgentSeeder::class);
        $this->seed(AiOfficeAgentSeeder::class);

        $this->assertSame(7, Agent::count());

        // 權限是全量覆蓋：手動塞一個不在清單裡的能力，重跑之後必須消失，
        // 不可以留著一個沒人記得的 allow。
        $ceo = Agent::where('role', 'ceo')->firstOrFail();
        $ceo->permissions()->create(['ability' => 'deploy_production', 'effect' => 'allow']);

        $this->seed(AiOfficeAgentSeeder::class);

        $this->assertDatabaseMissing('ai_office_agent_permissions', [
            'agent_id' => $ceo->id,
            'ability' => 'deploy_production',
        ]);
    }
}
