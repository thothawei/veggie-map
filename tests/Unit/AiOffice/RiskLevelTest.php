<?php

namespace Tests\Unit\AiOffice;

use App\AiOffice\Security\RiskLevel;
use Tests\TestCase;

class RiskLevelTest extends TestCase
{
    public function test_high_meets_the_default_threshold(): void
    {
        $risk = new RiskLevel;

        $this->assertTrue($risk->requiresApproval('high'));
        $this->assertTrue($risk->requiresApproval('critical'));
        $this->assertFalse($risk->requiresApproval('medium'));
        $this->assertFalse($risk->requiresApproval('low'));
    }

    public function test_threshold_comes_from_config(): void
    {
        config(['ai_office.approvals.threshold' => 'critical']);

        $risk = new RiskLevel;
        $this->assertFalse($risk->requiresApproval('high'));
        $this->assertTrue($risk->requiresApproval('critical'));
    }

    public function test_off_still_forces_critical(): void
    {
        config(['ai_office.approvals.threshold' => 'off']);

        $risk = new RiskLevel;
        $this->assertFalse($risk->requiresApproval('high'));
        $this->assertTrue($risk->requiresApproval('critical'));
    }

    public function test_unknown_ability_defaults_to_critical(): void
    {
        $this->assertSame('critical', (new RiskLevel)->forAbility('explode_prod'));
        $this->assertSame('high', (new RiskLevel)->forAbility('git_push', 'high'));
    }

    public function test_ability_risk_comes_from_config(): void
    {
        $this->assertSame('critical', (new RiskLevel)->forAbility('deploy_production'));

        config(['ai_office.approvals.ability_risk.deploy_production' => 'medium']);
        $this->assertSame('medium', (new RiskLevel)->forAbility('deploy_production'));
    }
}
