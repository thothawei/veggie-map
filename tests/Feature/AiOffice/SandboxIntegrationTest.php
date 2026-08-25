<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Process\SymfonyProcessRunner;
use App\AiOffice\Security\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 真的把容器跑起來的整合測試。沒有 docker 的環境（例如本專案的 app container 裡
 * 就沒有 docker CLI）直接 skip——skip 是誠實的「沒驗到」，比用假 runner 假裝
 * 驗過好。GitHub Actions 的 ubuntu runner 有 docker，所以 CI 會真的跑到。
 *
 * 這裡驗的是**旗標真的生效**，不是「我們有送出旗標」（那是 SandboxTest 的事）：
 * 沒有網路、rootfs 唯讀、workspace 可寫、逾時會被砍掉。
 */
class SandboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $runner = new SymfonyProcessRunner;

        if (! $runner->run(['docker', 'info', '--format', '{{.ServerVersion}}'], 15)->successful()) {
            $this->markTestSkipped('這個環境沒有可用的 docker，跳過沙箱整合測試。');
        }

        $this->workspace = sys_get_temp_dir().'/ai-office-sandbox-it-'.uniqid('', true);
        mkdir($this->workspace, 0777, true);
        chmod($this->workspace, 0777);

        config([
            'ai_office.sandbox.enabled' => true,
            'ai_office.sandbox.timeout_seconds' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }

        parent::tearDown();
    }

    private function manager(): SandboxManager
    {
        return new SandboxManager(new SymfonyProcessRunner);
    }

    public function test_a_command_really_runs_inside_the_container(): void
    {
        $result = $this->manager()->runCommand('echo hello-from-sandbox', $this->workspace);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertStringContainsString('hello-from-sandbox', $result['stdout']);
    }

    public function test_the_container_has_no_network(): void
    {
        // --network none 之下 /proc/net/route 只剩標頭，沒有任何對外路由。
        $result = $this->manager()->runCommand('cat /proc/net/route', $this->workspace);

        $this->assertSame(0, $result['exit_code'], $result['stderr']);
        $this->assertSame(1, substr_count(trim($result['stdout']), "\n") + 1, '容器內不應該有任何對外路由。');
    }

    public function test_the_root_filesystem_is_read_only_but_the_workspace_is_writable(): void
    {
        $blocked = $this->manager()->runCommand('touch /etc/should-not-work', $this->workspace);
        $this->assertNotSame(0, $blocked['exit_code'], 'rootfs 應該是唯讀的。');

        $allowed = $this->manager()->runCommand('echo written > /workspace/from-sandbox.txt', $this->workspace);
        $this->assertSame(0, $allowed['exit_code'], $allowed['stderr']);

        // 檔案要真的落在 host 的 workspace 目錄裡，掛載才算真的有效。
        $this->assertFileExists($this->workspace.'/from-sandbox.txt');
    }

    public function test_a_command_that_runs_too_long_is_killed_and_the_container_removed(): void
    {
        config(['ai_office.sandbox.timeout_seconds' => 2]);

        $result = $this->manager()->runCommand('sleep 60', $this->workspace);

        $this->assertTrue($result['timed_out']);

        // 逾時之後不該留下任何殘骸容器。
        $ps = (new SymfonyProcessRunner)->run(
            ['docker', 'ps', '--all', '--filter', 'name=ai-office-sandbox-', '--format', '{{.Names}}'],
            15,
        );
        $this->assertSame('', trim($ps->stdout));
    }
}
