<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\AIAssistant\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use PHPUnit\Framework\TestCase;

class ProjectPulseFactTest extends TestCase
{
    public function test_fact_exports_full_management_contract(): void
    {
        $fact = new ProjectPulseFact(
            id: 'purchase_request:2:no_order',
            type: 'purchase_request',
            priority: 'warning',
            title: 'Согласована, но заказ поставщику не создан',
            text: 'По согласованной закупочной заявке 33-202604-0001 еще не оформлен заказ поставщику.',
            projectId: 56,
            projectName: 'Строительство склада Литер А',
            relatedEntity: [
                'type' => 'purchase_request',
                'id' => 2,
                'label' => 'Заявка на закупку 33-202604-0001',
                'route' => '/procurement/purchase-requests/2',
            ],
            amount: 35000.0,
            occurredAt: '2026-04-30T12:00:00+03:00',
            source: 'procurement',
            category: 'procurement',
            status: 'approved',
            nextAction: 'Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.',
            primaryAction: [
                'label' => 'Открыть заявку',
                'route' => '/procurement/purchase-requests/2',
                'permission' => 'procurement.purchase_requests.view',
            ],
            deadline: null,
            ageDays: 2,
            ownerName: null,
        );

        $payload = $fact->toArray();

        self::assertSame('procurement', $payload['source']);
        self::assertSame('procurement', $payload['category']);
        self::assertSame('approved', $payload['status']);
        self::assertSame('Создать заказ поставщику и зафиксировать поставщика, сроки и сумму.', $payload['next_action']);
        self::assertSame('/procurement/purchase-requests/2', $payload['primary_action']['route']);
        self::assertSame(2, $payload['age_days']);
        self::assertSame(35000.0, $payload['amount']);
    }
}
