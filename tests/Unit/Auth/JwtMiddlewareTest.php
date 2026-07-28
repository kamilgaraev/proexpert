<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Http\Middleware\JwtMiddleware;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Tymon\JWTAuth\Blacklist;
use Tymon\JWTAuth\Http\Parser\Parser;
use Tymon\JWTAuth\JWT;
use Tymon\JWTAuth\Manager;
use Tymon\JWTAuth\Payload;
use Tymon\JWTAuth\Token;

final class JwtMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Container $originalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);

        parent::tearDown();
    }

    public function test_it_authenticates_request_user_through_configured_guard(): void
    {
        $user = new User();
        $user->id = 42;
        $payload = Mockery::mock(Payload::class);
        $token = new Token('header.payload.signature');

        $manager = Mockery::mock(Manager::class);
        $manager
            ->shouldReceive('decode')
            ->once()
            ->with(Mockery::type(Token::class))
            ->andReturn($payload);
        $manager
            ->shouldReceive('getBlacklist')
            ->once()
            ->andReturn($blacklist = Mockery::mock(Blacklist::class));

        $blacklist
            ->shouldReceive('has')
            ->once()
            ->with($payload)
            ->andReturnFalse();

        $parser = Mockery::mock(Parser::class);
        $parser
            ->shouldReceive('parseToken')
            ->once()
            ->andReturn($token);

        $guard = Mockery::mock(Guard::class);
        $guard
            ->shouldReceive('user')
            ->once()
            ->andReturn($user);

        $auth = new class ($guard) {
            public function __construct(private readonly Guard $guard)
            {
            }

            public function shouldUse(string $guard): void
            {
                TestCase::assertSame('api_mobile', $guard);
            }

            public function guard(?string $guard = null): Guard
            {
                TestCase::assertTrue($guard === null || $guard === 'api_mobile');

                return $this->guard;
            }
        };

        $container = new Container();
        $container->instance('auth', $auth);
        $container->instance(AuthFactory::class, $auth);
        Container::setInstance($container);

        $request = Request::create('/api/v1/mobile/auth/me', 'GET');
        $middleware = new JwtMiddleware(new JWT($manager, $parser));

        $response = $middleware->handle($request, static fn (Request $handledRequest): JsonResponse => new JsonResponse([
            'user_id' => $handledRequest->user()?->id,
            'guard_user_id' => $handledRequest->user('api_mobile')?->id,
            'jwt_token' => $handledRequest->attributes->get('jwt_token'),
            'token_payload_attached' => $handledRequest->attributes->get('token_payload') === $payload,
        ]), 'api_mobile');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'user_id' => 42,
            'guard_user_id' => 42,
            'jwt_token' => 'header.payload.signature',
            'token_payload_attached' => true,
        ], json_decode((string) $response->getContent(), true));
    }
}
