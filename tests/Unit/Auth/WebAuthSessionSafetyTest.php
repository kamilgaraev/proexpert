<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\DTOs\Auth\WebAuthTokenPayload;
use App\Enums\AuthSessionStatus;
use App\Http\Middleware\VerifyWebCsrfToken;
use App\Http\Middleware\VerifyWebRequestOrigin;
use App\Http\Middleware\AuthenticateWebRefreshToken;
use App\Http\Middleware\WebInterfaceSecurityMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\JwtAuthService;
use App\Services\Auth\UserAuthSessionService;
use App\Services\Auth\WebAuthenticationService;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Auth\WebRefreshCookieService;
use App\Services\Security\WebOriginPolicy;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\JWT;

final class WebAuthSessionSafetyTest extends TestCase
{
    private Application $app;
    private WebAuthTokenService $tokens;
    private WebAuthenticationService $authentication;
    private User $user;
    private UserAuthSessionService $sessions;
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application;
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'web_auth' => ['issuer' => 'test', 'keys' => ['admin' => str_repeat('a', 64), 'lk' => str_repeat('b', 64), 'customer' => str_repeat('c', 64)],
                'origins' => ['admin' => ['https://admin.test']],
                'cookies' => ['admin' => ['name' => '__Host-admin-test'], 'lk' => ['name' => '__Host-lk-test'], 'customer' => ['name' => '__Host-customer-test']]],
        ]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->instance('cache', new CacheManager($this->app));
        $this->app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->app->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        $factory = Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('json')->andReturnUsing(static fn (array $body, int $status) => new JsonResponse($body, $status));
        $this->app->instance(ResponseFactory::class, $factory);
        $logger = Mockery::mock();
        $logger->shouldReceive('notice')->andReturnUsing(function (string $message, array $context): void { $this->events[] = [$message, $context]; });
        $this->app->instance('log', $logger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->user = new User;
        $this->user->setRawAttributes(['id' => 123, 'is_active' => true]);
        $this->sessions = Mockery::mock(UserAuthSessionService::class);
        $this->tokens = new WebAuthTokenService($this->sessions);
        $this->authentication = new WebAuthenticationService(Mockery::mock(JwtAuthService::class), $this->tokens, $this->sessions, Mockery::mock(JWT::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public static function webControllers(): array
    {
        return [
            'admin' => [\App\Http\Controllers\Api\V1\Admin\Auth\AuthController::class, 'admin'],
            'lk' => [\App\Http\Controllers\Api\V1\Landing\Auth\AuthController::class, 'lk'],
            'customer' => [\App\Http\Controllers\Api\V1\Customer\Auth\AuthController::class, 'customer'],
        ];
    }

    private function controller(string $class): object
    {
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($controller, 'webAuthentication'))->setValue($controller, $this->authentication);
        (new ReflectionProperty($controller, 'refreshCookies'))->setValue($controller, new WebRefreshCookieService);

        return $controller;
    }

    public static function logoutSessions(): array
    {
        $cases = [];
        foreach (self::webControllers() as $name => [$class, $audience]) {
            $cases[$name.'-same'] = [$class, $audience, true];
            $cases[$name.'-newer'] = [$class, $audience, false];
        }

        return $cases;
    }

    #[DataProvider('webControllers')]
    public function test_lock_timeout_returns_retryable_503_without_clearing_cookie(string $class, string $audience): void
    {
        $pair = $this->tokens->issue($this->user, $audience, (string) Str::uuid(), null, false);
        $request = Request::create('/auth/refresh', 'POST');
        $request->attributes->set('web_refresh_payload', $this->tokens->parse($pair->refreshToken, $audience, 'refresh'));
        $request->attributes->set('web_refresh_token', $pair->refreshToken);
        $request->setUserResolver(fn () => $this->user);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        Cache::swap(Mockery::mock()->shouldReceive('lock')->andReturn($lock)->getMock());
        $this->sessions->shouldNotReceive('revoke');
        $response = $this->controller($class)->refresh($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('web_refresh_unavailable', $response->getData(true)['code']);
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame([], $response->headers->getCookies());
    }

    #[DataProvider('webControllers')]
    public function test_failed_refresh_record_write_keeps_existing_session_and_cookie(string $class, string $audience): void
    {
        $id = (string) Str::uuid();
        $pair = $this->tokens->issue($this->user, $audience, $id, null, false);
        $request = Request::create('/auth/refresh', 'POST');
        $request->attributes->set('web_refresh_payload', $this->tokens->parse($pair->refreshToken, $audience, 'refresh'));
        $request->attributes->set('web_refresh_token', $pair->refreshToken);
        $request->setUserResolver(fn () => $this->user);
        $session = new UserAuthSession;
        $session->setRawAttributes(['user_id' => 123, 'status' => AuthSessionStatus::Active->value]);
        $this->sessions->shouldReceive('findActiveByUuid')->with($id)->andReturn($session);
        $this->sessions->shouldNotReceive('revoke');
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturnUsing(static fn ($seconds, $callback) => $callback());
        $cache = Mockery::mock();
        $cache->shouldReceive('lock')->andReturn($lock);
        $cache->shouldReceive('get')->with("web_auth:refresh:{$audience}:{$id}")->andReturn(['token_hash' => hash('sha256', $pair->refreshToken), 'token_id' => $pair->refreshTokenId]);
        $cache->shouldReceive('put')->andReturnUsing(static fn (string $key) => str_starts_with($key, 'web_auth:refresh_rotation:'));
        $cache->shouldNotReceive('forget');
        Cache::swap($cache);
        $response = $this->controller($class)->refresh($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
    }

    #[DataProvider('webControllers')]
    public function test_failed_rotation_result_write_preserves_old_cookie_retry(string $class, string $audience): void
    {
        $id = (string) Str::uuid();
        $pair = $this->tokens->issue($this->user, $audience, $id, null, false);
        $payload = $this->tokens->parse($pair->refreshToken, $audience, 'refresh');
        $key = "web_auth:refresh:{$audience}:{$id}";
        $originalRecord = ['token_hash' => hash('sha256', $pair->refreshToken), 'token_id' => $pair->refreshTokenId, 'csrf_hash' => hash('sha256', $pair->csrfToken)];
        $records = [$key => $originalRecord];
        $failRotationWrite = true;
        $session = new UserAuthSession;
        $session->setRawAttributes(['user_id' => 123, 'status' => AuthSessionStatus::Active->value]);
        $this->sessions->shouldReceive('findActiveByUuid')->with($id)->andReturn($session);
        $this->sessions->shouldNotReceive('revoke');
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andReturnUsing(static fn ($seconds, $callback) => $callback());
        $cache = Mockery::mock();
        $cache->shouldReceive('lock')->andReturn($lock);
        $cache->shouldReceive('get')->andReturnUsing(static function (string $key) use (&$records) { return $records[$key] ?? null; });
        $cache->shouldReceive('put')->andReturnUsing(static function (string $key, mixed $value) use (&$records, &$failRotationWrite): bool {
            if ($failRotationWrite && str_starts_with($key, 'web_auth:refresh_rotation:')) return false;
            $records[$key] = $value;

            return true;
        });
        $cache->shouldNotReceive('forget');
        Cache::swap($cache);
        $request = Request::create('/auth/refresh', 'POST');
        $request->attributes->set('web_refresh_payload', $payload);
        $request->attributes->set('web_refresh_token', $pair->refreshToken);
        $request->setUserResolver(fn () => $this->user);
        $controller = $this->controller($class);
        self::assertSame(503, $controller->refresh($request)->getStatusCode());
        self::assertSame($originalRecord, $records[$key]);
        $failRotationWrite = false;
        $retried = $controller->refresh($request);
        self::assertSame(200, $retried->getStatusCode());
        self::assertCount(1, $retried->headers->getCookies());
        self::assertNotSame($originalRecord['token_id'], $records[$key]['token_id']);
    }

    #[DataProvider('logoutSessions')]
    public function test_logout_only_clears_the_original_session_cookie(string $class, string $audience, bool $sameSession): void
    {
        $oldId = (string) Str::uuid();
        $old = $this->tokens->issue($this->user, $audience, $oldId, null, false);
        $new = $sameSession ? $old : $this->tokens->issue($this->user, $audience, (string) Str::uuid(), null, false);
        $request = Request::create('/auth/logout', 'POST', [], [config("web_auth.cookies.{$audience}.name") => $new->refreshToken]);
        $request->attributes->set('web_auth_payload', $this->tokens->parse($old->accessToken, $audience, 'access'));
        $request->setUserResolver(fn () => $this->user);
        $session = new UserAuthSession;
        $session->setRawAttributes(['user_id' => 123, 'status' => AuthSessionStatus::Active->value]);
        $this->sessions->shouldReceive('findActiveByUuid')->with($oldId)->once()->andReturn($session);
        $this->sessions->shouldReceive('revoke')->with($session, 'user_logout')->once();
        $response = $this->controller($class)->logout($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount($sameSession ? 1 : 0, $response->headers->getCookies());
        self::assertTrue($this->authentication->canClearRefreshCookie($request, $audience, $this->tokens->parse($new->refreshToken, $audience, 'refresh')->sessionUuid));
        self::assertSame($sameSession, $this->authentication->canClearRefreshCookie($request, $audience, $oldId));
    }

    #[DataProvider('webControllers')]
    public function test_csrf_response_identifies_current_cookie_session_without_returning_credentials(string $class, string $audience): void
    {
        $sessionId = (string) Str::uuid();
        $pair = $this->tokens->issue($this->user, $audience, $sessionId, null, false);
        $request = Request::create('/auth/csrf', 'GET');
        $request->attributes->set('web_refresh_payload', $this->tokens->parse($pair->refreshToken, $audience, 'refresh'));
        $response = $this->controller($class)->csrf($request);
        self::assertSame($sessionId, $response->getData(true)['data']['session_id']);
        self::assertSame($pair->csrfToken, $response->getData(true)['data']['csrf_token']);
        self::assertStringNotContainsString($pair->refreshToken, $response->getContent());
        self::assertStringNotContainsString($pair->accessToken, $response->getContent());
    }

    public function test_reject_reasons_are_distinct_and_logs_do_not_include_credentials(): void
    {
        $pair = $this->tokens->issue($this->user, 'admin', (string) Str::uuid(), null, false);
        $request = Request::create('/auth/refresh?token=private', 'POST');
        $request->attributes->set('web_refresh_payload', $this->tokens->parse($pair->refreshToken, 'admin', 'refresh'));
        $request->headers->set('Origin', 'https://evil.test');
        $next = static fn () => new Response('ok');
        $origin = (new VerifyWebRequestOrigin(new WebOriginPolicy))->handle($request, $next, 'admin');
        self::assertSame('web_origin_forbidden', $origin->getData(true)['code']);
        $csrf = new VerifyWebCsrfToken($this->tokens);
        self::assertSame('web_csrf_missing', $csrf->handle($request, $next, 'admin')->getData(true)['code']);
        $request->headers->set('X-CSRF-Token', 'private-csrf');
        self::assertSame('web_csrf_mismatch', $csrf->handle($request, $next, 'admin')->getData(true)['code']);
        $request->headers->set('X-CSRF-Token', $pair->csrfToken);
        self::assertSame(200, $csrf->handle($request, $next, 'admin')->getStatusCode());
        $events = json_encode($this->events, JSON_THROW_ON_ERROR);
        self::assertCount(3, $this->events);
        self::assertStringNotContainsString('private', $events);
        self::assertStringNotContainsString($pair->refreshToken, $events);
        self::assertStringNotContainsString($pair->csrfToken, $events);
    }

    public function test_diagnostics_do_not_log_invitation_tokens_from_url_paths(): void
    {
        $request = Request::create('/project-participant-invitations/private-invitation/accept', 'POST');
        $route = new Route('POST', 'project-participant-invitations/{token}/accept', static fn () => null);
        $request->setRouteResolver(static fn () => $route);
        (new VerifyWebCsrfToken($this->tokens))->handle($request, static fn () => new Response, 'lk');
        self::assertSame('project-participant-invitations/{token}/accept', $this->events[0][1]['path']);
        self::assertStringNotContainsString('private-invitation', json_encode($this->events, JSON_THROW_ON_ERROR));
    }

    public static function tokenMiddleware(): array { return [['refresh'], ['access']]; }

    #[DataProvider('tokenMiddleware')]
    public function test_session_lookup_failure_is_not_reported_as_invalid_credentials(string $kind): void
    {
        $id = (string) Str::uuid();
        $pair = $this->tokens->issue($this->user, 'admin', $id, null, false);
        $request = Request::create('/api/v1/admin/projects', 'GET', [], ['__Host-admin-test' => $pair->refreshToken]);
        $request->headers->set('Authorization', 'Bearer '.$pair->accessToken);
        $this->sessions->shouldReceive('findActiveByUuid')->with($id)->andThrow(new RuntimeException('temporary_session_lookup_failure'));
        $middleware = $kind === 'refresh'
            ? new AuthenticateWebRefreshToken(new WebRefreshCookieService, $this->tokens, $this->sessions)
            : new WebInterfaceSecurityMiddleware($this->tokens, $this->sessions, Mockery::mock(AuthorizationService::class), new WebOriginPolicy);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('temporary_session_lookup_failure');
        $middleware->handle($request, static fn () => new Response, 'admin');
    }
}
