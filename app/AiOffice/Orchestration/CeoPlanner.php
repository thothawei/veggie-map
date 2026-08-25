<?php

namespace App\AiOffice\Orchestration;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\LlmRequest;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Services\ActivityRecorder;
use App\AiOffice\Services\TokenUsageService;
use RuntimeException;

/**
 * 規格第 28 節：把專案需求交給 CEO Agent，拿到通過 schema 的任務圖。
 *
 * 不合格的回覆會重試（次數來自 config），用完就丟 PlanValidationException——
 * 不會退而求其次把自然語言拆成任務。
 */
class CeoPlanner
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly PlanSchema $schema,
        private readonly TokenUsageService $tokenUsage,
        private readonly ActivityRecorder $activities,
    ) {}

    /**
     * @return array{project: array{name: ?string, description: ?string}, tasks: list<array{title: string, agent: string, description: ?string, priority: int, dependencies: list<string>}>}
     */
    public function plan(Project $project): array
    {
        $ceo = $this->ceoAgent();
        $attempts = max(1, (int) config('ai_office.planner.max_attempts', 3));
        $lastError = '沒有收到有效規劃。';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->provider->send(new LlmRequest(
                systemPrompt: $ceo->system_prompt,
                messages: [['role' => 'user', 'content' => $this->userPrompt($project, $attempt, $lastError)]],
                model: $ceo->model_name,
            ));

            $this->tokenUsage->recordUsage($response, $ceo, $project);

            if ($response->wantsTool()) {
                $lastError = '規劃階段不該呼叫工具，請直接輸出 JSON。';

                continue;
            }

            $payload = $this->schema->extract($response->text);
            if ($payload === null) {
                $lastError = '回覆裡找不到 JSON 物件。不要輸出自然語言任務清單。';

                continue;
            }

            try {
                $plan = $this->schema->validate($payload);
            } catch (PlanValidationException $e) {
                $lastError = $e->getMessage();

                continue;
            }

            $this->activities->record(
                'ProjectPlanned',
                "{$ceo->name} 完成規劃，拆成 ".count($plan['tasks']).' 個任務',
                agent: $ceo,
                payload: ['attempt' => $attempt, 'task_count' => count($plan['tasks'])],
                project: $project,
            );

            return $plan;
        }

        throw new PlanValidationException([$lastError]);
    }

    private function ceoAgent(): Agent
    {
        $role = (string) config('ai_office.planner.agent_role');

        $agent = Agent::query()
            ->where('role', $role)
            ->where('status', '!=', 'offline')
            ->orderBy('id')
            ->first();

        if ($agent === null) {
            throw new RuntimeException(
                "找不到 role={$role} 的規劃 Agent。請先跑 AiOfficeAgentSeeder。"
            );
        }

        return $agent;
    }

    private function userPrompt(Project $project, int $attempt, string $lastError): string
    {
        $schema = $this->schema->promptDescription();
        $description = $project->description ?: '（沒有補充說明）';
        $retry = $attempt === 1 ? '' : "\n\n上一輪沒通過驗證：{$lastError}\n請只修正這些問題後重新輸出完整 JSON。";

        return <<<PROMPT
        專案名稱：{$project->name}

        需求：
        {$description}

        {$schema}
        {$retry}
        PROMPT;
    }
}
