<?php

namespace App\AiOffice\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * 用 Symfony Process 直接 exec argv（不經過 shell）。
 * 走 shell 的話，指令字串裡任何一個 `;` 或反引號都會變成新的執行機會。
 */
class SymfonyProcessRunner implements ProcessRunner
{
    public function run(array $argv, ?int $timeoutSeconds = null, ?string $cwd = null): ProcessResult
    {
        $process = new Process($argv, $cwd);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessResult(
                exitCode: null,
                stdout: $process->getOutput(),
                stderr: $process->getErrorOutput(),
                timedOut: true,
            );
        }

        return new ProcessResult(
            exitCode: $process->getExitCode(),
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }
}
