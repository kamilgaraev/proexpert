<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
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
use Closure;
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

    private int $maximumFreshRequestReads = 64;

    private int $membershipQueries = 0;

    private bool $allowed = true;

    private array $deniedPermissions = [];

    private bool $expireDuringFreshRead = false;

    private int $permissionChecks = 0;

    private ?int $permissionInterruptionAt = null;

    private ?Closure $permissionInterruption = null;

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

    public function test_static_catalog_does_not_repeat_global_authorization_for_each_type(): void
    {
        $this->prepareActualChain();
        $this->maximumFreshRequestReads = 10000;
        $catalogChecks = 0;
        $catalog = $this->readPhase(function () use (&$catalogChecks): array {
            $before = $this->freshRequestReads;
            $result = (new ReflectionMethod(AIAssistantService::class, 'buildDomainCapabilityHints'))->invoke($this->service, []);
            $catalogChecks = $this->freshRequestReads - $before;

            return $result;
        });

        $this->assertGreaterThan(50, count($catalog['domains']));
        $this->assertLessThanOrEqual(10, $catalogChecks);
    }

    public function test_cancellation_during_catalog_is_rejected_by_fresh_final_checkpoint(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestCancelled::class);
        $this->catalogWithInterruption(function (): void {
            $this->request->cancel_requested_at = now();
        });
    }

    public function test_permission_revoked_during_catalog_cannot_return_partial_metadata(): void
    {
        $this->prepareActualChain();
        $this->expectException(AuthorizationException::class);
        $this->catalogWithInterruption(function (): void {
            $this->allowed = false;
        });
    }

    public function test_catalog_checkpoint_preserves_read_phase_deadline(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestDeadlineExceeded::class);
        $this->catalogWithInterruption(function (): void {
            (new ReflectionProperty(AssistantRequestExecutionContext::class, 'operationDeadlines'))->setValue($this->execution, [hrtime(true) - 1]);
        });
    }

    public function test_failed_catalog_does_not_masquerade_as_empty_metadata(): void
    {
        $this->prepareActualChain();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('controlled_catalog_failure');
        $this->catalogWithInterruption(static function (): void {
            throw new RuntimeException('controlled_catalog_failure');
        });
    }

    public function test_tool_definitions_do_not_repeat_global_checks_for_metadata_decisions(): void
    {
        $this->prepareActualChain();
        $this->maximumFreshRequestReads = 10000;
        $definitionChecks = 0;
        $definitions = $this->readPhase(function () use (&$definitionChecks): array {
            $before = $this->freshRequestReads;
            $result = $this->toolDefinitions();
            $definitionChecks = $this->freshRequestReads - $before;

            return $result;
        });

        $names = array_column(array_column($definitions, 'function'), 'name');
        $this->assertContains('assistant_domain_discover_capabilities', $names);
        $this->assertContains('get_material_stock', $names);
        $this->assertLessThanOrEqual(4, $definitionChecks);
    }

    public function test_tool_definitions_remove_tools_without_current_domain_permission(): void
    {
        $this->prepareActualChain();
        $this->deniedPermissions = ['warehouse.view'];
        $definitions = $this->readPhase(fn (): array => $this->toolDefinitions());
        $names = array_column(array_column($definitions, 'function'), 'name');

        $this->assertContains('assistant_domain_discover_capabilities', $names);
        $this->assertNotContains('get_material_stock', $names);
    }

    public function test_tool_definition_cancellation_stops_metadata_at_the_final_fresh_check(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestCancelled::class);
        $this->toolDefinitionsWithInterruption(function (): void {
            $this->request->cancel_requested_at = now();
        });
    }

    public function test_tool_definition_permission_revocation_cannot_return_advertised_tools(): void
    {
        $this->prepareActualChain();
        $this->expectException(AuthorizationException::class);
        $this->toolDefinitionsWithInterruption(function (): void {
            $this->allowed = false;
        });
    }

    public function test_tool_definitions_check_effective_read_phase_deadline_inside_metadata(): void
    {
        $this->prepareActualChain();
        $this->expectException(AssistantRequestDeadlineExceeded::class);
        $this->toolDefinitionsWithInterruption(function (): void {
            (new ReflectionProperty(AssistantRequestExecutionContext::class, 'operationDeadlines'))->setValue($this->execution, [hrtime(true) - 1]);
        });
    }

    public function test_executing_an_advertised_tool_rechecks_current_permission(): void
    {
        $this->prepareActualChain();
        $definitions = $this->readPhase(fn (): array => $this->toolDefinitions());
        $this->assertContains('get_material_stock', array_column(array_column($definitions, 'function'), 'name'));
        $this->deniedPermissions = ['warehouse.view'];
        $before = $this->permissionChecks;
        $checker = (new ReflectionProperty(AIAssistantService::class, 'permissionChecker'))->getValue($this->service);

        $this->assertFalse($checker->canExecuteTool($this->actor, 'get_material_stock'));
        $this->assertGreaterThan($before, $this->permissionChecks);
    }

    private function toolDefinitions(): array
    {
        return (new ReflectionMethod(AIAssistantService::class, 'resolveToolDefinitions'))->invoke($this->service, ['task_type' => 'find']);
    }

    private function toolDefinitionsWithInterruption(Closure $interrupt): void
    {
        $this->readPhase(function () use ($interrupt): array {
            $this->permissionInterruptionAt = $this->permissionChecks + 4;
            $this->permissionInterruption = $interrupt;

            return $this->toolDefinitions();
        });
    }

    private function catalogWithInterruption(Closure $interrupt): void
    {
        $this->readPhase(function () use ($interrupt): array {
            $this->permissionInterruptionAt = $this->permissionChecks + 20;
            $this->permissionInterruption = $interrupt;

            return (new ReflectionMethod(AIAssistantService::class, 'buildDomainCapabilityHints'))->invoke($this->service, []);
        });
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
                if ($this->freshRequestReads > $this->maximumFreshRequestReads) {
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
        $authorization->method('canCurrent')->willReturnCallback(function (User $actor, string $permission): bool {
            $this->permissionChecks++;
            if ($this->permissionInterruption !== null && $this->permissionChecks >= $this->permissionInterruptionAt) {
                $interrupt = $this->permissionInterruption;
                $this->permissionInterruption = null;
                $interrupt();
            }

            return $this->allowed && ! in_array($permission, $this->deniedPermissions, true);
        });
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $moduleSlugs = array_unique(['ai-assistant', ...array_map(fn ($definition): string => $definition->module, $catalog->all())]);
        $modules->method('getEffectiveModules')->willReturn(collect(array_map(fn (string $slug): Module => new Module(['slug' => $slug]), $moduleSlugs)));
        $this->policy = new AssistantDataAccessPolicy($authorization, $this->createMock(UserProjectAccessService::class), $modules);
        app()->instance(AssistantDataAccessPolicy::class, $this->policy);
        $lifecycle = new AssistantRequestLifecycle((new ReflectionClass(AICreditService::class))->newInstanceWithoutConstructor(), new AIPermissionChecker($authorization),
            $this->createMock(ConversationManager::class), $this->policy);
        $this->execution = new AssistantRequestExecutionContext($lifecycle, $this->request, $this->actor, 30_000);
        app()->instance(AssistantRequestExecutionContext::class, $this->execution);
        app()->instance(AssistantReadConcurrencyLimiter::class, new AssistantReadConcurrencyLimiter);
        $this->service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AIAssistantService::class, 'dataAccess'))->setValue($this->service, $this->policy);
        (new ReflectionProperty(AIAssistantService::class, 'activeActor'))->setValue($this->service, $this->actor);
        (new ReflectionProperty(AIAssistantService::class, 'permissionChecker'))->setValue($this->service, new AIPermissionChecker($authorization));
        $registry = new AIToolRegistry;
        $registry->registerTool(new DiscoverAssistantDomainCapabilitiesTool($catalog, $this->policy, $authorization));
        $stockTool = $this->createMock(AIToolInterface::class);
        $stockTool->method('getName')->willReturn('get_material_stock');
        $stockTool->method('getDescription')->willReturn('Current material stock');
        $stockTool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $stockTool->expects($this->never())->method('execute');
        $registry->registerTool($stockTool);
        (new ReflectionProperty(AIAssistantService::class, 'toolRegistry'))->setValue($this->service, $registry);
    }
}
