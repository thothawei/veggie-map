<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Security\UnsafeQueryException;
use App\AiOffice\Tools\DatabaseTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class DatabaseToolTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    public function test_select_returns_rows_from_the_app_database(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        $result = app(DatabaseTool::class)->execute([
            'sql' => 'SELECT id, name FROM ai_office_projects WHERE id = '.$project->id,
        ], $ctx);

        $this->assertSame(1, $result['count']);
        $this->assertSame($project->name, $result['rows'][0]['name']);
    }

    public function test_write_statements_are_not_executed(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);

        try {
            app(DatabaseTool::class)->execute([
                'sql' => 'DELETE FROM ai_office_projects WHERE id = '.$project->id,
            ], $ctx);
            $this->fail('Expected UnsafeQueryException');
        } catch (UnsafeQueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertDatabaseHas('ai_office_projects', ['id' => $project->id]);
    }

    public function test_production_environment_is_blocked(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('目前環境禁止');
        app(DatabaseTool::class)->execute(['sql' => 'SELECT 1'], $ctx);
    }

    public function test_allowed_environments_come_from_config(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        config(['ai_office.tools.database.allowed_environments' => ['staging']]);

        $this->expectException(RuntimeException::class);
        app(DatabaseTool::class)->execute(['sql' => 'SELECT 1'], $ctx);
    }
}
