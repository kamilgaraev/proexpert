<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AssistantExtendedDomainRegistryDispatchTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        $this->setDispatch(null);
        parent::tearDown();
    }

    public function test_cached_dispatch_matches_the_original_helper_matrix_and_order(): void
    {
        $registry = new ReflectionClass(AssistantExtendedDomainRegistry::class);
        $constant = $registry->getReflectionConstant('HELPERS');
        $expected = [];
        foreach ($constant->getValue() as $helper) {
            if (! class_exists($helper) || ! method_exists($helper, 'entityDefinitions') || ! method_exists($helper, 'applyActorScope')) {
                continue;
            }
            $definitions = $helper::entityDefinitions();
            foreach ($definitions as $type => $definition) {
                if (isset($definitions[$type])) {
                    $expected[$type][] = $helper;
                }
            }
        }

        $dispatch = $registry->getMethod('actorScopeDispatch')->invoke(null);

        $this->assertSame($expected, $dispatch);
        $this->assertSame($dispatch, $registry->getMethod('actorScopeDispatch')->invoke(null));
        foreach ($dispatch as $helpers) {
            $this->assertContainsOnly('string', $helpers);
        }
    }

    public function test_real_actor_scope_helper_receives_the_current_actor_and_unknown_type_stays_allowed(): void
    {
        $actor = new User;
        $actor->id = 246;
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('getTable')->once()->andReturn('site_request_groups');
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('getModel')->once()->andReturn($model);
        $query->shouldReceive('visibleToActor')->once()->with(246)->andReturnSelf();
        $authorization = Mockery::mock(AuthorizationService::class);
        $policy = (new ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();

        $this->assertTrue(AssistantExtendedDomainRegistry::applyActorScopes('site_request_group', $query, $actor, 38, $authorization, $policy));

        $unknownQuery = Mockery::mock(Builder::class);
        $this->assertTrue(AssistantExtendedDomainRegistry::applyActorScopes('unknown_entity_type', $unknownQuery, $actor, 38, $authorization, $policy));
        $unknownQuery->shouldNotHaveReceived('getModel');
    }

    public function test_helper_failure_still_fails_closed(): void
    {
        $actor = new User;
        $actor->id = 246;
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('getTable')->once()->andReturn('site_request_groups');
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('getModel')->once()->andReturn($model);
        $query->shouldReceive('visibleToActor')->once()->with(246)->andThrow(new RuntimeException('denied'));
        $authorization = Mockery::mock(AuthorizationService::class);
        $policy = (new ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();

        $this->assertFalse(AssistantExtendedDomainRegistry::applyActorScopes('site_request_group', $query, $actor, 38, $authorization, $policy));
    }

    private function setDispatch(?array $value): void
    {
        $property = (new ReflectionClass(AssistantExtendedDomainRegistry::class))->getProperty('actorScopeDispatch');
        $property->setValue(null, $value);
    }
}
