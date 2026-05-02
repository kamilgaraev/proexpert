<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\AIAssistant\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseRuleEngine;
use PHPUnit\Framework\TestCase;

class ProjectPulseRuleEngineTest extends TestCase
{
    public function test_engine_builds_categories_groups_and_next_actions(): void
    {
        $facts = collect([
            new ProjectPulseFact(
                id: 'purchase_request:2:no_order',
                type: 'purchase_request',
                priority: 'warning',
                title: 'Согласована, но заказ поставщику не создан',
                text: 'По заявке нет заказа.',
                amount: 35000.0,
                source: 'procurement',
                category: 'procurement',
                nextAction: 'Создать заказ поставщику.',
                primaryAction: ['label' => 'Открыть заявку', 'route' => '/procurement/purchase-requests/2'],
                ageDays: 2,
            ),
        ]);

        $engine = new ProjectPulseRuleEngine();

        $categories = $engine->categories($facts);
        $groups = $engine->groups($facts);
        $nextActions = $engine->nextActions($facts);

        self::assertSame('procurement', $categories[0]['key']);
        self::assertSame('procurement', $categories[0]['label']);
        self::assertSame('warning', $categories[0]['status']);
        self::assertSame(35000.0, $categories[0]['amount']);
        self::assertSame('requires_action', $groups[0]['key']);
        self::assertSame('/procurement/purchase-requests/2', $nextActions[0]['primary_action']['route']);
    }
}
