<?php

namespace App\AiOffice\Orchestration;

/**
 * 規格第 28 節：CEO 的產出必須是通過驗證的 structured JSON。
 *
 * 合法角色與欄位規則讀 config，不在這裡寫死 backend／frontend。自然語言清單
 * （「1. 做資料庫 2. 做 API」）解不出 JSON 物件，會被拒絕而不是被拆成任務。
 */
class PlanSchema
{
    /**
     * 從 LLM 回覆抽出 JSON 物件。允許包在 markdown fence 裡，但不允許「看起來像
     * 清單的純文字」——抽不出 `{...}` 就回 null，交給呼叫端重試。
     *
     * @return array<string, mixed>|null
     */
    public function extract(string $text): ?array
    {
        $candidate = trim($text);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $candidate, $matches) === 1) {
            $candidate = $matches[1];
        } else {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');

            if ($start === false || $end === false || $end <= $start) {
                return null;
            }

            $candidate = substr($candidate, $start, $end - $start + 1);
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{project: array{name: ?string, description: ?string}, tasks: list<array{title: string, agent: string, description: ?string, priority: int, dependencies: list<string>}>}
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $project = $payload['project'] ?? [];
        if (! is_array($project)) {
            $errors[] = 'project 必須是物件。';
            $project = [];
        }

        $rawTasks = $payload['tasks'] ?? null;
        if (! is_array($rawTasks) || $rawTasks === [] || ! array_is_list($rawTasks)) {
            $errors[] = 'tasks 必須是非空陣列。';
            $rawTasks = [];
        }

        $assignable = $this->assignableRoles();
        $titles = [];
        $tasks = [];

        foreach ($rawTasks as $index => $raw) {
            $label = 'tasks['.$index.']';

            if (! is_array($raw)) {
                $errors[] = "{$label} 必須是物件。";

                continue;
            }

            $title = isset($raw['title']) && is_string($raw['title']) ? trim($raw['title']) : '';
            if ($title === '') {
                $errors[] = "{$label}.title 必填。";
            } elseif (isset($titles[$title])) {
                $errors[] = "任務 title「{$title}」重複——相依是用 title 指到其他任務，重複會指錯人。";
            } else {
                $titles[$title] = $index;
            }

            $role = isset($raw['agent']) && is_string($raw['agent']) ? trim($raw['agent']) : '';
            if ($role === '') {
                $errors[] = "{$label}.agent 必填。";
            } elseif (! in_array($role, $assignable, true)) {
                $errors[] = "{$label}.agent「{$role}」不在可派工角色裡（".implode('／', $assignable).'）。';
            }

            $dependencies = $raw['dependencies'] ?? [];
            if (! is_array($dependencies) || ! array_is_list($dependencies)) {
                $errors[] = "{$label}.dependencies 必須是字串陣列。";
                $dependencies = [];
            }

            $cleanDeps = [];
            foreach ($dependencies as $depIndex => $dep) {
                if (! is_string($dep) || trim($dep) === '') {
                    $errors[] = "{$label}.dependencies[{$depIndex}] 必須是任務 title。";

                    continue;
                }
                $cleanDeps[] = trim($dep);
            }

            $priority = $raw['priority'] ?? 50;
            if (! is_numeric($priority)) {
                $errors[] = "{$label}.priority 必須是整數。";
                $priority = 50;
            }
            $priority = (int) $priority;
            if ($priority < 0 || $priority > 100) {
                $errors[] = "{$label}.priority 必須介於 0 與 100。";
            }

            $description = $raw['description'] ?? null;
            if ($description !== null && ! is_string($description)) {
                $errors[] = "{$label}.description 必須是字串。";
                $description = null;
            }

            $tasks[] = [
                'title' => $title,
                'agent' => $role,
                'description' => is_string($description) ? $description : null,
                'priority' => $priority,
                'dependencies' => $cleanDeps,
            ];
        }

        foreach ($tasks as $index => $task) {
            foreach ($task['dependencies'] as $dep) {
                if ($dep === $task['title'] && $task['title'] !== '') {
                    $errors[] = "tasks[{$index}] 不能依賴自己。";
                } elseif ($task['title'] !== '' && ! isset($titles[$dep])) {
                    $errors[] = "tasks[{$index}] 依賴了不存在的 title「{$dep}」。";
                }
            }
        }

        if ($this->hasCycle($tasks)) {
            $errors[] = '任務相依圖有環，整條鏈會永遠等不到前置完成。';
        }

        if ($errors !== []) {
            throw new PlanValidationException($errors);
        }

        $projectName = isset($project['name']) && is_string($project['name']) ? trim($project['name']) : null;
        $projectDescription = isset($project['description']) && is_string($project['description'])
            ? $project['description']
            : null;

        return [
            'project' => [
                'name' => $projectName !== '' ? $projectName : null,
                'description' => $projectDescription,
            ],
            'tasks' => $tasks,
        ];
    }

    /**
     * 寫進 CEO prompt 的 schema 說明，跟 validate() 讀同一份 config，避免 prompt
     * 裡列的角色跟驗證白名單各寫一份然後漂移。
     */
    public function promptDescription(): string
    {
        $roles = implode(' | ', $this->assignableRoles());

        return <<<SCHEMA
        只輸出一個 JSON 物件，不要前言、不要 markdown。形狀：
        {
          "project": { "name": string, "description": string },
          "tasks": [
            {
              "title": string,
              "agent": {$roles},
              "description": string,
              "priority": 0-100,
              "dependencies": [其他 task 的 title]
            }
          ]
        }
        tasks 至少一筆；title 不可重複；dependencies 只能引用本清單裡的 title；不可成環。
        SCHEMA;
    }

    /**
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        $roles = config('ai_office.planner.assignable_roles', []);

        return is_array($roles) ? array_values($roles) : [];
    }

    /**
     * @param  list<array{title: string, dependencies: list<string>}>  $tasks
     */
    private function hasCycle(array $tasks): bool
    {
        $edges = [];
        foreach ($tasks as $task) {
            if ($task['title'] === '') {
                continue;
            }
            $edges[$task['title']] = $task['dependencies'];
        }

        $visited = [];
        $stack = [];

        $visit = function (string $node) use (&$visit, &$visited, &$stack, $edges): bool {
            if (isset($stack[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visited[$node] = true;
            $stack[$node] = true;
            foreach ($edges[$node] ?? [] as $dep) {
                if ($visit($dep)) {
                    return true;
                }
            }
            unset($stack[$node]);

            return false;
        };

        foreach (array_keys($edges) as $title) {
            if ($visit($title)) {
                return true;
            }
        }

        return false;
    }
}
