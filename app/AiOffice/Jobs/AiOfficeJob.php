<?php

namespace App\AiOffice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AI Office 的 Job 共用：獨立佇列、timeout 比 LLM 長、tries=1。
 * 領域層重試走 RetryFailedTaskJob，不要跟 Laravel job retry 疊加。
 */
abstract class AiOfficeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct()
    {
        $this->onQueue((string) config('ai_office.queue'));
        $this->tries = (int) config('ai_office.jobs.tries', 1);
        $this->timeout = (int) config('ai_office.jobs.timeout', 300);
    }
}
