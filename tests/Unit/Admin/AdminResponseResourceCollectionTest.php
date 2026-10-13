<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

final class AdminResponseResourceCollectionTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_it_preserves_paginated_resource_collection_metadata(): void
    {
        $paginator = new LengthAwarePaginator(
            [['id' => 1]],
            25,
            10,
            2,
            ['path' => '/api/v1/admin/projects']
        );

        $response = AdminResponse::success(JsonResource::collection($paginator));
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame([['id' => 1]], $payload['data']['data']);
        $this->assertSame(25, $payload['data']['meta']['total']);
        $this->assertSame(2, $payload['data']['meta']['current_page']);
        $this->assertArrayHasKey('links', $payload['data']);
    }

    public function test_it_keeps_plain_resource_collections_as_arrays(): void
    {
        $response = AdminResponse::success(JsonResource::collection(collect([['id' => 1]])));
        $payload = $response->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertSame([['id' => 1]], $payload['data']);
    }

    public function test_it_preserves_native_collection_metadata_and_response_hook(): void
    {
        $collection = new class(collect([['id' => 1, 'nested' => [null, false, 2.5, ['label' => 'fixture']]]])) extends ResourceCollection
        {
            public function toArray(Request $request): array
            {
                return $this->collection->all();
            }

            public function with(Request $request): array
            {
                return ['meta' => ['total' => 1], 'summary' => ['count' => 1], 'links' => ['next' => null]];
            }

            public function withResponse(Request $request, JsonResponse $response): void
            {
                $payload = $response->getData(true);
                $payload['summary']['hook'] = true;
                $response->setData($payload);
                $response->headers->set('X-Resource-Fixture', 'inner');
            }
        };

        $response = AdminResponse::paginated($collection, ['total' => 1], 'fixture', 201, ['outer' => 1], ['next' => null]);
        $payload = $response->getData(true);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([['id' => 1, 'nested' => [null, false, 2.5, ['label' => 'fixture']]]], $payload['data']['data']);
        $this->assertSame(['total' => 1], $payload['data']['meta']);
        $this->assertSame(['count' => 1, 'hook' => true], $payload['data']['summary']);
        $this->assertSame(['next' => null], $payload['data']['links']);
        $this->assertSame(['total' => 1], $payload['meta']);
        $this->assertSame(['outer' => 1], $payload['summary']);
        $this->assertSame(['next' => null], $payload['links']);
        $this->assertNull($response->headers->get('X-Resource-Fixture'));
    }

    public function test_it_preserves_custom_json_response_conversion(): void
    {
        $dto = Mockery::mock(Arrayable::class);
        $dto->shouldReceive('toArray')->once()->andReturn(['fixture' => true]);
        $inner = Mockery::mock(JsonResponse::class);
        $inner->shouldReceive('getData')->once()->with(true)->andReturn(['data' => [['nested' => $dto]]]);
        $collection = Mockery::mock(ResourceCollection::class);
        $collection->shouldReceive('response')->once()->andReturn($inner);

        $payload = AdminResponse::success($collection)->getData(true);

        $this->assertSame([['nested' => ['fixture' => true]]], $payload['data']);
    }
}
