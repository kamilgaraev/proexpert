<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationRevocation;
use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\Models\User;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AccessRecertificationServiceWorkflowGuardTest extends TestCase
{
    #[Test]
    public function draft_campaign_cannot_be_completed(): void
    {
        $service = $this->service();
        $campaign = new AccessRecertificationCampaign();
        $campaign->forceFill(['organization_id' => 7, 'status' => 'draft']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('campaign_cannot_complete');

        $service->completeCampaign($campaign, 7, $this->actor());
    }

    #[Test]
    public function cancelled_revocation_cannot_be_completed(): void
    {
        $service = $this->service();
        $revocation = new AccessRecertificationRevocation();
        $revocation->forceFill(['organization_id' => 7, 'status' => 'cancelled']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revocation_cannot_complete');

        $service->completeRevocation($revocation, 7, $this->actor(), []);
    }

    #[Test]
    public function risk_mode_controls_campaign_scope(): void
    {
        $service = $this->service();
        $method = (new ReflectionClass($service))->getMethod('campaignAllowsRisk');
        $method->setAccessible(true);

        $all = new AccessRecertificationCampaign();
        $all->forceFill(['risk_mode' => 'all', 'scope' => []]);
        $this->assertTrue($method->invoke($service, $all, 'low'));

        $highRiskOnly = new AccessRecertificationCampaign();
        $highRiskOnly->forceFill(['risk_mode' => 'high_risk_only', 'scope' => []]);
        $this->assertFalse($method->invoke($service, $highRiskOnly, 'medium'));
        $this->assertTrue($method->invoke($service, $highRiskOnly, 'critical'));

        $riskBased = new AccessRecertificationCampaign();
        $riskBased->forceFill(['risk_mode' => 'risk_based', 'scope' => []]);
        $this->assertFalse($method->invoke($service, $riskBased, 'low'));
        $this->assertTrue($method->invoke($service, $riskBased, 'medium'));

        $customScope = new AccessRecertificationCampaign();
        $customScope->forceFill(['risk_mode' => 'risk_based', 'scope' => ['risk_levels' => ['critical']]]);
        $this->assertFalse($method->invoke($service, $customScope, 'high'));
        $this->assertTrue($method->invoke($service, $customScope, 'critical'));
    }

    private function service(): AccessRecertificationService
    {
        return (new ReflectionClass(AccessRecertificationService::class))->newInstanceWithoutConstructor();
    }

    private function actor(): User
    {
        $actor = new User();
        $actor->forceFill(['id' => 11]);

        return $actor;
    }
}
