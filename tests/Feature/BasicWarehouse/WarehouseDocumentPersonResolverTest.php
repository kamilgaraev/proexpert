<?php

declare(strict_types=1);

namespace Tests\Feature\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseDocumentPersonResolver;
use App\Models\User;
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

    public function test_does_not_use_account_login_as_formal_name_without_workforce_profile(): void
    {
        $context = AdminApiTestContext::create();
        $responsible = User::factory()->create([
            'name' => 'employee_technical_login',
            'email' => 'employee-technical@example.test',
        ]);
        $context->organization->users()->attach($responsible->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        $resolved = app(WarehouseDocumentPersonResolver::class)->resolve(
            $responsible,
            (int) $context->organization->id,
            CarbonImmutable::parse('2026-09-02'),
        );

        $this->assertSame('ФИО не указано', $resolved);
        $this->assertStringNotContainsString('employee_technical_login', $resolved);
        $this->assertStringNotContainsString('employee-technical@example.test', $resolved);
    }
}
