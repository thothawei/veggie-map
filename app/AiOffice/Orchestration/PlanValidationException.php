<?php

namespace App\AiOffice\Orchestration;

use RuntimeException;

/**
 * CEO 的輸出沒通過 PlanSchema。訊息給人看，errors() 給測試與 activity payload 看。
 */
class PlanValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('CEO 規劃未通過 schema 驗證：'.implode('；', $errors));
    }
}
