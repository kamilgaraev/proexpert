<?php

declare(strict_types=1);

namespace Tests\Unit\AccessRecertification;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationException;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationRevocation;
use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\Domain\Authorization\Services\AuthorizationService;
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
    public function only_assigned_executor_can_complete_revocation(): void
    {
        $service = $this->service();
        $revocation = new AccessRecertificationRevocation;
        $revocation->forceFill([
            'organization_id' => 7,
            'status' => 'pending',
            'executor_user_id' => 12,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revocation_executor_required');

        $service->completeRevocation($revocation, 7, $this->actor(), []);
    }

    #[Test]
    public function exception_requester_cannot_decide_own_exception(): void
    {
        $service = $this->service();
        $exception = $this->exception(requestedByUserId: 11, subjectUserId: 12);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exception_independent_approver_required');

        $service->decideException($exception, 7, $this->actor(), 'approved', 'Проверено независимо');
    }

    #[Test]
    public function access_subject_cannot_decide_own_exception(): void
    {
        $service = $this->service();
        $exception = $this->exception(requestedByUserId: 13, subjectUserId: 11);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exception_independent_approver_required');

        $service->decideException($exception, 7, $this->actor(), 'rejected', 'Исключение отклонено');
    }

    #[Test]
    public function revocation_executor_must_have_execution_permission(): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects($this->once())
            ->method('can')
            ->with(
                $this->callback(fn (User $user): bool => (int) $user->id === 12),
                'access_recertification.revocations.execute',
                ['organization_id' => 7],
            )
            ->willReturn(false);

        $service = $this->service();
        (new ReflectionClass($service))->getProperty('authorization')->setValue($service, $authorization);

        $executor = new User();
        $executor->forceFill(['id' => 12]);
        $method = (new ReflectionClass($service))->getMethod('assertRevocationExecutorCanExecute');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revocation_executor_permission_required');

        $method->invoke($service, $executor, 7);
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

    private function exception(int $requestedByUserId, int $subjectUserId): AccessRecertificationException
    {
        $item = new AccessRecertificationItem;
        $item->forceFill(['subject_user_id' => $subjectUserId]);

        $exception = new AccessRecertificationException;
        $exception->forceFill([
            'organization_id' => 7,
            'status' => 'requested',
            'requested_by_user_id' => $requestedByUserId,
        ]);
        $exception->setRelation('item', $item);

        return $exception;
    }
}
