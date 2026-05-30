<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Http\Responses\AdminResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

final class AdminResponseResourceCollectionTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

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
}
