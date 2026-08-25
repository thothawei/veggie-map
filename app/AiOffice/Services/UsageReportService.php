<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\TokenUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 規格第 40／74 節：用量與成本報表。所有數字都是從 `ai_office_token_usages`
 * 聚合出來的，沒有任何一個是寫死的。
 *
 * 成本用 `estimated_cost` 欄位加總，不在報表這層重算單價：重算的話，改了
 * `config('ai_office.llm.pricing')` 之後連歷史帳單都會跟著變，對不上當時的實際請求。
 */
class UsageReportService
{
    /**
     * @param  array{project_id?: int|null, agent_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        return [
            'totals' => $this->totals($filters),
            'by_model' => $this->groupedBy($filters, 'model'),
            'by_agent' => $this->groupedByAgent($filters),
            'by_project' => $this->groupedByProject($filters),
            'daily' => $this->daily($filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(array $filters = []): array
    {
        $row = $this->query($filters)->toBase()
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->first();

        return [
            'requests' => (int) ($row->requests ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'estimated_cost' => $this->money($row->estimated_cost ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function groupedBy(array $filters, string $column): array
    {
        return $this->query($filters)->toBase()
            ->select($column)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->groupBy($column)
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($row) => [
                $column => $row->{$column},
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'estimated_cost' => $this->money($row->estimated_cost),
            ])
            ->all();
    }

    /**
     * 帶名字回去：前端拿到 `agent_id` 還要再打一次 API 才知道是誰，那是多餘的往返。
     * 名字用 join 取，Agent 被刪掉（token_usages 是 nullOnDelete）時回 null，不編一個。
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function groupedByAgent(array $filters): array
    {
        return $this->query($filters)->toBase()
            ->leftJoin('ai_office_agents', 'ai_office_agents.id', '=', 'ai_office_token_usages.agent_id')
            ->select('ai_office_token_usages.agent_id')
            ->selectRaw('MAX(ai_office_agents.name) as agent_name')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->groupBy('ai_office_token_usages.agent_id')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($row) => [
                'agent_id' => $row->agent_id === null ? null : (int) $row->agent_id,
                'agent_name' => $row->agent_name,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'estimated_cost' => $this->money($row->estimated_cost),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function groupedByProject(array $filters): array
    {
        return $this->query($filters)->toBase()
            ->leftJoin('ai_office_projects', 'ai_office_projects.id', '=', 'ai_office_token_usages.project_id')
            ->select('ai_office_token_usages.project_id')
            ->selectRaw('MAX(ai_office_projects.name) as project_name')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->groupBy('ai_office_token_usages.project_id')
            ->orderByDesc('total_tokens')
            ->get()
            ->map(fn ($row) => [
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'project_name' => $row->project_name,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
                'estimated_cost' => $this->money($row->estimated_cost),
            ])
            ->all();
    }

    /**
     * 每日序列。只回有資料的日子——把沒有用量的日子補成 0 是前端畫圖的事，
     * 後端補的話，區間拉長到一年就會回一堆零列。
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function daily(array $filters): array
    {
        return $this->query($filters)->toBase()
            ->selectRaw('DATE(ai_office_token_usages.created_at) as day')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->groupBy(DB::raw('DATE(ai_office_token_usages.created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'total_tokens' => (int) $row->total_tokens,
                'estimated_cost' => $this->money($row->estimated_cost),
            ])
            ->all();
    }

    /**
     * 聚合查詢一律 `->toBase()` 之後再 select：SUM／COUNT 出來的是統計列，不是
     * TokenUsage 模型。用 Eloquent 取回來的話，`$row->total_tokens` 會是一個
     * 模型上根本不存在的動態屬性——PHPStan 抓得到，執行期則是安靜地能跑。
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<TokenUsage>
     */
    private function query(array $filters): Builder
    {
        // 欄位一律帶表名。by_agent／by_project 會 join 另一張表，那兩張表也有
        // `created_at`，不帶表名時 MySQL 直接回「Column 'created_at' is ambiguous」
        // ——而且只有在帶日期篩選時才會炸，很容易漏測。
        $table = (new TokenUsage)->getTable();

        return TokenUsage::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where("{$table}.project_id", $id))
            ->when($filters['agent_id'] ?? null, fn ($q, $id) => $q->where("{$table}.agent_id", $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate("{$table}.created_at", '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate("{$table}.created_at", '<=', $to));
    }

    /** 金額一律回固定 6 位小數的字串，不回浮點數——帳務數字不該經過 float。 */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
