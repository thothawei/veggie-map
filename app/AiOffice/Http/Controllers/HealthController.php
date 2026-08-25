<?php

namespace App\AiOffice\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * AI Office 的 readiness 檢查（規格第 72 節 Phase 1：Health Check／Database Connection／
 * Redis Connection）。
 *
 * 跟 Laravel 內建的 `/up` 分工不同：`/up` 是 liveness（框架有沒有起來），這一條是
 * readiness（AI Office 真的能不能開工）——資料庫、Redis、佇列、workspace 目錄
 * 任一項不通，Agent 就沒辦法執行任務。
 *
 * 每一項都是**真的去連**，不是讀設定檔回報。回傳的數字（延遲、佇列長度）也都是
 * 當下量到的，沒有任何寫死值（規格第 74 節）。
 *
 * 需要登入且具備 AI Office 角色：基礎設施狀態對匿名者是不必要的資訊揭露。
 */
class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => $this->databaseDetails()),
            'redis' => $this->check(fn () => $this->redisDetails()),
            'queue' => $this->check(fn () => $this->queueDetails()),
            'workspace' => $this->check(fn () => $this->workspaceDetails()),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'llm' => [
                    // 只回報「設定成哪個 provider」與「金鑰有沒有設」，永遠不回傳金鑰本身。
                    'provider' => config('ai_office.llm.default_provider'),
                    'api_key_configured' => filled(config('ai_office.llm.providers.claude.api_key')),
                ],
                'limits' => config('ai_office.limits'),
                'sandbox_enabled' => config('ai_office.sandbox.enabled'),
            ],
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  callable(): array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function check(callable $probe): array
    {
        $startedAt = microtime(true);

        try {
            $details = $probe();
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'latency_ms' => $this->elapsedMs($startedAt),
                // 例外訊息可能帶連線字串（含使用者名稱／主機），只回類別名稱，
                // 細節留給 log，不從 API 洩出去。
                'error' => class_basename($e),
            ];
        }

        return ['ok' => true, 'latency_ms' => $this->elapsedMs($startedAt)] + $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseDetails(): array
    {
        $connection = DB::connection();
        $connection->select('select 1');

        return [
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function redisDetails(): array
    {
        // phpredis 回 true、predis 回 '+PONG'，兩種都算通。真的沒連上會直接丟例外。
        Redis::connection()->ping();

        return ['client' => config('database.redis.client')];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueDetails(): array
    {
        $queue = config('ai_office.queue');

        return [
            'connection' => config('queue.default'),
            'queue' => $queue,
            'pending_jobs' => Queue::size($queue),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceDetails(): array
    {
        $root = config('ai_office.workspace_root');

        if (! is_dir($root) || ! is_writable($root)) {
            throw new \RuntimeException('Workspace root is not a writable directory.');
        }

        return ['path' => $root];
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
