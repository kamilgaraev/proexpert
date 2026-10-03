<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AssistantPolicyRecursionGuardTest extends TestCase
{
    public function test_revisited_type_and_excessive_depth_fail_closed_without_loading_a_database(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $authorization->shouldNotReceive('canCurrent');
        $policy = new AssistantDataAccessPolicy($authorization, new UserProjectAccessService);
        $path = new ReflectionProperty($policy, 'entityQueryPath');
        $path->setValue($policy, ['workforce_department']);
        $this->assertNull($policy->entityQuery(new User, 1, 'workforce_department'));
        $this->assertSame(['workforce_department'], $path->getValue($policy));
        $path->setValue($policy, array_fill(0, 16, 'ancestor'));
        $this->assertNull($policy->entityQuery(new User, 1, 'workforce_export_package'));
        $path->setValue($policy, []);
        $this->assertNull($policy->entityQuery(new User, 1, 'unknown_entity'));
        $this->assertSame([], $path->getValue($policy));
        Mockery::close();
    }
}
