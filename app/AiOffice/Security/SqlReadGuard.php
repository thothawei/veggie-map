<?php

namespace App\AiOffice\Security;

/**
 * 規格第 20 節：DatabaseTool 只允許讀取。前綴白名單 + 關鍵字黑名單雙重檢查，
 * 兩份名單都讀 config。
 */
class SqlReadGuard
{
    public function assertReadOnly(string $sql): void
    {
        $stripped = trim($this->stripComments($sql));

        if ($stripped === '') {
            throw new UnsafeQueryException('SQL 不可為空。');
        }

        $withoutTrailing = rtrim($stripped, "; \t\n\r");

        if (str_contains($withoutTrailing, ';')) {
            throw new UnsafeQueryException('禁止一次送出多句 SQL。');
        }

        $tokens = preg_split('/\s+/', $withoutTrailing) ?: [];
        $first = strtolower((string) ($tokens[0] ?? ''));

        if (! in_array($first, $this->allowedPrefixes(), true)) {
            throw new UnsafeQueryException('只允許 '.implode('/', $this->allowedPrefixes())."，收到的是 {$first}。");
        }

        foreach ($this->deniedKeywords() as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $withoutTrailing) === 1) {
                throw new UnsafeQueryException("SQL 含有禁止的關鍵字：{$keyword}。");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function allowedPrefixes(): array
    {
        $items = config('ai_office.tools.database.allowed_prefixes', []);

        return is_array($items) ? array_map(strtolower(...), array_map(strval(...), $items)) : [];
    }

    /**
     * @return list<string>
     */
    private function deniedKeywords(): array
    {
        $items = config('ai_office.tools.database.denied_keywords', []);

        return is_array($items) ? array_map(strtolower(...), array_map(strval(...), $items)) : [];
    }

    private function stripComments(string $sql): string
    {
        $withoutBlock = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;

        $lines = preg_replace('/--[^\n]*/', ' ', $withoutBlock) ?? $withoutBlock;

        return preg_replace('/#[^\n]*/', ' ', $lines) ?? $lines;
    }
}
