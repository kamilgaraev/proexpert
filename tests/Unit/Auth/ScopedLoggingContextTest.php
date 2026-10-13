<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Providers\LoggingServiceProvider;
use App\Services\Logging\AccessLogger;
use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ScopedLoggingContextTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_logger_shares_current_context_and_drops_previous_request_state(): void
    {
        $app = new Application;
        $app->instance('config', new Repository);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('auth', Mockery::mock()->shouldReceive('user')->andReturn(null)->getMock());
        $firstRequest = Request::create('/api/v1/admin/auth/refresh');
        $firstRequest->headers->set('User-Agent', 'first-browser');
        $app->instance('request', $firstRequest);
        (new LoggingServiceProvider($app))->register();
        $firstLogger = $app->make(AccessLogger::class);
        $firstContext = $app->make(RequestContext::class);
        $firstContext->setCorrelationId('first-request-id');
        $captured = (new ReflectionProperty($firstLogger, 'requestContext'))->getValue($firstLogger);
        self::assertSame($firstContext, $captured);
        self::assertSame('first-request-id', $captured->getCorrelationId());
        $userContext = $app->make(UserContext::class);
        (new ReflectionProperty($userContext, 'userId'))->setValue($userContext, 123);

        $app->forgetScopedInstances();
        $secondRequest = Request::create('/api/v1/admin/auth/csrf');
        $secondRequest->headers->set('User-Agent', 'second-browser');
        $app->instance('request', $secondRequest);
        $secondLogger = $app->make(AccessLogger::class);
        $secondContext = $app->make(RequestContext::class);
        $secondContext->setCorrelationId('second-request-id');
        $secondCaptured = (new ReflectionProperty($secondLogger, 'requestContext'))->getValue($secondLogger);
        self::assertNotSame($firstLogger, $secondLogger);
        self::assertSame($secondContext, $secondCaptured);
        self::assertSame('second-browser', $secondCaptured->getMetadata()['user_agent']);
        self::assertSame('second-request-id', $secondCaptured->getCorrelationId());
        self::assertNull($app->make(UserContext::class)->getUserId());
    }
}
