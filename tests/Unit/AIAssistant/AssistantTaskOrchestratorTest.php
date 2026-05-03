<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantTaskOrchestratorTest extends TestCase
{
    #[DataProvider('russianIntentProvider')]
    public function test_plan_routes_russian_intent_keywords(string $query, string $expectedTaskType): void
    {
        $orchestrator = $this->makeOrchestrator();

        $plan = $orchestrator->plan($query, [], [
            'organization_id' => 15,
            'permissions_flat' => [],
        ]);

        $this->assertSame($expectedTaskType, $plan['task_type']);
    }

    public static function russianIntentProvider(): array
    {
        return [
            'navigate' => ['открой график проекта', 'navigate'],
            'act' => ['создай задачу в графике', 'act'],
            'find' => ['найди проект Дом 300', 'find'],
            'analyze' => ['проанализируй риски по срокам', 'analyze'],
            'wizard' => ['помоги оформить заявку пошагово', 'wizard'],
        ];
    }

    private function makeOrchestrator(): AssistantTaskOrchestrator
    {
        $registry = $this->createMock(AssistantCapabilityRegistry::class);
        $registry->method('match')->willReturn(null);

        $accessContextResolver = $this->createMock(AssistantAccessContextResolver::class);
        $accessContextResolver->method('toPublicContext')->willReturn([
            'organization_id' => 15,
            'available_modules' => [],
            'permission_count' => 0,
            'is_read_only' => true,
            'allowed_action_types' => ['summary', 'find', 'analyze', 'navigate'],
        ]);

        return new AssistantTaskOrchestrator($registry, $accessContextResolver);
    }
}
