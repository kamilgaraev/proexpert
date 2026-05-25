<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRequests;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestWorkflowService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SiteRequestWorkflowContractTest extends TestCase
{
    public function test_pending_default_transitions_include_approved(): void
    {
        $defaultTransitions = SiteRequestStatusEnum::getDefaultTransitions();

        $this->assertArrayHasKey(SiteRequestStatusEnum::PENDING->value, $defaultTransitions);
        $this->assertContains(
            SiteRequestStatusEnum::APPROVED->value,
            $defaultTransitions[SiteRequestStatusEnum::PENDING->value]
        );
    }

    public function test_default_permission_for_transition_contract(): void
    {
        $service = new SiteRequestWorkflowService();
        $method = new ReflectionMethod($service, 'getDefaultPermissionForTransition');
        $method->setAccessible(true);

        $this->assertSame(
            'site_requests.approve',
            $method->invoke($service, 'pending', 'approved')
        );
        $this->assertSame(
            'site_requests.reject',
            $method->invoke($service, 'pending', 'rejected')
        );
        $this->assertSame(
            'site_requests.change_status',
            $method->invoke($service, 'approved', 'in_progress')
        );
    }
}
