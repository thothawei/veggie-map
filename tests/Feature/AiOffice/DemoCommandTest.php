<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `php artisan ai-office:demo` 是規格第 79 節 Demo 的入口。指令層要顧的是
 * 「找不到核准人時講清楚」「--fresh 真的重來」這類事，流程本身由 DemoTest 顧。
 */
class DemoCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceRoot = sys_get_temp_dir().'/ai-office-demo-cmd-'.uniqid('', true);
        mkdir($this->workspaceRoot, 0755, true);
        config(['ai_office.workspace_root' => $this->workspaceRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }

        parent::tearDown();
    }

    public function test_the_command_runs_the_whole_demo_and_reports_it(): void
    {
        User::factory()->create(['role' => 'admin', 'name' => '核准的人']);

        $this->artisan('ai-office:demo')
            ->expectsOutputToContain('不會送出任何真的 API 請求')
            ->expectsOutputToContain('Todo API Demo')
            ->assertSuccessful();

        $project = Project::where('name', 'Todo API Demo')->firstOrFail();

        $this->assertSame('completed', $project->status);
        $this->assertSame(4, $project->tasks()->where('status', 'completed')->count());
    }

    public function test_it_fails_loudly_when_there_is_nobody_who_could_approve(): void
    {
        // 沒有 admin 就沒有人能按核准。與其跑到一半卡住，不如一開始就講清楚。
        $this->artisan('ai-office:demo')
            ->expectsOutputToContain('找不到任何 admin 使用者')
            ->assertFailed();

        $this->assertSame(0, Project::count());
    }

    public function test_an_unknown_user_option_is_reported_instead_of_silently_using_someone_else(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->artisan('ai-office:demo', ['--user' => 'nobody@example.com'])
            ->expectsOutputToContain('找不到 email')
            ->assertFailed();
    }

    public function test_fresh_replaces_the_previous_demo_project_instead_of_piling_up(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->artisan('ai-office:demo')->assertSuccessful();
        $first = Project::where('name', 'Todo API Demo')->firstOrFail()->id;

        $this->artisan('ai-office:demo', ['--fresh' => true])->assertSuccessful();

        $projects = Project::where('name', 'Todo API Demo')->get();

        $this->assertCount(1, $projects, '--fresh 應該把舊的那個刪掉，不是再開一個同名的。');
        $this->assertNotSame($first, $projects->first()->id);
    }

    public function test_reject_option_shows_the_task_stopping(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->artisan('ai-office:demo', ['--reject' => true])
            ->expectsOutputToContain('rejected')
            ->assertSuccessful();

        $project = Project::where('name', 'Todo API Demo')->firstOrFail();

        $this->assertSame('rejected', $project->tasks()->where('title', '撰寫上線說明')->firstOrFail()->status);
    }
}
