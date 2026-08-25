<?php

namespace App\AiOffice\Tools;

/**
 * 單一動作一個實例：name() 是 read_file／git_commit 這種 ability 名稱。
 * 風險等級讀 config，沒設才用建構時的預設——改 config 測試要跟著變。
 */
abstract class ActionTool implements ToolInterface
{
    public function __construct(
        protected readonly string $actionName,
        protected readonly string $toolsetName,
        protected readonly string $defaultRisk,
    ) {}

    public function name(): string
    {
        return $this->actionName;
    }

    public function toolset(): string
    {
        return $this->toolsetName;
    }

    public function riskLevel(): string
    {
        $configured = config("ai_office.tools.{$this->toolsetName}.actions.{$this->actionName}.risk");

        return is_string($configured) && $configured !== '' ? $configured : $this->defaultRisk;
    }

    protected function truncate(string $text): string
    {
        $max = (int) config('ai_office.tools.max_output_bytes', 32_000);

        if ($max <= 0 || strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max)."\n…（已截斷）";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function stringArg(array $input, string $key, bool $required = true): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            if ($required) {
                throw new \InvalidArgumentException("{$this->actionName} 需要 {$key}。");
            }

            return null;
        }

        $value = $input[$key];

        if (! is_string($value) && ! is_numeric($value)) {
            throw new \InvalidArgumentException("{$key} 必須是字串。");
        }

        return (string) $value;
    }
}
