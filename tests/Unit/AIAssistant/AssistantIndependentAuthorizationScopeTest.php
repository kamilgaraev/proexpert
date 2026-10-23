<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\User;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class AssistantIndependentAuthorizationScopeTest extends TestCase
{
    use UsesAssistantReportUnitAccess;

    public function test_scope_preserves_dependencies_and_surface_without_inheriting_active_state(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $policy->setTrustedSurface(KnowledgeSurface::MOBILE);
        $actor = $this->actor();
        $checkpoints = 0;
        $policy->withCurrentChecks($actor, 1, function () use ($policy, $actor, &$checkpoints): void {
            $this->assertTrue($policy->canReadDomain($actor, 1, 'assistant'));
            (new ReflectionMethod($policy, 'compileAcl'))->invoke($policy, $actor, 1, function () use ($policy, $actor, &$checkpoints): null {
                $compiler = (new ReflectionProperty($policy, 'aclCompiler'))->getValue($policy);
                $scope = $policy->independentAuthorizationScope();
                $this->assertNotSame($policy, $scope);
                $this->assertSame(KnowledgeSurface::MOBILE, $scope->trustedSurface());
                foreach (['authorization', 'projectAccess', 'modules', 'sourceQueries', 'schemaMetadata'] as $name) {
                    $property = new ReflectionProperty($policy, $name);
                    $this->assertSame($property->getValue($policy), $property->getValue($scope));
                }
                $before = $checkpoints;
                $this->assertTrue($scope->withCurrentChecks($actor, 1, fn (): bool => $scope->canReadDomain($actor, 1, 'assistant'), true));
                $this->assertSame($before, $checkpoints);
                $this->assertSame($compiler, (new ReflectionProperty($policy, 'aclCompiler'))->getValue($policy));
                $this->assertNull((new ReflectionProperty($scope, 'aclCompiler'))->getValue($scope));
                return null;
            });
        }, true, static function () use (&$checkpoints): void { $checkpoints++; });
        $this->assertGreaterThan(0, $checkpoints);
    }

    public function test_assistant_checks_inside_compilation_observe_revocation_with_both_fresh_values(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $actor = $this->actor();
        $checker = app(AIPermissionChecker::class);
        $policy->withCurrentChecks($actor, 1, function () use ($policy, $actor, $checker): void {
            $this->assertTrue($policy->canReadDomain($actor, 1, 'assistant'));
            (new ReflectionMethod($policy, 'compileAcl'))->invoke($policy, $actor, 1, function () use ($actor, $checker): null {
                $this->assertTrue($checker->canUseAssistant($actor, 1));
                $this->grantReportUnitPermissions($actor, []);
                $this->assertFalse($checker->canUseAssistant($actor, 1));
                $this->assertFalse($checker->canUseAssistant($actor, 1, false));
                return null;
            });
        }, true);
    }

    public function test_direct_fresh_batch_during_compilation_remains_forbidden(): void
    {
        $policy = app(AssistantDataAccessPolicy::class);
        $actor = $this->actor();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('assistant_authorization_batch_during_query_compilation');
        (new ReflectionMethod($policy, 'compileAcl'))->invoke($policy, $actor, 1,
            fn (): mixed => $policy->withCurrentChecks($actor, 1, static fn (): bool => true, true));
    }

    private function actor(): User
    {
        $actor = $this->createPartialMock(User::class, ['belongsToOrganization']);
        $actor->forceFill(['id' => 39, 'current_organization_id' => 1, 'is_active' => true]);
        $actor->method('belongsToOrganization')->willReturn(true);
        $this->grantReportUnitPermissions($actor, ['ai_assistant.chat']);
        return $actor;
    }
}
