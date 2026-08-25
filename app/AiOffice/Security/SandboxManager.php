<?php

namespace App\AiOffice\Security;

use App\AiOffice\Process\ProcessResult;
use App\AiOffice\Process\ProcessRunner;
use Illuminate\Support\Str;

/**
 * 規格第 43／59 節：Terminal 指令真的丟進容器裡跑，限制 CPU／記憶體／時間／網路。
 *
 * 這個類別的價值幾乎全部在 `runArgs()` 產出的那串旗標上。每一項都對應一種
 * 具體的逃脫或破壞方式，所以逐條寫下理由，別人要改動時才知道自己在拆掉什麼：
 *
 *   --network none            預設不給網路：Agent 拿到的程式碼／指令來自 LLM，
 *                             有網路就等於有外送資料的管道
 *   --cap-drop ALL            不需要任何 Linux capability
 *   --security-opt no-new-privileges  擋掉 setuid 提權
 *   --read-only + --tmpfs     只有 /workspace 與 /tmp 可寫，改不到映像本身
 *   --user 非 root            容器內不是 root，寫進 workspace 的檔案也不會變成 root 所有
 *   --pids-limit              fork bomb 的第二道防線（第一道是 CommandAllowlist）
 *   --memory / --cpus         單一任務吃不掉整台機器
 *   -v {workspace}:/workspace 只掛這個專案的目錄，**不掛 docker.sock、不掛 host 根目錄**
 *
 * 沙箱不可用時這裡不做任何退路。「退回 host 跑」是 Phase 5 就明確拒絕的選項，
 * 因為那等於把 host shell 交給 LLM。
 */
class SandboxManager
{
    private ?bool $available = null;

    public function __construct(private readonly ProcessRunner $runner) {}

    public function enabled(): bool
    {
        return (bool) config('ai_office.sandbox.enabled');
    }

    /**
     * docker 指令是否真的能用。結果快取在這個實例上——一次任務執行內會問很多次，
     * 每次都 fork 一個 `docker info` 太浪費；但也不跨請求快取，
     * 否則 docker 剛起來還要等快取過期。
     */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $result = $this->runner->run([$this->binary(), 'info', '--format', '{{.ServerVersion}}'], 10);

        return $this->available = $result->successful();
    }

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string, timed_out: bool, sandbox: string}
     */
    public function runCommand(string $command, string $workspacePath): array
    {
        $name = 'ai-office-sandbox-'.Str::lower(Str::random(12));
        $timeout = $this->timeout();

        $result = $this->runner->run($this->runArgs($command, $workspacePath, $name), $timeout + 5);

        if ($result->timedOut) {
            // 逾時的容器不會自己消失（--rm 只在正常結束時生效），留著會一直吃資源。
            $this->runner->run([$this->binary(), 'rm', '--force', $name], 15);
        }

        return [
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'timed_out' => $result->timedOut,
            'sandbox' => $this->image(),
        ];
    }

    /**
     * @return list<string>
     */
    public function runArgs(string $command, string $workspacePath, string $containerName): array
    {
        $args = [
            $this->binary(), 'run', '--rm',
            '--name', $containerName,
            '--network', (string) config('ai_office.sandbox.network', 'none'),
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--pids-limit', (string) max(16, (int) config('ai_office.sandbox.pids_limit', 128)),
            '--memory', $this->memoryLimit(),
            '--memory-swap', $this->memoryLimit(),
            '--cpus', (string) config('ai_office.sandbox.cpu_limit', '1.0'),
            '--user', (string) config('ai_office.sandbox.user', '1000:1000'),
        ];

        if ((bool) config('ai_office.sandbox.read_only_rootfs', true)) {
            $tmpfsSize = max(8, (int) config('ai_office.sandbox.tmpfs_size_mb', 64));

            $args[] = '--read-only';
            $args[] = '--tmpfs';
            $args[] = "/tmp:rw,noexec,nosuid,size={$tmpfsSize}m";
        }

        return [
            ...$args,
            '--volume', "{$workspacePath}:/workspace:rw",
            '--workdir', '/workspace',
            '--env', 'HOME=/workspace',
            $this->image(),
            // 指令在進到這裡之前已經過 CommandAllowlist（白名單 + 禁用中繼字元），
            // 所以這裡的 `sh -c` 拿到的是一條乾淨的指令，不是任意 shell script。
            'sh', '-c', $command,
        ];
    }

    public function timeout(): int
    {
        return max(1, (int) config('ai_office.sandbox.timeout_seconds', 60));
    }

    public function image(): string
    {
        return (string) config('ai_office.sandbox.image', 'alpine:3.20');
    }

    public function binary(): string
    {
        return (string) config('ai_office.sandbox.docker_binary', 'docker');
    }

    /** 測試用：讓同一個實例重新偵測。 */
    public function forgetAvailability(): void
    {
        $this->available = null;
    }

    /** 給 DockerSandboxEngine 共用的執行入口，維持同一組逾時處理。 */
    public function runDocker(array $argv, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->runner->run([$this->binary(), ...$argv], $timeoutSeconds ?? $this->timeout());
    }

    private function memoryLimit(): string
    {
        return max(64, (int) config('ai_office.sandbox.memory_limit_mb', 512)).'m';
    }
}
