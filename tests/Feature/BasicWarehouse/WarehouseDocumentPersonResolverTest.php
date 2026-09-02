<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseDocumentPersonResolver;
use Carbon\CarbonImmutable;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class WarehouseDocumentPersonResolverTest extends TestCase
{
    public function test_resolves_active_organization_owner_without_workforce_profile(): void
    {
        $context = AdminApiTestContext::create();
        $context->user->forceFill([
            'name' => 'technical_owner_login',
            'email' => 'technical-owner@example.test',
        ])->save();

        $resolved = app(WarehouseDocumentPersonResolver::class)->resolve(
            $context->user,
            (int) $context->organization->id,
            CarbonImmutable::parse('2026-09-02'),
        );

        $this->assertSame('Владелец организации', $resolved);
        $this->assertStringNotContainsString('technical_owner_login', $resolved);
        $this->assertStringNotContainsString('technical-owner@example.test', $resolved);
    }
}
