<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Security\SandboxPolicy;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Tools\DockerTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AiOffice\FakeDockerEngine;
use Tests\Support\AiOffice\PreparesProjectWorkspace;
use Tests\TestCase;

class DockerToolTest extends TestCase
{
    use PreparesProjectWorkspace;
    use RefreshDatabase;

    public function test_sandbox_enabled_does_not_call_the_engine(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $engine = new FakeDockerEngine;
        config(['ai_office.sandbox.enabled' => true]);

        $tool = $this->tool('docker_run', $engine);

        try {
            $tool->execute([
                'image' => 'ai-office-project-'.$project->id,
            ], $ctx);
            $this->fail('Expected sandbox refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('沙箱尚未就緒', $e->getMessage());
        }

        $this->assertSame(0, $engine->callCount());
    }

    public function test_names_outside_the_project_pattern_are_rejected(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $engine = new FakeDockerEngine;
        config(['ai_office.sandbox.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('專案邊界');
        $this->tool('docker_run', $engine)->execute(['image' => 'nginx'], $ctx);
    }

    public function test_host_escape_flags_are_rejected(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $engine = new FakeDockerEngine;
        config(['ai_office.sandbox.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('禁止的片段');
        $this->tool('docker_run', $engine)->execute([
            'image' => 'ai-office-project-'.$project->id,
            'command' => '--privileged',
        ], $ctx);
    }

    public function test_a_managed_name_reaches_the_engine_when_sandbox_is_off(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $engine = new FakeDockerEngine;
        config(['ai_office.sandbox.enabled' => false]);

        $image = 'ai-office-project-'.$project->id.'-web';
        $result = $this->tool('docker_run', $engine)->execute(['image' => $image], $ctx);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $engine->callCount());
        $this->assertSame('docker_run', $engine->calls[0]['action']);
    }

    public function test_name_pattern_comes_from_config(): void
    {
        $project = $this->prepareWorkspace();
        $ctx = $this->toolContext($project);
        $engine = new FakeDockerEngine;
        config([
            'ai_office.sandbox.enabled' => false,
            'ai_office.tools.docker.name_pattern' => '/^custom-{id}$/',
        ]);

        $this->expectException(RuntimeException::class);
        $this->tool('docker_run', $engine)->execute([
            'image' => 'ai-office-project-'.$project->id,
        ], $ctx);
    }

    private function tool(string $action, FakeDockerEngine $engine): DockerTool
    {
        return new DockerTool(
            $action,
            app(WorkspaceGuard::class),
            app(SandboxPolicy::class),
            $engine,
        );
    }
}
