<?php

namespace App\AiOffice\Orchestration;

use App\AiOffice\Jobs\ExecuteTaskJob;
use App\AiOffice\Jobs\RetryFailedTaskJob;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Services\ActivityRecorder;
use App\AiOffice\Services\AgentMessenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 規格第 27 節：專案從規劃到派工、失敗重試、收尾的編排入口。
 *
 * 這個類別不跑 LLM 迴圈（那是 AgentRuntime），也不在 HTTP request 裡被同步呼叫
 * 去做規劃——Controller 只 dispatch PlanProjectJob。
 */
class AgentOrchestrator
{
    public function __construct(
        private readonly CeoPlanner $planner,
        private readonly AgentSelector $selector,
        private readonly TaskGraph $graph,
        private readonly ActivityRecorder $activities,
        private readonly AgentMessenger $messenger,
    ) {}

    public function planProject(Project $project): void
    {
        $project->refresh();

        if ($project->status !== 'planning') {
            return;
        }

        if ($project->tasks()->exists()) {
            $this->dispatchReadyTasks($project);

            return;
        }

        try {
            $plan = $this->planner->plan($project);
            $this->createTasks($project, $plan);
            $project->update(['status' => 'active']);
        } catch (Throwable $e) {
            $project->update(['status' => 'failed']);

            $this->activities->record(
                'ProjectPlanningFailed',
                '規劃失敗：'.$e->getMessage(),
                payload: ['error' => class_basename($e)],
                project: $project,
            );

            Log::warning('ai_office.planning_failed', [
                'project_id' => $project->id,
                'error' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // 派工不包在上面的 catch 裡：任務執行失敗是 handleFailure 的事，
        // 不能回過頭把已經規劃好的專案標成「規劃失敗」。
        $this->dispatchReadyTasks($project->fresh());
    }

    /**
     * @param  array{project: array{name: ?string, description: ?string}, tasks: list<array{title: string, agent: string, description: ?string, priority: int, dependencies: list<string>}>}  $plan
     */
    public function createTasks(Project $project, array $plan): void
    {
        if ($plan['project']['description'] && blank($project->description)) {
            $project->update(['description' => $plan['project']['description']]);
        }

        $maxRetries = (int) config('ai_office.limits.max_retries');

        DB::transaction(function () use ($project, $plan, $maxRetries) {
            /** @var array<string, Task> $byTitle */
            $byTitle = [];

            foreach ($plan['tasks'] as $item) {
                $byTitle[$item['title']] = $project->tasks()->create([
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'priority' => $item['priority'],
                    'status' => 'pending',
                    'max_retries' => $maxRetries,
                ]);
            }

            foreach ($plan['tasks'] as $item) {
                $task = $byTitle[$item['title']];
                $depIds = [];

                foreach ($item['dependencies'] as $depTitle) {
                    $depIds[] = $byTitle[$depTitle]->id;
                }

                if ($depIds !== [] && $this->graph->wouldCreateCycle($task->id, $depIds)) {
                    throw new RuntimeException("寫入任務「{$task->title}」的相依時偵測到環。");
                }

                if ($depIds !== []) {
                    $task->dependencies()->sync($depIds);
                }

                $this->assign($task, $item['agent']);
            }
        });
    }

    public function assign(Task $task, string $role, ?string $reason = null): bool
    {
        $agent = $this->selector->select($role);

        if ($agent === null) {
            $this->activities->record(
                'AgentUnavailable',
                "沒有 {$role} Agent 可接「{$task->title}」，任務維持 pending",
                $task,
                payload: ['role' => $role],
            );

            return false;
        }

        $task->update([
            'assigned_agent_id' => $agent->id,
            'status' => 'assigned',
        ]);

        $task->assignments()->create([
            'agent_id' => $agent->id,
            'assigned_by' => null,
            'reason' => $reason ?? "AgentSelector: role={$role}",
        ]);

        // 規格第 34 節：CEO → 承接的 Agent。派工在這之前只留下 assignment 一列，
        // 沒有任何「誰通知了誰」的紀錄。
        $this->messenger->taskAssigned($task, $agent);

        return true;
    }

    public function dispatchReadyTasks(?Project $project = null): void
    {
        $tasks = Task::query()
            ->whereIn('status', ['pending', 'assigned'])
            ->when($project, fn ($query) => $query->where('project_id', $project->id))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->with(['dependencies', 'agent'])
            ->get()
            ->filter(fn (Task $task) => $task->dependenciesSatisfied());

        foreach ($tasks as $task) {
            $this->tryDispatch($task);
        }
    }

    public function tryDispatch(Task $task): void
    {
        $task->loadMissing(['dependencies', 'agent']);

        if (! in_array($task->status, ['pending', 'assigned'], true)) {
            return;
        }

        if (! $task->dependenciesSatisfied() || $task->assigned_agent_id === null) {
            return;
        }

        $agent = $task->agent;
        if ($agent === null || $agent->status === 'offline') {
            return;
        }

        if ($this->runningCount($agent) >= $agent->max_concurrency) {
            return;
        }

        if ($task->status === 'pending') {
            $task->update(['status' => 'assigned']);
        }

        ExecuteTaskJob::dispatch($task->id);
    }

    public function afterTaskRun(Task $task, TaskRun $run): void
    {
        $task->refresh();
        $project = $task->project;

        if ($project === null) {
            return;
        }

        if ($run->status === 'failed' && $task->status === 'failed') {
            $this->handleFailure($task);
        }

        // 完成回報：執行的 Agent → CEO（規格第 34 節的 Backend → CEO）。
        if (in_array($task->status, Task::TERMINAL_SUCCESS_STATUSES, true)) {
            $this->messenger->taskCompleted($task);
        }

        $this->dispatchReadyTasks($project);
        $this->refreshProjectStatus($project->fresh() ?? $project);
    }

    public function handleFailure(Task $task): void
    {
        if ($task->retry_count < $task->max_retries) {
            $delay = (int) config('ai_office.jobs.retry_delay_seconds', 10);

            RetryFailedTaskJob::dispatch($task->id)->delay(now()->addSeconds($delay));

            $this->activities->record(
                'TaskRetryScheduled',
                "「{$task->title}」失敗，將進行第 {$task->retry_count} 次重試",
                $task,
                $task->agent,
                ['retry_count' => $task->retry_count, 'max_retries' => $task->max_retries],
            );

            return;
        }

        $ceo = Agent::query()
            ->where('role', (string) config('ai_office.planner.agent_role'))
            ->orderBy('id')
            ->first();

        $this->activities->record(
            'TaskPermanentlyFailed',
            "「{$task->title}」已達最大重試次數，通知 CEO",
            $task,
            $ceo,
            ['retry_count' => $task->retry_count],
        );

        // 上面那則 Activity 說「通知 CEO」，但在這之前沒有任何東西真的送到 CEO
        // 手上——這是規格第 34 節最明顯的缺口。
        $this->messenger->taskPermanentlyFailed($task);
    }

    /**
     * $manual = true 是「人在 UI 按了重試」（規格第 50 節），與自動重試有兩點不同：
     *
     *  1. 不受 max_retries 限制。自動重試的上限是為了擋住無人看管的無限重跑；
     *     人明確要求時再擋，等於在最需要那顆按鈕的時候（任務已永久失敗）拿掉它。
     *  2. 連 cancelled 也收。取消是人的決定，反悔也是。
     *
     * retry_count 不歸零：那是這個任務失敗過幾次的事實，不該因為換人按而消失。
     */
    public function retry(Task $task, bool $manual = false): void
    {
        $task->refresh();

        $acceptable = $manual ? Task::RETRYABLE_STATUSES : ['failed'];

        if (! in_array($task->status, $acceptable, true)) {
            return;
        }

        if (! $manual && $task->retry_count >= $task->max_retries) {
            return;
        }

        $task->update([
            'status' => 'assigned',
            'error' => null,
        ]);

        $agent = $task->agent;
        if ($agent !== null && $agent->status === 'error' && $this->runningCount($agent) === 0) {
            $agent->update(['status' => 'idle']);
        }

        $this->tryDispatch($task);
    }

    /**
     * 取消任務（規格第 50 節的 POST /tasks/{id}/cancel）。
     *
     * running 的任務**不會**當場中斷——沒有辦法從外面砍掉別的 process 裡跑到一半的
     * LLM 請求。這裡只寫下狀態，AgentRuntime 在下一個步進點看到就收手，
     * 而且不會把 cancelled 覆寫回 completed／failed。所以取消 running 的語意是
     * 「不要再往下做了」，不是「當作沒發生過」：已經寫出去的檔案還在。
     *
     * 已排隊但還沒被 worker 撈到的任務更單純：ExecuteTaskJob 開頭就會因為狀態
     * 不是 pending／assigned 而直接 return。
     */
    public function cancel(Task $task, ?string $reason = null): void
    {
        $task->refresh();

        if (! in_array($task->status, Task::CANCELLABLE_STATUSES, true)) {
            return;
        }

        $wasRunning = $task->status === 'running';

        $task->update([
            'status' => 'cancelled',
            'error' => $reason,
        ]);

        // Agent 卡在 working／waiting_review 的話要放它走，否則它的並行額度
        // 被一個已經取消的任務永久佔著，之後什麼都派不進去。
        $agent = $task->agent;
        if ($agent !== null && ! $wasRunning && in_array($agent->status, ['working', 'waiting_review'], true)) {
            $agent->update(['status' => 'idle']);
        }

        $this->activities->record(
            'TaskCancelled',
            "「{$task->title}」已取消".($wasRunning ? '，執行中的那一輪會在下一個步進點停下' : ''),
            $task,
            $agent,
            ['was_running' => $wasRunning],
        );

        $project = $task->project;
        if ($project !== null) {
            $this->refreshProjectStatus($project);
        }
    }

    public function refreshProjectStatus(Project $project): void
    {
        $statuses = $project->tasks()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $open = ['pending', 'planning', 'assigned', 'running', 'waiting_review'];

        if ($statuses->every(fn (string $status) => in_array($status, Task::TERMINAL_SUCCESS_STATUSES, true))) {
            $project->update(['status' => 'completed']);
            $this->activities->record(
                'ProjectCompleted',
                "專案「{$project->name}」全部任務完成",
                project: $project,
            );

            return;
        }

        $hasOpen = $statuses->contains(fn (string $status) => in_array($status, $open, true));
        $hasPermanentFail = $project->tasks()
            ->where(function ($query) {
                $query->where(function ($failed) {
                    $failed->where('status', 'failed')->whereColumn('retry_count', '>=', 'max_retries');
                })->orWhere('status', 'rejected');
            })
            ->exists();

        if (! $hasOpen && $hasPermanentFail) {
            $project->update(['status' => 'failed']);
            $this->activities->record(
                'ProjectFailed',
                "專案「{$project->name}」有任務無法完成",
                project: $project,
            );
        }
    }

    private function runningCount(Agent $agent): int
    {
        return $agent->tasks()->where('status', 'running')->count();
    }
}
