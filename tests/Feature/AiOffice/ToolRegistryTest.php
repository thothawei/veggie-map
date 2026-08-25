<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Tools\ToolRegistry;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    public function test_all_phase_five_actions_are_registered(): void
    {
        $registry = app(ToolRegistry::class);

        foreach ([
            'read_file', 'write_file', 'list_files', 'search_files',
            'git_status', 'git_diff', 'git_log', 'git_branch',
            'git_checkout', 'git_add', 'git_commit', 'git_push',
            'execute_command',
            'docker_build', 'docker_run', 'docker_logs', 'docker_stop',
            'database_read',
        ] as $name) {
            $this->assertTrue($registry->has($name), $name.' should be registered');
        }

        $definitions = $registry->definitionsFor(['file', 'git']);
        $names = array_column($definitions, 'name');
        $this->assertContains('read_file', $names);
        $this->assertContains('git_commit', $names);
        $this->assertNotContains('execute_command', $names);
    }
}
