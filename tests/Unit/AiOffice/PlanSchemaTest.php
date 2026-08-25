<?php

namespace Tests\Unit\AiOffice;

use App\AiOffice\Orchestration\PlanSchema;
use App\AiOffice\Orchestration\PlanValidationException;
use Tests\TestCase;

class PlanSchemaTest extends TestCase
{
    private function schema(): PlanSchema
    {
        return new PlanSchema;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPlan(): array
    {
        return [
            'project' => ['name' => '台灣素食餐廳地圖', 'description' => 'demo'],
            'tasks' => [
                ['title' => '設計資料庫', 'agent' => 'backend', 'dependencies' => []],
                ['title' => '建立 REST API', 'agent' => 'backend', 'dependencies' => ['設計資料庫']],
                ['title' => '建立前端地圖', 'agent' => 'frontend', 'dependencies' => []],
                ['title' => 'QA', 'agent' => 'qa', 'dependencies' => ['建立 REST API', '建立前端地圖']],
            ],
        ];
    }

    public function test_valid_plan_is_normalized(): void
    {
        $plan = $this->schema()->validate($this->validPlan());

        $this->assertSame('台灣素食餐廳地圖', $plan['project']['name']);
        $this->assertCount(4, $plan['tasks']);
        $this->assertSame(50, $plan['tasks'][0]['priority']);
        $this->assertSame(['設計資料庫'], $plan['tasks'][1]['dependencies']);
    }

    public function test_json_can_be_extracted_from_a_markdown_fence(): void
    {
        $payload = $this->schema()->extract(
            "好的，規劃如下：\n```json\n".json_encode($this->validPlan(), JSON_UNESCAPED_UNICODE)."\n```\n"
        );

        $this->assertIsArray($payload);
        $this->assertSame('設計資料庫', $payload['tasks'][0]['title']);
    }

    public function test_natural_language_is_not_treated_as_a_plan(): void
    {
        $this->assertNull($this->schema()->extract("1. 做資料庫\n2. 做 API\n3. 做前端"));
        $this->assertNull($this->schema()->extract('請把這個專案拆成三個任務。'));
    }

    public function test_unknown_agent_role_is_rejected(): void
    {
        $payload = $this->validPlan();
        $payload['tasks'][0]['agent'] = 'wizard';

        try {
            $this->schema()->validate($payload);
            $this->fail('Expected PlanValidationException');
        } catch (PlanValidationException $e) {
            $this->assertStringContainsString('wizard', $e->getMessage());
        }
    }

    public function test_removing_a_role_from_config_makes_that_agent_invalid(): void
    {
        $this->assertNotEmpty($this->schema()->validate($this->validPlan())['tasks']);

        config(['ai_office.planner.assignable_roles' => ['frontend', 'qa', 'design', 'devops']]);

        $this->expectException(PlanValidationException::class);
        $this->expectExceptionMessage('backend');
        $this->schema()->validate($this->validPlan());
    }

    public function test_duplicate_titles_are_rejected(): void
    {
        $payload = $this->validPlan();
        $payload['tasks'][1]['title'] = '設計資料庫';

        $this->expectException(PlanValidationException::class);
        $this->expectExceptionMessage('重複');
        $this->schema()->validate($payload);
    }

    public function test_unknown_dependency_title_is_rejected(): void
    {
        $payload = $this->validPlan();
        $payload['tasks'][1]['dependencies'] = ['不存在的任務'];

        $this->expectException(PlanValidationException::class);
        $this->expectExceptionMessage('不存在');
        $this->schema()->validate($payload);
    }

    public function test_cyclic_dependencies_are_rejected(): void
    {
        $payload = [
            'project' => ['name' => '環'],
            'tasks' => [
                ['title' => 'A', 'agent' => 'backend', 'dependencies' => ['B']],
                ['title' => 'B', 'agent' => 'backend', 'dependencies' => ['A']],
            ],
        ];

        $this->expectException(PlanValidationException::class);
        $this->expectExceptionMessage('環');
        $this->schema()->validate($payload);
    }

    public function test_empty_tasks_are_rejected(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->schema()->validate(['project' => ['name' => '空'], 'tasks' => []]);
    }

    public function test_prompt_description_lists_roles_from_config(): void
    {
        config(['ai_office.planner.assignable_roles' => ['backend', 'qa']]);

        $prompt = $this->schema()->promptDescription();

        $this->assertStringContainsString('backend | qa', $prompt);
        $this->assertStringNotContainsString('frontend', $prompt);
    }
}
