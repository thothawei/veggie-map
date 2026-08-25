<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace
    |--------------------------------------------------------------------------
    |
    | 每個 Project 的檔案都關在 {root}/{project_id}/ 底下（見 docs/implementation-plan.md
    | 第 9 節）。這個值是 WorkspaceGuard 唯一認可的邊界起點：Agent 給的任何路徑
    | realpath() 之後必須仍落在自己 Project 的目錄內，否則拒絕。
    | 預設放 repo 內的 workspace/，內容不進版控（見 .gitignore）。
    |
    */

    // 用 ?: 而不是 env() 的第二參數：.env.example 裡寫的是 `AI_OFFICE_WORKSPACE_ROOT=`，
    // 那是「已定義的空字串」，env() 的預設值不會生效，workspace_root 會變成 ''，
    // is_dir('') 為 false，readiness 檢查固定回 503。CI 是 cp .env.example .env，
    // 所以只有 CI 會炸、本機（沒有這一行）永遠是綠的。同一個坑先前在
    // EXTERNAL_API_OVERPASS_USER_AGENT 也踩過一次，見 docs/progress.md。
    'workspace_root' => env('AI_OFFICE_WORKSPACE_ROOT') ?: base_path('workspace'),

    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    |
    | 不把 Claude 寫死在 Agent 裡（規格第 4 節）。predefault 是 mock，理由跟既有
    | EXTERNAL_API_RESTAURANT_PROVIDER 一樣：開發／測試環境不該因為忘了設定就
    | 真的打出去燒 token。未知值要 throw，不能靜默退回 mock——否則設定打錯字時
    | 看起來一切正常，實際上一個字都沒送到 Claude。
    |
    | API key 只從 env 讀，不進資料庫、不寫進 log（規格第 54 節）。
    |
    */

    'llm' => [
        'default_provider' => env('AI_OFFICE_LLM_PROVIDER', 'mock'),
        'providers' => [
            'claude' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'model' => env('AI_OFFICE_CLAUDE_MODEL', 'claude-opus-5'),
                // 非串流請求的輸出上限。壓太低會讓回覆在句子中間被截斷、
                // 迫使整輪重來，比多給一點額度貴。
                'max_tokens' => (int) env('AI_OFFICE_CLAUDE_MAX_TOKENS', 16000),
                'timeout' => (int) env('AI_OFFICE_LLM_TIMEOUT', 120),
            ],
            'mock' => [],
        ],

        /*
        | 每百萬 token 的美元單價，TokenUsageService 用它估算成本。
        |
        | 放設定檔不寫在程式裡：價格會變、也會有新模型，改價不該動到程式碼。
        | 找不到對應模型時估成 0——寧可少報，也不要在成本報表裡放一個沒有來源的數字。
        */
        'pricing' => [
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
            'mock-1' => ['input' => 0, 'output' => 0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Loop 上限
    |--------------------------------------------------------------------------
    |
    | 規格第 26 節：必須擋住無限迴圈、工具呼叫爆炸、token 燒光、遞迴生任務。
    | 這些是硬上限，AgentRuntime 撞到就中止並把 Task 標成 failed，不是警告而已。
    |
    */

    'limits' => [
        'max_agent_steps' => (int) env('AI_OFFICE_MAX_AGENT_STEPS', 25),
        'max_tool_calls' => (int) env('AI_OFFICE_MAX_TOOL_CALLS', 50),
        'max_retries' => (int) env('AI_OFFICE_MAX_RETRIES', 3),
        'max_token_budget' => (int) env('AI_OFFICE_MAX_TOKEN_BUDGET', 200000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | 規格第 43 節：Terminal／Docker 執行必須在沙箱裡。第一版沙箱還沒實作，
    | 所以這裡的語意是「要求沙箱」而不是「已經有沙箱」：SANDBOX_ENABLED=true
    | 而 SandboxManager 尚未就緒時，TerminalTool 必須直接拒絕執行，不可以退回
    | 在 host 上跑——寧可功能缺席，不可假裝安全。
    |
    */

    'sandbox' => [
        'enabled' => (bool) env('AI_OFFICE_SANDBOX_ENABLED', true),
        'timeout_seconds' => (int) env('AI_OFFICE_SANDBOX_TIMEOUT', 60),
        'memory_limit_mb' => (int) env('AI_OFFICE_SANDBOX_MEMORY_MB', 512),
        'cpu_limit' => env('AI_OFFICE_SANDBOX_CPU_LIMIT', '1.0'),
        'network' => env('AI_OFFICE_SANDBOX_NETWORK', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | AI Office 的工作跟餐廳領域的 Job（評分／評分重算）分開排隊，避免一個
    | 燒很久的 Agent loop 把餐廳評分重算卡住。
    |
    */

    'queue' => env('AI_OFFICE_QUEUE', 'ai-office'),

    /*
    |--------------------------------------------------------------------------
    | Planner（CEO 拆任務）
    |--------------------------------------------------------------------------
    |
    | 規格第 28 節：CEO 必須輸出通過 schema 驗證的 JSON，禁止把自然語言當 Task。
    | 角色白名單、重試次數、規劃用的 Agent role 全部放這裡——CeoPlanner 不寫死
    | `ceo` 或 `backend`。
    |
    */

    'planner' => [
        'agent_role' => env('AI_OFFICE_PLANNER_ROLE', 'ceo'),
        'max_attempts' => (int) env('AI_OFFICE_PLANNER_MAX_ATTEMPTS', 3),
        'assignable_roles' => ['frontend', 'backend', 'automation', 'qa', 'design', 'devops'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs
    |--------------------------------------------------------------------------
    |
    | LLM 單次 timeout 見 llm.providers.claude.timeout。Job timeout 必須比它長，
    | 否則 worker 會先砍掉還在等模型的 process。tries=1：領域層的重試走
    | RetryFailedTaskJob，不要跟 Laravel 的 job retry 疊加把 retry_count 算亂。
    |
    */

    'jobs' => [
        'timeout' => (int) env('AI_OFFICE_JOB_TIMEOUT', 300),
        'tries' => 1,
        'retry_delay_seconds' => (int) env('AI_OFFICE_RETRY_DELAY', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools（規格第 16～20、22 節）
    |--------------------------------------------------------------------------
    |
    | 風險等級、白名單、禁止關鍵字全部放這裡。工具類別只負責執行與讀設定，
    | 不寫死 `if ($cmd === 'rm -rf /')` 或 `if ($sql starts with SELECT)`。
    |
    */

    'tools' => [
        'max_output_bytes' => (int) env('AI_OFFICE_TOOL_MAX_OUTPUT', 32_000),

        'file' => [
            'max_read_bytes' => (int) env('AI_OFFICE_FILE_MAX_READ', 512_000),
            'max_write_bytes' => (int) env('AI_OFFICE_FILE_MAX_WRITE', 1_048_576),
            'max_search_results' => 50,
            'actions' => [
                'read_file' => ['risk' => 'low'],
                'list_files' => ['risk' => 'low'],
                'search_files' => ['risk' => 'low'],
                'write_file' => ['risk' => 'medium'],
            ],
        ],

        'git' => [
            'protected_branches' => ['main', 'master'],
            // Phase 5 還沒有沙箱內的 deploy key；關掉 SSH，避免用到 host 的 ~/.ssh。
            'ssh_command' => env('AI_OFFICE_GIT_SSH_COMMAND', 'false'),
            'actions' => [
                'git_status' => ['risk' => 'low'],
                'git_diff' => ['risk' => 'low'],
                'git_log' => ['risk' => 'low'],
                'git_branch' => ['risk' => 'low'],
                'git_checkout' => ['risk' => 'medium'],
                'git_add' => ['risk' => 'medium'],
                'git_commit' => ['risk' => 'medium'],
                'git_push' => ['risk' => 'high'],
            ],
        ],

        'terminal' => [
            'actions' => [
                'execute_command' => ['risk' => 'medium'],
            ],
            // 指令必須完全等於某一項，或以其後接一個空白當前綴。
            'allowlist' => [
                'php artisan test',
                'php artisan migrate',
                'php artisan pint',
                'phpunit',
                'vendor/bin/pint',
                'vendor/bin/phpstan',
                'composer test',
                'npm test',
                'npm run build',
                'ls',
                'cat',
                'head',
                'tail',
                'wc',
                'echo',
            ],
            // 即使被加進 allowlist 也硬擋（規格第 18 節）。
            'denylist_patterns' => [
                '/rm\s+-rf\s+\//',
                '/\bshutdown\b/i',
                '/\breboot\b/i',
                '/\bsudo\b/i',
                '/\bmkfs\b/i',
                '/\.ssh\b/',
                '/id_rsa/',
                '/docker\.sock/',
                '/:\(\)\s*\{/',
            ],
            'denied_metacharacters' => [';', '|', '&', '`', '$(', "\n", "\r", '>', '<'],
        ],

        'docker' => [
            'actions' => [
                'docker_build' => ['risk' => 'medium'],
                'docker_run' => ['risk' => 'medium'],
                'docker_logs' => ['risk' => 'medium'],
                'docker_stop' => ['risk' => 'medium'],
            ],
            // {id} 會被換成專案 id。Agent 只能動自己專案的 image／container。
            'name_pattern' => '/^ai-office-project-{id}(-[a-z0-9][a-z0-9-]*)?$/',
            'denied_substrings' => [
                'docker.sock',
                '--privileged',
                'network=host',
                '--pid=host',
                '--network=host',
                '/:/',
                ':/:',
            ],
        ],

        'database' => [
            'actions' => [
                'database_read' => ['risk' => 'low'],
            ],
            'allowed_environments' => ['local', 'testing'],
            'allowed_prefixes' => ['select', 'explain', 'describe', 'desc'],
            'denied_keywords' => [
                'drop', 'truncate', 'delete', 'update', 'alter', 'insert',
                'replace', 'grant', 'revoke', 'load', 'outfile', 'dumpfile',
                'into', 'handler', 'lock', 'unlock', 'call', 'do',
            ],
            'max_rows' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Memory（規格第 41 節）
    |--------------------------------------------------------------------------
    |
    | Agent 每跑完一個任務就把「結果」或「失敗原因」記下來，下次執行時把重要度最高的
    | 幾則放進 prompt。上限存在的理由很實際：記憶是要塞進 context 的，無上限地塞
    | 等於每一次請求都在燒 token（而且是燒在舊資訊上）。
    |
    |   recall_limit        每次執行最多放幾則進 prompt
    |   max_content_length  單則記憶存多長，超過截斷（不是拒絕寫入）
    |   importance          寫入時的預設重要度：失敗比成功值得記，所以分數高
    |
    */

    'memory' => [
        'enabled' => (bool) env('AI_OFFICE_MEMORY_ENABLED', true),
        'recall_limit' => (int) env('AI_OFFICE_MEMORY_RECALL_LIMIT', 5),
        'max_content_length' => (int) env('AI_OFFICE_MEMORY_MAX_LENGTH', 1000),
        'importance' => [
            'task_result' => 5,
            'error_pattern' => 7,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events / SSE（規格第 35～36 節）
    |--------------------------------------------------------------------------
    |
    | `ai_office_activities` 是唯一事件來源，SSE 用 id 做增量拉取（表上有
    | `(project_id, id)` 索引）。三個上限都是為了「長連線不要吃光 PHP-FPM worker」
    | 這個風險（見 docs/implementation-plan.md 第 13 節）：
    |
    |   max_duration_seconds     單一連線活多久，時間到主動關閉讓瀏覽器重連
    |   max_connections_per_user 同一使用者同時能開幾條，超過回 429 不是排隊
    |   poll_interval_ms         每輪查資料庫的間隔；調小會變成打 DB 的迴圈
    |
    | ticket_ttl_seconds：EventSource 不能帶 Authorization 標頭，所以前端先用
    | Bearer token 換一張一次性、綁使用者＋專案的短期票，才用它開串流。
    | 不把 Sanctum token 放進網址——網址會進 access log 與瀏覽器歷史。
    |
    */

    'events' => [
        'poll_interval_ms' => (int) env('AI_OFFICE_SSE_POLL_MS', 1000),
        'max_duration_seconds' => (int) env('AI_OFFICE_SSE_MAX_SECONDS', 60),
        'max_connections_per_user' => (int) env('AI_OFFICE_SSE_MAX_CONNECTIONS', 3),
        'batch_size' => (int) env('AI_OFFICE_SSE_BATCH', 100),
        'ticket_ttl_seconds' => (int) env('AI_OFFICE_SSE_TICKET_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals（規格第 22～24 節）
    |--------------------------------------------------------------------------
    |
    | 判定順序：Agent 權限 deny → 立刻拒絕；其餘再看風險門檻。
    | threshold 及以上（含）即使權限是 allow 也要人工核准。`off` 只保留
    | critical 必核准（規格第 24 節改門檻也改不掉）。invalid 值回退成 high。
    |
    */

    'approvals' => [
        'threshold' => env('AI_OFFICE_APPROVAL_THRESHOLD', 'high'),
        'ttl_hours' => (int) env('AI_OFFICE_APPROVAL_TTL_HOURS', 24),
        // 還沒有對應 Tool 實作的能力（deploy_*）仍要能排出風險，不能當 low。
        'ability_risk' => [
            'deploy_staging' => 'high',
            'deploy_production' => 'critical',
        ],
    ],

];
