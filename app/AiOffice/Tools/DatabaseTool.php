<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Security\SqlReadGuard;
use Illuminate\Support\Facades\DB;

/**
 * 規格第 20 節：第一版只允許 SELECT／EXPLAIN／DESCRIBE。
 * production 預設完全禁止；允許的環境與關鍵字都讀 config。
 */
class DatabaseTool extends ActionTool
{
    public function __construct(private readonly SqlReadGuard $sql)
    {
        parent::__construct('database_read', 'database', 'low');
    }

    public function description(): string
    {
        return '對應用資料庫下唯讀查詢（SELECT／EXPLAIN／DESCRIBE）。寫入語句一律拒絕。';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['sql' => ['type' => 'string']],
            'required' => ['sql'],
        ];
    }

    public function execute(array $input, ToolContext $context): array
    {
        $environments = config('ai_office.tools.database.allowed_environments', []);

        if (! is_array($environments) || ! in_array(app()->environment(), $environments, true)) {
            throw new \RuntimeException('目前環境禁止使用 DatabaseTool。');
        }

        $sql = $this->stringArg($input, 'sql');
        $this->sql->assertReadOnly($sql);

        $rows = DB::select($sql);
        $max = (int) config('ai_office.tools.database.max_rows', 100);
        $truncated = count($rows) > $max;
        $slice = array_slice($rows, 0, $max);

        return [
            'rows' => json_decode(json_encode($slice), true),
            'count' => count($slice),
            'truncated' => $truncated,
        ];
    }
}
