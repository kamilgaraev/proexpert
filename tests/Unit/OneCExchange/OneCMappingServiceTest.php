<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Enums\OneCExchangeScope;
use App\Models\Organization;
use App\Services\OneCExchange\OneCMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OneCMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_keeps_mapping_scoped_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $service = app(OneCMappingService::class);

        $mapping = $service->upsert((int) $organization->id, [
            'scope' => OneCExchangeScope::Materials->value,
            'external_id' => '1c-material-1',
            'external_name' => 'Цемент М500',
            'local_type' => 'materials',
            'local_id' => 10,
            'payload' => ['unit' => 'кг'],
        ]);

        self::assertSame((int) $organization->id, (int) $mapping->organization_id);
        self::assertSame(['unit' => 'кг'], $mapping->payload);
        self::assertCount(1, $service->list((int) $organization->id, OneCExchangeScope::Materials->value));
    }
}
