<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class AssistantPreparationCheckpointReentrancyTest extends TestCase
{
    use UsesAssistantUnitTranslations { tearDown as private translationsTearDown; }

    private ?ConnectionResolverInterface $previousResolver = null;

    private int $freshRequestReads = 0;

    private int $membershipQueries = 0;

    private bool $allowed = true;

    private bool $expireDuringFreshRead = false;

    private AssistantRequest $request;

    private User $actor;

    private AssistantDataAccessPolicy $policy;

    private AssistantRequestExecutionContext $execution;

    private AIAssistantService $service;

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        $this->translationsTearDown();
    }

    public function test_real_preparation_callback_does_not_reenter_lifecycle_authorization(): void
    {
        $this->prepareActualChain();
        $readExecutions = 0;

        $result = $this->readPhase(function () use (&$readExecutions): array {
            $readExecutions++;

            return ['allowed' => $this->policy->canReadDomain($this->actor, 38, 'assistant')];
        });

        $this->assertSame(['allowed' => true], $result);
        $this->assertSame(1, $readExecutions);
        $this->assertGreaterThan(0, $this->membershipQueries);
        $this->assertGreaterThan(0, $this->freshRequestReads);
        $this->assertLessThan(32, $this->freshRequestReads);
    }

    public function test_cancellation_during_preparation_still_stops_outer_fresh_checkpoint(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestCancelled::class);

        $this->readPhase(function (): array {
            $this->policy->canReadDomain($this->actor, 38, 'assistant');
            $this->request->cancel_requested_at = now();

            return [];
        });
    }

    public function test_revoked_permission_is_not_hidden_by_reentrancy_guard(): void
    {
        $this->prepareActualChain();
        $this->expectException(AuthorizationException::class);

        $this->readPhase(function (): array {
            $this->policy->canReadDomain($this->actor, 38, 'assistant');
            $this->allowed = false;

            return [];
        });
    }

    public function test_expired_deadline_is_checked_inside_the_nested_policy_callback(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestDeadlineExceeded::class);

        $this->readPhase(function (): array {
            $this->expireDuringFreshRead = true;
            $this->policy->canReadDomain($this->actor, 38, 'assistant');

            return [];
        });
    }

    public function test_failed_checkpoint_resets_guard_and_checks_fresh_request_again(): void
    {
        $this->prepareActualChain();
        $this->request->cancel_requested_at = now();
        try {
            $this->execution->assertCanContinue();
            $this->fail('Cancellation must interrupt the checkpoint.');
        } catch (AssistantRequestCancelled) {
            $readsAfterCancellation = $this->freshRequestReads;
        }

        $this->request->cancel_requested_at = null;
        $this->execution->assertCanContinue();
        $this->assertGreaterThan($readsAfterCancellation, $this->freshRequestReads);

        $this->allowed = false;
        $this->expectException(AuthorizationException::class);
        $this->execution->assertCanContinue();
    }

    private function readPhase(callable $read): array
    {
        return (new ReflectionMethod(AIAssistantService::class, 'readPhase'))->invoke($this->service, $read, $this->actor, 38);
    }

    private function prepareActualChain(): void
    {
        $this->previousResolver = Model::getConnectionResolver();
        $connection = $this->getMockBuilder(Connection::class)->setConstructorArgs([null, 'controlled', '', ['driver' => 'controlled']])
            ->onlyMethods(['select'])->getMock();
        $connection->method('select')->willReturnCallback(function (string $query): array {
            if (str_contains($query, 'ai_assistant_requests')) {
                $this->freshRequestReads++;
                if ($this->freshRequestReads > 64) {
                    throw new RuntimeException('recursive_lifecycle_checkpoint_safety_limit');
                }
                if ($this->expireDuringFreshRead) {
                    (new ReflectionProperty(AssistantRequestExecutionContext::class, 'deadlineNanoseconds'))->setValue($this->execution, hrtime(true) - 1);
                }

                return [(object) $this->request->getAttributes()];
            }
            $this->membershipQueries++;

            return [(object) ['exists' => true]];
        });
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
        app()->instance('db', $resolver);
        Redis::swap(new class
        {
            public function connection(?string $name = null): object
            {
                return new class
                {
                    public function eval(mixed ...$arguments): int
                    {
                        return 1;
                    }
                };
            }
        });

        $this->actor = $this->createPartialMock(User::class, ['refresh', 'belongsToOrganization']);
        $this->actor->method('refresh')->willReturnSelf();
        $this->actor->method('belongsToOrganization')->willReturn(true);
        $this->actor->forceFill(['id' => 39, 'current_organization_id' => 38, 'is_active' => true]);
        $this->request = new AssistantRequest;
        $this->request->forceFill(['id' => 14, 'organization_id' => 38, 'user_id' => 39, 'conversation_id' => null,
            'status' => 'running', 'stage' => 'reading', 'cancel_requested_at' => null, 'lease_expires_at' => now()->addMinutes(8), 'created_at' => now()]);
        $this->request->exists = true;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $authorization->method('canCurrent')->willReturnCallback(fn (): bool => $this->allowed);
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect([new Module(['slug' => 'ai-assistant'])]));
        $this->policy = new AssistantDataAccessPolicy($authorization, $this->createMock(UserProjectAccessService::class), $modules);
        app()->instance(AssistantDataAccessPolicy::class, $this->policy);
        $lifecycle = new AssistantRequestLifecycle((new ReflectionClass(AICreditService::class))->newInstanceWithoutConstructor(), new AIPermissionChecker($authorization),
            $this->createMock(ConversationManager::class), $this->policy);
        $this->execution = new AssistantRequestExecutionContext($lifecycle, $this->request, $this->actor, 30_000);
        app()->instance(AssistantRequestExecutionContext::class, $this->execution);
        app()->instance(AssistantReadConcurrencyLimiter::class, new AssistantReadConcurrencyLimiter);
        $this->service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AIAssistantService::class, 'dataAccess'))->setValue($this->service, $this->policy);
    }
}
