<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecutePublicCoreTestJob;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicCoreRuntimeBindingsTest extends TestCase
{
    protected function setUp(): void
    {
        self::createIsolatedApplication();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    public static function createIsolatedApplication(): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $translator = new Translator(new FileLoader(new Filesystem(), dirname(__DIR__, 4).'/lang'), 'ru');
        $app->instance('translator', $translator);
        $validator = new Factory($translator, $app);
        $app->instance('validator', $validator);
        $app->instance(ValidationFactoryContract::class, $validator);
        $app->instance('request', Request::create('/'));
        $app->bind('db', static function (): never { throw new LogicException('test_database_forbidden'); });
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(static fn ($data = [], $status = 200, $headers = [], $options = 0): JsonResponse =>
            new JsonResponse($data, $status, $headers, $options));
        $app->instance(ResponseFactory::class, $responses);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    public function testUnacceptedProducersKeepDefaultRuntimeUnavailableWithNoPublicCatalogOrModel(): void
    {
        $runtime = new PublicCoreAssistantRuntime();
        $viewer = Mockery::mock(User::class);
        $readiness = (new PublicCoreRuntimeResource($runtime->readiness($viewer, 37)))->resolve();
        self::assertSame('unavailable', $readiness['status']);
        self::assertFalse($readiness['model_enabled']);
        self::assertFalse($readiness['private_ready']);
        self::assertNull($readiness['actual_model']);
        self::assertSame([], $readiness['fixtures']);
        self::assertSame('blocked', $runtime->submit($viewer, 37, self::command())['status']);
        self::assertSame($runtime->poll($viewer, 37, 'request_'.str_repeat('a', 32)),
            $runtime->poll($viewer, 37, 'request_'.str_repeat('b', 32)));
        $this->expectExceptionMessage('runtime_not_activated');
        $runtime->dispatchOwnedRequest('request_'.str_repeat('a', 32));
    }

    #[DataProvider('forbiddenKeys')]
    public function testServiceRejectsForbiddenKeysOutsideHttpBeforeAnyProducerCall(string $key): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(true);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('submit');
        $service = new PublicCoreRequestService($permissions, $runtime);
        $this->expectException(ValidationException::class);
        $service->submit($viewer, 37, self::command() + [$key => 'PRIVATE']);
    }

    public static function forbiddenKeys(): array
    {
        return array_map(static fn (string $key): array => [$key],
            ['message', 'context', 'conversation_id', 'history', 'attachment_ids', 'actions', 'project_id', 'organization_id']);
    }

    public function testCurrentViewerAuthorizationIsEnforcedOnServicePollWithoutHttp(): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(false);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('poll');
        $this->expectException(AuthorizationException::class);
        (new PublicCoreRequestService($permissions, $runtime))->poll($viewer, 37, 'request_'.str_repeat('a', 32));
    }

    public function testMissingOrganizationAndNonOpaqueReferenceCannotReachProducer(): void
    {
        $viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->once()->with($viewer, 37, true)->andReturn(true);
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldNotReceive('readiness');
        $runtime->shouldNotReceive('poll');
        $service = new PublicCoreRequestService($permissions, $runtime);
        try {
            $service->readiness($viewer, 0);
            self::fail('Missing organization must fail');
        } catch (AuthorizationException) {
        }
        $this->expectException(ValidationException::class);
        $service->poll($viewer, 37, '../private');
    }

    public function testPublicResourceExcludesRealEntityNavigationAndPlanText(): void
    {
        $result = self::completed() + ['private_map' => 'PRIVATE'];
        $result['sources'][0]['entity_id'] = 37;
        $result['sources'][0]['navigation_target'] = ['route' => '/projects/37'];
        $result['trace'][0]['plan'] = 'PRIVATE plan';
        $public = (new PublicCoreRuntimeResource($result))->resolve();
        self::assertSame($result['reply'], $public['reply']);
        self::assertSame(['ref', 'label'], array_keys($public['sources'][0]));
        self::assertSame(['action', 'step', 'tokens', 'callRef'], array_keys($public['trace'][0]));
        self::assertStringNotContainsString('PRIVATE', json_encode($public, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/projects/', json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function testBufferedReplyIsRejectedUntilCompletedAndUnavailableCannotAdvertiseModelEnabled(): void
    {
        try {
            (new PublicCoreRuntimeResource(array_replace(self::completed(), ['status' => 'running'])))->resolve();
            self::fail('Running request cannot publish reply');
        } catch (LogicException) {
        }
        $this->expectExceptionMessage('public_core_response_invalid');
        (new PublicCoreRuntimeResource(array_replace(PublicCoreRuntimeResource::unavailable(), ['model_enabled' => true])))->resolve();
    }

    public function testDedicatedJobCarriesOnlyOpaqueReferenceAndNeverRetriesDefaultUnavailableDispatch(): void
    {
        $job = new ExecutePublicCoreTestJob('request_'.str_repeat('a', 32));
        self::assertSame('ai-public-core', $job->queue);
        self::assertSame(1, $job->tries);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldNotReceive('canUseAssistant');
        $this->expectExceptionMessage('runtime_not_activated');
        $job->handle(new PublicCoreRequestService($permissions, new PublicCoreAssistantRuntime()));
    }

    public static function command(): array
    {
        return ['fixture_id' => 'unit-fixture', 'fixture_version' => '1', 'input_id' => 'unit-input',
            'request_id' => 'daaf7245-7a63-4cbd-8fd0-2f98580c7255'];
    }

    public static function completed(): array
    {
        return array_replace(PublicCoreRuntimeResource::blocked(), [
            'status' => 'completed', 'reason_code' => 'none', 'request_ref' => 'request_'.str_repeat('a', 32),
            'public_session_ref' => 'session_'.str_repeat('b', 32), 'reply' => 'Проверенный тестовый ответ.',
            'sources' => [['ref' => 'ref_'.str_repeat('c', 32), 'label' => 'Тестовый источник']],
            'trace' => [['action' => 'ready', 'step' => 1, 'tokens' => 40, 'callRef' => null]],
        ]);
    }
}
