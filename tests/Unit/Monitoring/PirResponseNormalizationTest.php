<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Http\Middleware\NormalizeAdminResponse;
use App\Http\Responses\AdminResponse;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Tests\TestCase;

final class PirResponseNormalizationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_native_json_envelopes_keep_canonical_payload_and_transport_contract(): void
    {
        $cases = [
            [201,
                '{"success":true,"message":"","data":{"map":{},"items":[],"numeric":{"01":1,"2":false},"zero":-0,"label":"МОСТ"},"meta":{"map":{},"items":[],"sparse":{"2":"a","7":"b"}},"summary":{"map":{}},"links":{"next":null}}',
                '{"success":true,"message":null,"data":{"map":{},"items":[],"numeric":{"01":1,"2":false},"zero":0,"label":"МОСТ"},"meta":{"map":[],"items":[],"sparse":{"2":"a","7":"b"}},"summary":{"map":[]},"links":{"next":null}}'],
            [200,
                '{"success":true,"message":"fixture","data":[{"map":{},"items":[],"value":1.25}],"current_page":{"a":{}},"last_page":2,"per_page":1,"total":2,"links":{"numeric":{"01":{}}},"next_page_url":"/next"}',
                '{"success":true,"message":"fixture","data":[{"map":{},"items":[],"value":1.25}],"meta":{"current_page":{"a":[]},"from":null,"last_page":2,"links":{"numeric":{"01":[]}},"path":null,"per_page":1,"to":null,"total":2},"links":{"first":null,"last":null,"prev":null,"next":"/next"}}'],
            [200,
                '{"success":true,"data":null,"meta":[],"summary":[],"links":[]}',
                '{"success":true,"message":null,"data":[],"meta":[],"summary":[],"links":[]}'],
            [200,
                '{"success":true,"message":"fixture","data":{"map":{}},"meta":"invalid","summary":{},"links":{}}',
                '{"success":true,"message":"fixture","data":{"map":{}}}'],
            [200,
                '{"success":true,"message":"fixture","custom":{"map":{}}}',
                '{"success":true,"message":"fixture","data":{"custom":{"map":[]}}}'],
            [400,
                '{"success":false,"message":"fixture","errors":{"map":{}},"custom":"retained"}',
                '{"success":false,"message":"fixture","data":null,"error":"fixture","code":"http_400","errors":{"map":[]},"custom":"retained"}'],
            [200,
                '{"data":[{"map":{}}],"current_page":1,"last_page":2,"per_page":1,"total":2}',
                '{"success":true,"message":null,"data":[{"map":[]}],"meta":{"current_page":1,"from":null,"last_page":2,"links":[],"path":null,"per_page":1,"to":null,"total":2},"links":{"first":null,"last":null,"prev":null,"next":null}}'],
            [200, '[{"map":{}}]', '{"success":true,"message":null,"data":[{"map":[]}]}'],
            [200, 'null', '{"success":true,"message":null,"data":null}'],
        ];

        foreach ([
            ['/api/v1/admin/design-management/packages', 'GET'],
            ['/api/v1/admin/design-management/packages', 'POST'],
            ['/api/v1/admin/design-management/packages/1', 'GET'],
        ] as [$path, $method]) {
            foreach ($cases as [$status, $input, $expected]) {
                $request = $this->profiledRequest($path, $method);
                $original = JsonResponse::fromJsonString($input, $status);
                $original->headers->set('X-Fixture', 'preserved');
                $original->headers->setCookie(Cookie::create('fixture', 'opaque'));
                $expectedResponse = new JsonResponse(json_decode($expected, false, 512, JSON_THROW_ON_ERROR));

                $response = (new NormalizeAdminResponse)->handle($request, static fn () => $original);

                self::assertSame($expectedResponse->getContent(), $response->getContent(), $path.' '.$method.' '.$input);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('preserved', $response->headers->get('X-Fixture'));
                self::assertSame('opaque', $response->headers->getCookies()[0]->getValue());
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                self::assertSame('no-cache', $response->headers->get('Pragma'));
                self::assertSame('0', $response->headers->get('Expires'));
                $this->assertMarkersRestored($request);
                self::assertSame(1, $request->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['processing_phases']['response_normalize']['count']);
            }
        }
    }

    public function test_custom_response_keeps_associative_hook_and_recursive_arrayable_conversion(): void
    {
        $request = $this->profiledRequest('/api/v1/admin/design-management/packages', 'GET');
        $dto = Mockery::mock(Arrayable::class);
        $dto->shouldReceive('toArray')->once()->andReturn(['map' => []]);
        $original = Mockery::mock(JsonResponse::class);
        $original->shouldReceive('getData')->once()->with(true)->andReturn(['success' => true, 'data' => [['nested' => $dto]]]);
        $original->shouldReceive('getStatusCode')->andReturn(201);
        $original->headers = new ResponseHeaderBag;

        $response = (new NormalizeAdminResponse)->handle($request, static fn () => $original);

        self::assertSame([['nested' => ['map' => []]]], $response->getData(true)['data']);
        self::assertSame(201, $response->getStatusCode());
        $this->assertMarkersRestored($request);
    }

    public function test_custom_decode_failure_preserves_exception_and_restores_request_markers(): void
    {
        $request = $this->profiledRequest('/api/v1/admin/design-management/packages', 'GET');
        $sentinel = new RuntimeException('fixture');
        $original = Mockery::mock(JsonResponse::class);
        $original->shouldReceive('getData')->once()->with(true)->andThrow($sentinel);
        $original->shouldReceive('getStatusCode')->andReturn(200);
        $original->headers = new ResponseHeaderBag;
        try {
            (new NormalizeAdminResponse)->handle($request, static fn () => $original);
            self::fail('expected decode failure');
        } catch (RuntimeException $exception) {
            self::assertSame($sentinel, $exception);
        }
        $this->assertMarkersRestored($request);
        self::assertSame(1, $request->attributes->get(ApiQueryMetrics::REQUEST_ATTRIBUTE)->summary()['processing_phases']['response_normalize']['count']);
    }

    private function profiledRequest(string $path, string $method): Request
    {
        $request = Request::create($path, $method);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, new ApiQueryMetrics(true));
        $request->attributes->set(AdminResponse::NORMALIZATION_ATTRIBUTE, false);
        $request->attributes->set(AdminResponse::DECODED_ARRAY_ATTRIBUTE, 'previous');
        $this->app->instance('request', $request);

        return $request;
    }

    private function assertMarkersRestored(Request $request): void
    {
        self::assertFalse($request->attributes->get(AdminResponse::NORMALIZATION_ATTRIBUTE));
        self::assertSame('previous', $request->attributes->get(AdminResponse::DECODED_ARRAY_ATTRIBUTE));
    }
}
