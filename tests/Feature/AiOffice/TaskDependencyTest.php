<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\TaskGraph;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 規格第 10 節：必須防止循環相依。
 *
 * 環的危害不是錯誤而是沉默——成環之後那條鏈上的每個任務都在等前面的完成，
 * 永遠等不到，也不會有任何例外被丟出來，只是安靜地不動。所以測試要涵蓋
 * 自環、直接互指、以及隔了好幾層才繞回來的間接環。
 */
class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'developer']));
    }

    private function task(string $title): Task
    {
        return Task::factory()->create(['project_id' => $this->project->id, 'title' => $title]);
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $task = $this->task('A');

        $this->postJson("/api/v1/ai-office/tasks/{$task->id}/dependencies", [
            'depends_on_task_ids' => [$task->id],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('ai_office_task_dependencies', 0);
    }

    public function test_direct_cycle_is_rejected(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');

        // B 依賴 A，成立。
        $this->postJson("/api/v1/ai-office/tasks/{$b->id}/dependencies", [
            'depends_on_task_ids' => [$a->id],
        ])->assertStatus(201);

        // 再讓 A 依賴 B 就成環。
        $this->postJson("/api/v1/ai-office/tasks/{$a->id}/dependencies", [
            'depends_on_task_ids' => [$b->id],
        ])->assertStatus(422);

        $this->assertDatabaseCount('ai_office_task_dependencies', 1);
    }

    public function test_indirect_cycle_across_several_hops_is_rejected(): void
    {
        // D → C → B → A（箭頭是「依賴」）。再讓 A 依賴 D 就繞回來了。
        $a = $this->task('A');
        $b = $this->task('B');
        $c = $this->task('C');
        $d = $this->task('D');

        foreach ([[$b, $a], [$c, $b], [$d, $c]] as [$task, $dependsOn]) {
            $this->postJson("/api/v1/ai-office/tasks/{$task->id}/dependencies", [
                'depends_on_task_ids' => [$dependsOn->id],
            ])->assertStatus(201);
        }

        $this->postJson("/api/v1/ai-office/tasks/{$a->id}/dependencies", [
            'depends_on_task_ids' => [$d->id],
        ])->assertStatus(422);

        $this->assertDatabaseCount('ai_office_task_dependencies', 3);
    }

    public function test_a_diamond_shape_is_allowed(): void
    {
        // 規格第 10 節第二張圖：B 與 C 都依賴 A，D 依賴 B 與 C。這是合法的 DAG，
        // 不可以被環偵測誤殺。
        $a = $this->task('A');
        $b = $this->task('B');
        $c = $this->task('C');
        $d = $this->task('D');

        foreach ([$b, $c] as $task) {
            $this->postJson("/api/v1/ai-office/tasks/{$task->id}/dependencies", [
                'depends_on_task_ids' => [$a->id],
            ])->assertStatus(201);
        }

        $this->postJson("/api/v1/ai-office/tasks/{$d->id}/dependencies", [
            'depends_on_task_ids' => [$b->id, $c->id],
        ])->assertStatus(201);

        $this->assertDatabaseCount('ai_office_task_dependencies', 4);
    }

    public function test_adding_the_same_dependency_twice_is_idempotent(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');

        foreach (range(1, 2) as $ignored) {
            $this->postJson("/api/v1/ai-office/tasks/{$b->id}/dependencies", [
                'depends_on_task_ids' => [$a->id],
            ])->assertStatus(201);
        }

        // 唯一鍵擋掉重複，而且第二次不該爆成 500。
        $this->assertDatabaseCount('ai_office_task_dependencies', 1);
    }

    public function test_dependency_can_be_removed(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $b->dependencies()->sync([$a->id]);

        $this->deleteJson("/api/v1/ai-office/tasks/{$b->id}/dependencies/{$a->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.dependencies', []);

        $this->assertDatabaseCount('ai_office_task_dependencies', 0);
    }

    public function test_cycle_detection_survives_pre_existing_cycles_in_the_data(): void
    {
        // 反向驗證閉環偵測本身不會無限迴圈：直接繞過 API 在資料裡種一個環
        // （例如舊資料或人工改庫造成的），再問它一個問題。如果 dependencyClosure()
        // 沒有 visited 集合，這個測試會直接跑到記憶體耗盡而不是回傳答案。
        $a = $this->task('A');
        $b = $this->task('B');
        $a->dependencies()->sync([$b->id]);
        $b->dependencies()->sync([$a->id]);

        $c = $this->task('C');

        $graph = app(TaskGraph::class);

        $this->assertTrue($graph->wouldCreateCycle($a->id, [$b->id]));
        $this->assertFalse($graph->wouldCreateCycle($c->id, []));
    }

    public function test_ready_tasks_only_returns_pending_tasks_whose_dependencies_succeeded(): void
    {
        $done = $this->task('done');
        $done->update(['status' => 'completed']);

        $blocker = $this->task('blocker');

        $ready = $this->task('ready');
        $ready->dependencies()->sync([$done->id]);
        $ready->update(['priority' => 90]);

        $blocked = $this->task('blocked');
        $blocked->dependencies()->sync([$blocker->id]);

        $result = app(TaskGraph::class)->readyTasks($this->project->id);

        // blocker 自己沒有前置，所以它也是 ready 的；blocked 不是。
        $this->assertSame(
            ['ready', 'blocker'],
            $result->pluck('title')->all(),
        );
    }
}
