<?php

namespace App\AiOffice\Security;

/**
 * 規格第 18 節：TerminalTool 只放行白名單；denylist 即使被加進 allowlist 也硬擋。
 * 名單與 pattern 全部讀 config，這裡不做 `if ($cmd === 'rm -rf /')`。
 */
class CommandAllowlist
{
    public function assertAllowed(string $command): void
    {
        $command = trim($command);

        if ($command === '') {
            throw new CommandDeniedException('指令不可為空。');
        }

        foreach ($this->denylistPatterns() as $pattern) {
            if (@preg_match($pattern, $command) === 1) {
                throw new CommandDeniedException('指令命中 denylist。');
            }
        }

        foreach ($this->metacharacters() as $needle) {
            if (str_contains($command, $needle)) {
                throw new CommandDeniedException('指令含有禁止的 shell 中介字元。');
            }
        }

        foreach ($this->allowlist() as $prefix) {
            if ($command === $prefix || str_starts_with($command, $prefix.' ')) {
                return;
            }
        }

        throw new CommandDeniedException('指令不在 allowlist 內。');
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        $items = config('ai_office.tools.terminal.allowlist', []);

        return is_array($items) ? array_values(array_map(strval(...), $items)) : [];
    }

    /**
     * @return list<string>
     */
    private function denylistPatterns(): array
    {
        $items = config('ai_office.tools.terminal.denylist_patterns', []);

        return is_array($items) ? array_values(array_map(strval(...), $items)) : [];
    }

    /**
     * @return list<string>
     */
    private function metacharacters(): array
    {
        $items = config('ai_office.tools.terminal.denied_metacharacters', []);

        return is_array($items) ? array_values(array_map(strval(...), $items)) : [];
    }
}
