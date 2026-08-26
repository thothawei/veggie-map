<?php

namespace App\AiOffice\Runtime;

use RuntimeException;

/**
 * 任務已被取消，不該進入 AgentRuntime。
 *
 * 正常路徑走不到這裡（ExecuteTaskJob 開頭就會擋掉），所以它是「不該發生」
 * 的訊號而不是一般流程分支——會留在 failed job 裡讓人看見，不是靜默 return。
 */
class TaskCancelledException extends RuntimeException {}
