<?php

namespace App\AiOffice\Process;

/**
 * 一次外部程序執行的結果。`timedOut` 獨立成一個欄位而不是塞進 exit code：
 * 「跑太久被砍」跟「跑完但回非零」要給模型不同的訊息，混在一起會讓它一直重試。
 */
final class ProcessResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
