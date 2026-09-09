<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Enums\AuthSessionStatus;
use App\Http\Middleware\EnsureAuthSessionIsActive;
use App\Http\Middleware\JwtMiddleware;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\UserAuthSessionService;
use Carbon\Carbon;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\JWT;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;
use Tymon\JWTAuth\Token;

final class MobileTokenRefreshTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Container $originalContainer;
    private mixed $originalFacadeApplication;
    private Application $app;
    private User $user;
    private UserAuthSession $session;
    private JWT $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $this->originalFacadeApplication = Facade::getFacadeApplication();
        $this->app = new Application(dirname(__DIR__, 3));
        $jwtConfig = require $this->app->basePath('config/jwt.php');
        $this->app->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
            'auth' => [
                'defaults' => ['guard' => 'api_mobile'],
                'guards' => ['api_mobile' => ['driver' => 'jwt', 'provider' => 'users']],
                'providers' => ['users' => ['driver' => 'memory']],
            ],
            'auth_tokens' => ['sessions' => ['enabled' => true]],
            'jwt' => array_replace($jwtConfig, [
                'secret' => 'mobile-refresh-regression-test-secret-at-least-32-characters',
                'ttl' => 60,
                'refresh_ttl' => 20160,
                'blacklist_grace_period' => 0,
            ]),
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => [
                'driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class,
            ]]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $translator = new Translator(new ArrayLoader(), 'ru');
        $translator->addLines([
            'auth.not_authenticated' => 'Authentication required',
            'auth.security_session_expired' => 'Session expired',
            'auth.token_expired' => 'Token expired',
            'auth.token_error' => 'Invalid token',
        ], 'ru');
        $this->app->instance('translator', $translator);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(
            static fn (array $data, int $status): JsonResponse => new JsonResponse($data, $status)
        );
        $this->app->instance(ResponseFactory::class, $responses);
        $this->app->instance('request', Request::create('/api/v1/mobile/auth/refresh', 'POST'));
        $this->app->register(AuthServiceProvider::class);
        $this->app->register(CacheServiceProvider::class);
        $this->app->register(LaravelServiceProvider::class)->boot();
        $this->user = new User();
        $this->user->forceFill(['id' => 42, 'is_active' => true]);
        $provider = Mockery::mock(UserProvider::class);
        $provider->shouldReceive('retrieveById')->with(42)->andReturnUsing(fn () => $this->user);
        $this->app['auth']->provider('memory', static fn () => $provider);
        $this->session = new UserAuthSession();
        $this->session->forceFill([
            'user_id' => 42,
            'session_uuid' => '1485121c-07f9-4ac9-bef0-d75f8d402650',
            'status' => AuthSessionStatus::Active,
        ]);
        $this->jwt = $this->app->make(JWT::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->originalFacadeApplication);
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    public function test_expired_token_refreshes_through_session_middleware(): void
    {
        $token = $this->expiredToken();
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldReceive('findActiveByUuid')->with($this->session->session_uuid)->once()->andReturn($this->session);
        $sessions->shouldReceive('touch')->with($this->session)->once();
        $response = $this->handle($token, $sessions, function (Request $request): JsonResponse {
            self::assertSame($this->user, $request->user());
            self::assertSame($this->user, auth('api_mobile')->user());
            self::assertSame($this->session, $request->attributes->get('auth_session'));
            $payload = $request->attributes->get('token_payload');
            $fresh = auth('api_mobile')->claims([
                'session_uuid' => $payload->get('session_uuid'),
                'organization_id' => $payload->get('organization_id'),
            ])->refresh();

            return new JsonResponse(['token' => $fresh]);
        });
        self::assertSame(200, $response->getStatusCode());
        $fresh = json_decode((string) $response->getContent(), true)['token'];
        self::assertNotSame($token, $fresh);
        $this->jwt->manager()->setRefreshFlow(false);
        $payload = $this->jwt->setToken($fresh)->getPayload();
        self::assertSame('42', (string) $payload->get('sub'));
        self::assertSame(7, $payload->get('organization_id'));
        self::assertSame($this->session->session_uuid, $payload->get('session_uuid'));
        self::assertGreaterThan(Carbon::now()->timestamp, $payload->get('exp'));
    }

    public function test_refresh_keeps_normal_expiration_validation_enabled(): void
    {
        $token = $this->expiredToken();
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldReceive('findActiveByUuid')->andReturn($this->session);
        $sessions->shouldReceive('touch');
        $response = $this->handle($token, $sessions, static fn () => new JsonResponse());
        self::assertSame(200, $response->getStatusCode());
        $this->expectException(TokenExpiredException::class);
        $this->jwt->manager()->decode(new Token($token));
    }

    public function test_revoked_session_cannot_refresh_expired_token(): void
    {
        $token = $this->expiredToken();
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldReceive('findActiveByUuid')->once()->andReturnNull();
        $sessions->shouldNotReceive('touch');
        $response = $this->handle($token, $sessions, static function (): never {
            self::fail('Revoked session reached refresh');
        });
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_inactive_user_cannot_refresh_expired_token(): void
    {
        $token = $this->expiredToken();
        $this->user->is_active = false;
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldReceive('findActiveByUuid')->once()->andReturn($this->session);
        $sessions->shouldNotReceive('touch');
        $response = $this->handle($token, $sessions, static function (): never {
            self::fail('Inactive user reached refresh');
        });
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_session_of_another_user_cannot_refresh_expired_token(): void
    {
        $token = $this->expiredToken();
        $this->session->user_id = 99;
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldReceive('findActiveByUuid')->once()->andReturn($this->session);
        $sessions->shouldNotReceive('touch');
        $response = $this->handle($token, $sessions, static function (): never {
            self::fail('Mismatched session reached refresh');
        });
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_blacklisted_token_cannot_refresh(): void
    {
        $token = $this->expiredToken();
        $this->jwt->manager()->setRefreshFlow()->invalidate(new Token($token));
        $this->jwt->manager()->setRefreshFlow(false);
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldNotReceive('findActiveByUuid');
        $response = $this->handle($token, $sessions, static function (): never {
            self::fail('Blacklisted token reached refresh');
        });
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_token_outside_refresh_window_cannot_refresh(): void
    {
        $token = $this->expiredToken();
        Carbon::setTestNow(Carbon::now()->addDays(15));
        $sessions = Mockery::mock(UserAuthSessionService::class);
        $sessions->shouldNotReceive('findActiveByUuid');
        $response = $this->handle($token, $sessions, static function (): never {
            self::fail('Expired refresh window reached refresh');
        });
        self::assertSame(401, $response->getStatusCode());
    }

    private function expiredToken(): string
    {
        Carbon::setTestNow(Carbon::now()->subHours(2));
        $token = $this->jwt->claims([
            'session_uuid' => $this->session->session_uuid,
            'organization_id' => 7,
        ])->fromUser($this->user);
        Carbon::setTestNow();

        return $token;
    }

    private function handle(string $token, UserAuthSessionService $sessions, \Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/api/v1/mobile/auth/refresh', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $this->app->instance('request', $request);
        $this->jwt->setToken($token);

        return (new JwtMiddleware($this->jwt))->handle(
            $request,
            static fn (Request $request) => (new EnsureAuthSessionIsActive($sessions))->handle($request, $next),
            'api_mobile'
        );
    }
}
