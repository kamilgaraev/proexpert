<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\SentryScopeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use ReflectionMethod;
use RuntimeException;
use Sentry\Event;
use Sentry\State\Scope;

final class SentryScopeServiceTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_normalized_request_correlation_id_has_priority_over_raw_headers(): void
    {
        $request = Request::create('/api/v1/admin/projects/52/estimate-generation', 'POST');
        $request->headers->set('X-Correlation-ID', 'unsafe-raw-id');
        $request->attributes->set('correlation_id', 'req_admin_20260813_safe');
        $scope = new Scope;
        $method = new ReflectionMethod(SentryScopeService::class, 'applyExceptionContext');

        $method->invoke(new SentryScopeService, $scope, new RuntimeException('failure'), $request);

        $event = $scope->applyToEvent(Event::createEvent());
        self::assertNotNull($event);
        self::assertSame('req_admin_20260813_safe', $event->getTags()['correlation_id']);
        self::assertSame('req_admin_20260813_safe', $event->getContexts()['request']['correlation_id']);
    }
}
