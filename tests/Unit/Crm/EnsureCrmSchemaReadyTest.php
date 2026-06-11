<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\BusinessModules\Features\Crm\Http\Middleware\EnsureCrmSchemaReady;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EnsureCrmSchemaReadyTest extends TestCase
{
    public function test_returns_controlled_service_unavailable_response_when_crm_schema_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(false);

        $middleware = new EnsureCrmSchemaReady();
        $response = $middleware->handle(
            Request::create('/api/v1/admin/crm/summary', 'GET'),
            static fn () => response()->json(['reached' => true])
        );

        $payload = $response->getData(true);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('CRM_SCHEMA_NOT_READY', $payload['code']);
        $this->assertSame('setup', $payload['status']);
    }

    public function test_allows_request_when_crm_schema_is_ready(): void
    {
        Schema::shouldReceive('hasTable')->andReturn(true);

        $middleware = new EnsureCrmSchemaReady();
        $response = $middleware->handle(
            Request::create('/api/v1/admin/crm/summary', 'GET'),
            static fn () => response()->json(['reached' => true])
        );

        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['reached']);
    }
}
