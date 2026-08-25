<?php

namespace Tests\Support\AiOffice;

use App\AiOffice\Process\ProcessResult;
use App\AiOffice\Process\ProcessRunner;

/**
 * 記下每一次呼叫的 argv，讓測試可以直接斷言「我們到底送了哪些旗標給 docker」。
 * 沙箱的安全性幾乎全在那串旗標上，真的跑起來之後反而看不見。
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{argv: list<string>, timeout: int|null, cwd: string|null}> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $queue = [];

    private ProcessResult $default;

    public function __construct(bool $dockerAvailable = true)
    {
        $this->default = new ProcessResult(
            exitCode: $dockerAvailable ? 0 : 127,
            stdout: $dockerAvailable ? '27.0.3' : '',
            stderr: $dockerAvailable ? '' : 'docker: not found',
        );
    }

    public function push(ProcessResult $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function run(array $argv, ?int $timeoutSeconds = null, ?string $cwd = null): ProcessResult
    {
        $this->calls[] = ['argv' => $argv, 'timeout' => $timeoutSeconds, 'cwd' => $cwd];

        return array_shift($this->queue) ?? $this->default;
    }

    /** @return list<string> 最近一次呼叫的 argv */
    public function lastArgv(): array
    {
        return $this->calls[array_key_last($this->calls)]['argv'];
    }

    /** @return list<list<string>> */
    public function argvList(): array
    {
        return array_map(fn (array $call) => $call['argv'], $this->calls);
    }
}
