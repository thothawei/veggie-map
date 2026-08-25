<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Runtime\AgentRuntime;
use App\AiOffice\Security\WorkspaceEscapeException;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Tools\FileTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class FileToolTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    public function test_write_then_read_round_trips_inside_the_workspace(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $guard = app(WorkspaceGuard::class);

        (new FileTool('write_file', $guard))->execute([
            'path' => 'src/hello.txt',
            'content' => '你好',
        ], $ctx);

        $read = (new FileTool('read_file', $guard))->execute(['path' => 'src/hello.txt'], $ctx);

        $this->assertSame('src/hello.txt', $read['path']);
        $this->assertSame('你好', $read['content']);
        $this->assertDatabaseHas('ai_office_project_files', [
            'project_id' => $project->id,
            'path' => 'src/hello.txt',
            'last_modified_by_agent_id' => $ctx->agent->id,
        ]);
    }

    public function test_list_and_search_stay_inside_the_project(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $guard = app(WorkspaceGuard::class);

        (new FileTool('write_file', $guard))->execute([
            'path' => 'docs/readme.md',
            'content' => '素食地圖 API',
        ], $ctx);

        $list = (new FileTool('list_files', $guard))->execute(['path' => 'docs'], $ctx);
        $this->assertSame('docs/readme.md', $list['entries'][0]['path']);

        $search = (new FileTool('search_files', $guard))->execute(['query' => '素食地圖'], $ctx);
        $this->assertSame('docs/readme.md', $search['matches'][0]['path']);
        $this->assertSame('content', $search['matches'][0]['match']);
    }

    public function test_write_cannot_escape_via_dotdot(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        $this->expectException(WorkspaceEscapeException::class);
        (new FileTool('write_file', app(WorkspaceGuard::class)))->execute([
            'path' => '../../pwned.txt',
            'content' => 'nope',
        ], $ctx);
    }

    public function test_risk_level_comes_from_config(): void
    {
        $tool = new FileTool('write_file', app(WorkspaceGuard::class));
        $this->assertSame('medium', $tool->riskLevel());

        config(['ai_office.tools.file.actions.write_file.risk' => 'high']);
        $this->assertSame('high', $tool->riskLevel());
    }

    public function test_runtime_can_write_through_the_registered_file_tool(): void
    {
        $project = $this->prepareWorkspace();
        $agent = Agent::factory()->role('backend')->create();
        $agent->tools()->create(['tool' => 'file']);
        $agent->permissions()->create(['ability' => 'write_file', 'effect' => 'allow']);

        $task = $project->tasks()->create([
            'title' => '寫一個檔案',
            'assigned_agent_id' => $agent->id,
            'status' => 'assigned',
        ]);

        $llm = new MockProvider;
        $llm->pushToolCall('write_file', ['path' => 'out.txt', 'content' => 'from-runtime']);
        $llm->pushText('寫好了');
        $this->app->instance(LlmProviderInterface::class, $llm);

        $run = $this->app->make(AgentRuntime::class)->run($task);

        $this->assertSame('completed', $run->status);
        $this->assertFileExists(app(WorkspaceGuard::class)->rootFor($project).'/out.txt');
        $this->assertSame('from-runtime', file_get_contents(app(WorkspaceGuard::class)->rootFor($project).'/out.txt'));
    }
}
