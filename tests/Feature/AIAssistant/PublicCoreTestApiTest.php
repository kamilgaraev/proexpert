<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Controllers\PublicCoreTestController;
use App\BusinessModules\Features\AIAssistant\Http\Requests\PublicCoreTestRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FormRequestServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Mockery;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\Runtime\PublicCoreRuntimeBindingsTest;

final class PublicCoreTestApiTest extends TestCase
{
    private Application $application;
    private Router $router;
    private User $viewer;
    private bool $allowed = true;

    protected function setUp(): void
    {
        $this->application = PublicCoreRuntimeBindingsTest::createIsolatedApplication();
        $this->router = $this->application->make('router');
        $this->application->instance('middleware.disable', true);
        $this->viewer = Mockery::mock(User::class);
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->with($this->viewer, 37, true)->andReturnUsing(fn (): bool => $this->allowed);
        $this->application->instance(PublicCoreRequestService::class, new PublicCoreRequestService($permissions, new PublicCoreAssistantRuntime()));
        (new FormRequestServiceProvider($this->application))->boot();
        require dirname(__DIR__, 3).'/app/BusinessModules/Features/AIAssistant/routes.php';
        $this->router->getRoutes()->refreshNameLookups();
        $this->application->instance('redirect', new Redirector(new UrlGenerator($this->router->getRoutes(), Request::create('/'))));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    public function testPublicRoutesUseDedicatedControllerAndAdminAuthenticationStack(): void
    {
        $routes = $this->router->getRoutes();
        foreach (['readiness', 'submit', 'poll'] as $name) {
            $route = $routes->getByName('admin.ai-assistant.public-core.'.$name);
            self::assertNotNull($route);
            self::assertStringContainsString(PublicCoreTestController::class, $route->getActionName());
            self::assertContains('auth:api_admin', $route->gatherMiddleware());
            self::assertContains('organization.context', $route->gatherMiddleware());
            self::assertContains('authorize:admin.access', $route->gatherMiddleware());
            self::assertNull($routes->getByName('mobile.ai-assistant.public-core.'.$name));
        }
    }

    public function testReadinessUsesAdminEnvelopeAndCannotClaimConfiguredSourceIsLive(): void
    {
        $body = $this->send('GET', '/api/v1/admin/ai-assistant/public-core/readiness')->getData(true);
        self::assertTrue($body['success']);
        self::assertSame('public-core-runtime-api/1', $body['data']['schema_version']);
        self::assertSame('unavailable', $body['data']['status']);
        self::assertFalse($body['data']['model_enabled']);
        self::assertFalse($body['data']['private_ready']);
        self::assertNull($body['data']['actual_model']);
        self::assertCount(2, $body['data']['fixtures']);
        self::assertSame(7, array_sum(array_map(static fn (array $fixture): int => count($fixture['inputs']), $body['data']['fixtures'])));
    }

    public function testCompletedSourceCustodyControllerDeliversExactCommittedBodyWithoutSecondWrapper(): void
    {
        $stage = PublicCoreRuntimeResource::stageCompletedEnvelope(PublicCoreRuntimeBindingsTest::completed());
        $runtime = Mockery::mock(PublicCoreAssistantRuntime::class);
        $runtime->shouldReceive('poll')->once()->with($this->viewer, 37, 'request_'.str_repeat('a', 32))
            ->andReturn(PublicCoreRuntimeResource::committedEnvelopeResponse($stage['envelopeBytes'], $stage['envelopeDigest']));
        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->with($this->viewer, 37, true)->andReturnUsing(fn (): bool => $this->allowed);
        $this->application->instance(PublicCoreRequestService::class, new PublicCoreRequestService($permissions, $runtime));
        $response = $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('a', 32));
        self::assertSame($stage['envelopeBytes'], $response->getContent());
        self::assertSame($stage['envelopeDigest'], hash('sha256', $response->getContent()));
        $this->allowed = false;
        $this->expectException(AuthorizationException::class);
        $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('a', 32));
    }

    #[DataProvider('forbiddenFields')]
    public function testUnknownAndRawInputFieldsAreRejectedInsteadOfSilentlyDropped(string $field): void
    {
        $this->expectException(ValidationException::class);
        $this->send('POST', '/api/v1/admin/ai-assistant/public-core/requests',
            PublicCoreRuntimeBindingsTest::command() + [$field => 'PRIVATE']);
    }

    public static function forbiddenFields(): array
    {
        return array_map(static fn (string $field): array => [$field],
            ['message', 'context', 'conversation_id', 'history', 'page', 'attachment_ids', 'uploads', 'actions', 'organization_id', 'unknown']);
    }

    public function testReadinessAndPollingRejectRawQueryInput(): void
    {
        try {
            $this->send('GET', '/api/v1/admin/ai-assistant/public-core/readiness?message=PRIVATE');
            self::fail('Readiness must reject raw input');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('a', 32).'?context=PRIVATE');
    }

    public function testDefaultSubmitAndUnknownPollStayBlockedAndUniformWithoutCreatingOrdinaryHistory(): void
    {
        $submitted = $this->send('POST', '/api/v1/admin/ai-assistant/public-core/requests', PublicCoreRuntimeBindingsTest::command())->getData(true);
        self::assertSame('blocked', $submitted['data']['status']);
        self::assertNull($submitted['data']['reply']);
        self::assertNull($submitted['data']['request_ref']);
        $first = $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('a', 32))->getData(true);
        $other = $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('b', 32))->getData(true);
        self::assertSame($first, $other);
        self::assertSame('runtime_not_activated', $first['data']['reason_code']);
    }

    public function testCurrentAuthorizationRevocationStopsPollingInsideService(): void
    {
        $this->allowed = false;
        $this->expectException(AuthorizationException::class);
        $this->send('GET', '/api/v1/admin/ai-assistant/public-core/requests/request_'.str_repeat('a', 32));
    }

    public function testMalformedSessionReferenceDoesNotReachRequestRuntime(): void
    {
        $this->expectException(ValidationException::class);
        $this->send('POST', '/api/v1/admin/ai-assistant/public-core/requests',
            PublicCoreRuntimeBindingsTest::command() + ['public_session_ref' => '../PRIVATE']);
    }

    private function send(string $method, string $url, array $body = []): JsonResponse
    {
        $request = Request::create($url, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $request->attributes->set('current_organization_id', 37);
        $request->setUserResolver(fn (): User => $this->viewer);
        $this->application->instance('request', $request);
        $this->application->forgetInstance(PublicCoreTestRequest::class);

        $response = $this->router->dispatch($request);
        if (!$response instanceof JsonResponse) {
            throw new LogicException('public_core_json_response_expected');
        }

        return $response;
    }
}
