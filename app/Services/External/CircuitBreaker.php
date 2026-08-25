<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Cache;

/**
 * 第三方 API 的斷路器（總 Prompt 第二十節）。
 *
 * 為什麼 timeout／retry 還不夠：排程一次跑五個城市 bbox，每個都是**獨立的
 * artisan 程序**。Overpass 掛掉時，五個程序會各自 retry 三次、各自等滿逾時——
 * 一次同步變成十五次注定失敗的請求、加起來好幾分鐘。斷路器讓後面的請求直接
 * 短路，不用再等。
 *
 * 狀態存在 Cache（Redis）而不是程序記憶體，正是因為要跨程序共用——存在物件裡
 * 的計數器在這個場景等於沒有。
 *
 * 這是簡化版的斷路器：只有 closed 與 open 兩態，沒有 half-open 的試探請求。
 * 冷卻時間到就直接放行下一個請求，成功則歸零、失敗則重新開路——效果接近
 * half-open，但少了一套狀態機。
 */
class CircuitBreaker
{
    private function __construct(
        private readonly string $service,
        private readonly int $failureThreshold,
        private readonly int $cooldownSeconds,
    ) {}

    public static function for(string $service): self
    {
        return new self(
            $service,
            (int) config('services.circuit_breaker.failure_threshold', 5),
            (int) config('services.circuit_breaker.cooldown_seconds', 600),
        );
    }

    /** 現在可以打這個服務嗎？open 期間一律 false。 */
    public function available(): bool
    {
        return ! Cache::has($this->openKey());
    }

    /** 還要多久才會再放行（秒）。給 log 用，讓「為什麼沒同步」看得見。 */
    public function retryAfter(): int
    {
        return $this->cooldownSeconds;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failureKey());
        Cache::forget($this->openKey());
    }

    /**
     * 連續失敗達到門檻就開路。計數本身也有 TTL：偶爾失敗一次不該永遠累積，
     * 否則跑了三個月之後任何一次失敗都會直接觸發開路。
     */
    public function recordFailure(): void
    {
        $failures = (int) Cache::get($this->failureKey(), 0) + 1;
        Cache::put($this->failureKey(), $failures, $this->cooldownSeconds);

        if ($failures >= $this->failureThreshold) {
            Cache::put($this->openKey(), true, $this->cooldownSeconds);
        }
    }

    private function failureKey(): string
    {
        return "circuit:{$this->service}:failures";
    }

    private function openKey(): string
    {
        return "circuit:{$this->service}:open";
    }
}
