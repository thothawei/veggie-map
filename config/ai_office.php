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

    'workspace_root' => env('AI_OFFICE_WORKSPACE_ROOT', base_path('workspace')),

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
                'model' => env('AI_OFFICE_CLAUDE_MODEL', 'claude-sonnet-5'),
                'timeout' => (int) env('AI_OFFICE_LLM_TIMEOUT', 120),
            ],
            'mock' => [],
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

];
