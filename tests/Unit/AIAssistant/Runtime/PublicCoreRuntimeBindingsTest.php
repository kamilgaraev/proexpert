<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Runtime;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecutePublicCoreTestJob;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreGatewayModelDriver;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantModelContextProfile;
use App\BusinessModules\Features\AIAssistant\Services\Context\AssistantContextSourceBinding;
use App\Models\User;
use App\Services\Privacy\Gateway\Contracts\GatewayModelProfile;
use App\Services\Privacy\Gateway\Contracts\GatewayModelRequest;
use App\Services\Privacy\Gateway\Contracts\GatewayModelResponse;
use App\Services\Privacy\Gateway\GatewayPublicCoreRequestValidator;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
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

    public function testAcceptedCatalogDoesNotMakeDefaultRuntimeOrModelAvailable(): void
    {
        $runtime = new PublicCoreAssistantRuntime();
        $viewer = Mockery::mock(User::class);
        $readiness = (new PublicCoreRuntimeResource($runtime->readiness($viewer, 37)))->resolve();
        self::assertSame('unavailable', $readiness['status']);
        self::assertFalse($readiness['model_enabled']);
        self::assertFalse($readiness['private_ready']);
        self::assertNull($readiness['actual_model']);
        self::assertCount(2, $readiness['fixtures']);
        self::assertSame(7, array_sum(array_map(static fn (array $fixture): int => count($fixture['inputs']), $readiness['fixtures'])));
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

    public function testGatewaySixteenFieldsMapExplicitlyToCoreElevenAndNativeReceiptEight(): void
    {
        $gateway = self::gatewayProfile();
        $mapped = PublicCoreContextBindings::coreProfile($gateway);
        $core = AssistantModelContextProfile::resolve($mapped['profileRef'], static fn (string $ref): array => $mapped);
        self::assertCount(16, $gateway->values());
        self::assertCount(11, $mapped);
        self::assertCount(8, $core->modelPayload());
        self::assertSame('offline-synthetic', $mapped['qualification']);
        self::assertSame($gateway->inputBudget(), $core->inputBudget());
        self::assertSame($gateway->values()['maxOutputTokens'], $core->modelPayload()['maxOutputTokens']);
        self::assertSame($gateway->values()['toolReserve'], $core->modelPayload()['toolReserve']);
        self::assertNotSame($gateway->fingerprint(), $core->fingerprint());
        self::assertNotSame($core->fingerprint(), hash('sha256', AssistantContextSourceBinding::canonical($core->modelPayload())));
        self::assertArrayNotHasKey('endpoint', $mapped);
        self::assertArrayNotHasKey('mappingEvidenceRef', $mapped);
        self::assertFalse($gateway->isActualProfile());
        $this->expectExceptionMessage('receipt_unavailable');
        (new PublicCoreGatewayModelDriver($gateway))(['schemaVersion' => 'assistant-loop-input/1']);
    }

    public function testUnqualifiedGatewayProfileCannotProduceCoreProfileOrBody(): void
    {
        try {
            PublicCoreContextBindings::coreProfile(GatewayModelProfile::unqualified());
            self::fail('Unqualified profile must fail');
        } catch (LogicException) {
        }
        $this->expectExceptionMessage('model_profile_unqualified');
        (new PublicCoreGatewayModelDriver(GatewayModelProfile::unqualified()))->bodyBytes([]);
    }

    public function testDriverBodyPreservesCompleteLoopInputAndMatchesAcceptedGatewayBodyContract(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $input = ['schemaVersion' => 'assistant-loop-input/1', 'context' => ['currentRef' => 'ref_'.str_repeat('a', 32)],
            'contextScope' => ['kind' => 'selected_entity'], 'tools' => [['name' => 'material.search']],
            'toolReferences' => null, 'repair' => ['reason' => 'claims_invalid']];
        $body = $driver->bodyBytes($input);
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(GatewayModelRequest::canonicalJson($input), $decoded['messages'][1]['content']);
        self::assertFalse($decoded['stream']);
        self::assertFalse($decoded['store']);
        self::assertSame($profile->values()['maxOutputTokens'], $decoded['max_completion_tokens']);
        $request = self::gatewayRequest($profile, $body);
        self::assertNull((new GatewayPublicCoreRequestValidator())->validate($request, $profile, 1000));
        self::assertSame(hash('sha256', $body), $request->projectionDigest);
    }

    public function testDriverDecodesExactCanonicalActionAndRejectsForeignResponseBindings(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $action = ['type' => 'plan', 'plan' => 'Выбрать разрешённый инструмент.'];
        $response = GatewayModelResponse::completed($request, GatewayModelRequest::canonicalJson($action), null);
        self::assertSame(json_decode(GatewayModelRequest::canonicalJson($action), true, 64, JSON_THROW_ON_ERROR), $driver->action($request, $response));
        $other = GatewayModelResponse::fromArray(array_replace($response->values(), ['attemptRef' => 'attempt_'.str_repeat('d', 32)]));
        $this->expectExceptionMessage('profile_changed');
        $driver->action($request, $other);
    }

    public function testUnavailableGatewayResponseCannotBecomeModelAction(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $this->expectExceptionMessage('runtime_not_activated');
        $driver->action($request, GatewayModelResponse::unavailable($request, 'runtime_not_activated'));
    }

    public function testTypedUsageCannotBypassQualifiedProfileOutputBudget(): void
    {
        $profile = self::gatewayProfile();
        $driver = new PublicCoreGatewayModelDriver($profile);
        $request = self::gatewayRequest($profile, $driver->bodyBytes(['schemaVersion' => 'assistant-loop-input/1']));
        $response = GatewayModelResponse::completed($request, GatewayModelRequest::canonicalJson(['type' => 'plan', 'plan' => 'Шаг']),
            ['inputTokens' => 1, 'outputTokens' => 257, 'totalTokens' => 258]);
        $this->expectExceptionMessage('budget_exceeded');
        $driver->action($request, $response);
    }

    public function testPublicCatalogUsesExactAcceptedRowsAndExposesOnlySelectorsAndReviewedLabels(): void
    {
        $registry = RegisteredPublicFixtureRegistry::compiled();
        self::assertSame('f6bfc3c523c792ab9a80dbe2c3a950aebec1dfad65456243bbaadfcdb50a85fe', $registry->manifestDigest());
        $readiness = (new PublicCoreAssistantRuntime())->readiness(Mockery::mock(User::class), 37);
        $actual = [];
        foreach ($readiness['fixtures'] as $fixture) {
            self::assertSame(['fixture_id', 'fixture_version', 'label', 'inputs'], array_keys($fixture));
            foreach ($fixture['inputs'] as $input) {
                self::assertSame(['input_id', 'label'], array_keys($input));
                $row = $registry->resolve($fixture['fixture_id'], $fixture['fixture_version'], $input['input_id']);
                self::assertNotNull($row);
                self::assertSame($row['scenario_step'], $input['input_id']);
                self::assertSame($row['display_text'], $input['label']);
                $actual[] = $input['input_id'];
            }
        }
        self::assertSame(array_column($registry->catalog(), 'input_id'), $actual);
        $encoded = json_encode($readiness, JSON_THROW_ON_ERROR);
        foreach (['record_sha256', 'canonical_question_sha256', 'source_generation_ref', 'records', 'transcript', '7800', '8250'] as $privateField) {
            self::assertStringNotContainsString($privateField, $encoded);
        }
        self::assertFalse($readiness['model_enabled']);
        self::assertSame('unavailable', $readiness['status']);
    }

    public function testUnknownCatalogTupleRemainsBlockedWithoutAuthorityFallback(): void
    {
        $runtime = new PublicCoreAssistantRuntime();
        $viewer = Mockery::mock(User::class);
        self::assertSame('source_unavailable', $runtime->submit($viewer, 37, self::command())['reason_code']);
        self::assertSame('runtime_not_activated', $runtime->submit($viewer, 37,
            array_replace(self::command(), ['fixture_id' => 'material-search-v1', 'fixture_version' => 'public-material/1', 'input_id' => 'price-b25']))['reason_code']);
    }

    public static function gatewayProfile(): GatewayModelProfile
    {
        return GatewayModelProfile::fromArray([
            'profileRef' => 'profile_'.str_repeat('a', 32), 'qualification' => 'local-stub',
            'adapterRevision' => 'unit-public-adapter/1', 'apiMethod' => 'local_action', 'endpoint' => 'local://public-core-stub',
            'modelId' => 'local-action-stub', 'modelRevision' => 'unit/1', 'tokenizerId' => 'unit-tokenizer', 'tokenizerRevision' => 'unit/1',
            'mappingEvidenceRef' => 'mapping_'.str_repeat('b', 32), 'capabilityEvidenceRef' => 'capability_'.str_repeat('b', 32),
            'capacityEvidenceRef' => 'capacity_'.str_repeat('b', 32), 'contextWindow' => 32768,
            'maxOutputTokens' => 256, 'answerReserve' => 1024, 'toolReserve' => 512,
        ]);
    }

    private static function gatewayRequest(GatewayModelProfile $profile, string $body): GatewayModelRequest
    {
        return GatewayModelRequest::fromArray([
            'schemaVersion' => GatewayModelRequest::SCHEMA_VERSION, 'contractVersion' => GatewayModelRequest::CONTRACT_VERSION,
            'purpose' => GatewayModelRequest::PURPOSE, 'requestRef' => 'request_'.str_repeat('a', 32),
            'attemptRef' => 'attempt_'.str_repeat('b', 32), 'publicAdmissionRef' => 'admission_'.str_repeat('a', 32),
            'contextReceiptRef' => 'context_'.str_repeat('a', 32), 'corePayloadDigest' => str_repeat('a', 64),
            'coreReceiptDigest' => str_repeat('b', 64), 'projectionRef' => 'projection_'.str_repeat('a', 32),
            'projectionDigest' => hash('sha256', $body), 'profileRef' => $profile->values()['profileRef'],
            'profileFingerprint' => $profile->fingerprint(), 'expiresAt' => 2000, 'bodyBytes' => $body,
        ]);
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
