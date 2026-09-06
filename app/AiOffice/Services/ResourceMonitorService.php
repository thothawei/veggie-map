<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Task;
use App\AiOffice\Security\SandboxManager;
use Illuminate\Support\Facades\Queue;

/**
 * `GET /api/v1/ai-office/resource-usage`（規格第 39、44 節：`ResourceUsage`）。
 *
 * 規格第 39 節自己留了退路：拿不到 host CPU／Memory 就用 application-level
 * metrics，**但 UI 必須標示資料來源、不要假裝是真的 host CPU**。這個 repo
 * 沒有對 sandbox 容器做 `docker stats` 輪詢的基礎建設（容器是 `--rm --detach`
 * 跑完即丟，不是常駐可輪詢的對象），所以老實地只回應用層量得到的訊號：
 *
 * - `host_load`：`sys_getloadavg()`，Linux 才有、且反映的是**整台機器**
 *   （所有 container 共用同一個 kernel），不是只有 AI Office 這支 process 的負載。
 * - `php_memory`：目前這次請求的 PHP process 記憶體用量，不是所有 worker 的總和。
 * - `queue`：Horizon／queue 待處理工作數，來自 `Queue::size()`（跟 HealthController
 *   同一支，量到的是當下真值）。
 * - `tasks`：目前 `running`／`waiting_review` 的任務數、`working` 的 Agent 數——
 *   這是「系統有多忙」最接近真相的代理指標，因為每個 running task 對應一個
 *   正在跑的 Agent loop。
 *
 * 每個欄位都標 `source`，前端顯示時要照樣標出來，不能省略。
 */
class ResourceMonitorService
{
    public function __construct(private readonly SandboxManager $sandbox) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'source' => 'application', // §39：不是真的 host CPU/Memory 監控
            'host_load' => $this->hostLoad(),
            'php_memory' => $this->phpMemory(),
            'queue' => $this->queue(),
            'tasks' => $this->tasks(),
            'sandbox' => $this->sandboxStatus(),
        ];
    }

    /**
     * @return array{available: bool, one: float|null, five: float|null, fifteen: float|null}
     */
    private function hostLoad(): array
    {
        // sys_getloadavg() 在 Windows 上永遠回 false，容器內是 Linux 所以會有值，
        // 但反映的是整台宿主機（所有 container 共用同一顆 kernel），不是只有這支
        // PHP process 吃掉多少——這正是要在 UI 上說清楚的那個落差。
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            return ['available' => false, 'one' => null, 'five' => null, 'fifteen' => null];
        }

        return [
            'available' => true,
            'one' => round($load[0], 2),
            'five' => round($load[1], 2),
            'fifteen' => round($load[2], 2),
        ];
    }

    /**
     * @return array{used_bytes: int, peak_bytes: int, limit: string}
     */
    private function phpMemory(): array
    {
        return [
            'used_bytes' => memory_get_usage(true),
            'peak_bytes' => memory_get_peak_usage(true),
            'limit' => (string) ini_get('memory_limit'),
        ];
    }

    /**
     * @return array{connection: string, queue: string, pending_jobs: int}
     */
    private function queue(): array
    {
        $queue = (string) config('ai_office.queue');

        return [
            'connection' => (string) config('queue.default'),
            'queue' => $queue,
            'pending_jobs' => Queue::size($queue),
        ];
    }

    /**
     * @return array{running_tasks: int, waiting_review_tasks: int, working_agents: int}
     */
    private function tasks(): array
    {
        return [
            'running_tasks' => Task::query()->where('status', 'running')->count(),
            'waiting_review_tasks' => Task::query()->where('status', 'waiting_review')->count(),
            'working_agents' => Agent::query()->where('status', 'working')->count(),
        ];
    }

    /**
     * @return array{docker_available: bool, docker_tool_enabled: bool, cpu_limit: string, memory_limit_mb: int}
     */
    private function sandboxStatus(): array
    {
        return [
            // 容器是 --rm --detach 跑完即丟，這裡不去輪詢實際容器的 CPU／Memory
            // 用量（沒有常駐對象可以輪詢），只回報「這台機器能不能開沙箱」與
            // 「開了會套用哪個上限」——那是設定值，不是即時用量，欄位命名上不混淆。
            'docker_available' => $this->sandbox->available(),
            'docker_tool_enabled' => (bool) config('ai_office.sandbox.docker_tool_enabled', false),
            'cpu_limit' => (string) config('ai_office.sandbox.cpu_limit', '1.0'),
            'memory_limit_mb' => (int) config('ai_office.sandbox.memory_limit_mb', 512),
        ];
    }
}
