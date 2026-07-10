<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseOrderResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use PHPUnit\Framework\TestCase;

class PurchaseOrderResourceTest extends TestCase
{
    public function test_collection_accepts_laravel_generated_item_key(): void
    {
        $resources = PurchaseOrderResource::collection([new PurchaseOrder()]);

        $this->assertInstanceOf(PurchaseOrderResource::class, $resources->collection->first());
    }
}
