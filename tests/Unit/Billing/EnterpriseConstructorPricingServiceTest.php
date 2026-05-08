<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\DataTransferObjects\Billing\EnterpriseConstructorSelection;
use App\Services\Billing\EnterpriseConstructorPricingService;
use Tests\TestCase;

final class EnterpriseConstructorPricingServiceTest extends TestCase
{
    public function test_base_constructor_costs_99000(): void
    {
        $result = $this->service()->preview(new EnterpriseConstructorSelection());

        self::assertSame(99000, $result['price']['total']);
        self::assertSame(100, $result['limits']['users']);
        self::assertSame(100, $result['limits']['foremen']);
        self::assertSame(500, $result['limits']['contractor_invitations']);
        self::assertFalse($result['requires_implementation_project']);
        self::assertTrue($result['can_checkout']);
    }

    public function test_constructor_with_250_users_costs_149000(): void
    {
        $result = $this->service()->preview(new EnterpriseConstructorSelection(users: 250));

        self::assertSame(149000, $result['price']['total']);
        self::assertSame(250, $result['limits']['users']);
        self::assertSame('До 250 пользователей', $result['selected_extensions'][0]['name']);
    }

    public function test_additional_organization_adds_15000(): void
    {
        $result = $this->service()->preview(new EnterpriseConstructorSelection(additionalOrganizations: 1));

        self::assertSame(114000, $result['price']['total']);
        self::assertSame(2, $result['limits']['organizations']);
    }

    public function test_integrations_and_migration_require_implementation_project(): void
    {
        $result = $this->service()->preview(new EnterpriseConstructorSelection(
            needsIntegrations: true,
            needsMigration: true
        ));

        self::assertTrue($result['requires_implementation_project']);
        self::assertFalse($result['can_checkout']);
        self::assertSame('Подготовить проект внедрения', $result['primary_cta']);
    }

    private function service(): EnterpriseConstructorPricingService
    {
        return new EnterpriseConstructorPricingService([
            'name' => 'Enterprise Конструктор',
            'base' => [
                'price' => 99000,
                'users' => 100,
                'foremen' => 100,
                'projects' => 100,
                'storage_gb' => 50,
                'ai_requests' => 2000,
                'contractor_invitations' => 500,
                'organizations' => 1,
            ],
            'extensions' => [
                'users_to_250' => ['price' => 50000, 'label' => 'До 250 пользователей'],
                'next_100_users' => ['price' => 35000, 'label' => 'Каждые следующие 100 пользователей'],
                'additional_organization' => ['price' => 15000, 'label' => 'Дополнительная организация'],
                'extended_ai' => ['price' => 10000, 'ai_requests' => 2000, 'label' => 'Расширенный AI'],
                'extra_storage_100gb' => ['price' => 7000, 'label' => 'Дополнительные 100 ГБ'],
                'priority_support' => ['price' => 25000, 'label' => 'Приоритетная поддержка'],
            ],
            'cta' => [
                'standard' => 'Рассчитать стоимость',
                'implementation_project' => 'Подготовить проект внедрения',
            ],
        ]);
    }
}
