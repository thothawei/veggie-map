<?php

namespace App\AiOffice\Jobs;

use App\AiOffice\Models\Project;
use App\AiOffice\Orchestration\AgentOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * 規格第 30 節：規劃在 Queue 裡跑，不在建立專案的 HTTP request 裡呼叫 LLM。
 */
class PlanProjectJob extends AiOfficeJob implements ShouldBeUnique
{
    public function __construct(public int $projectId)
    {
        parent::__construct();
    }

    public function uniqueId(): string
    {
        return 'plan-'.$this->projectId;
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $project = Project::find($this->projectId);

        if ($project === null) {
            return;
        }

        $orchestrator->planProject($project);
    }
}
