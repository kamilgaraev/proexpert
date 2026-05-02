<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\AIAssistant\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseAiSynthesizer;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseRuleEngine;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class ProjectPulseAiSynthesizerTest extends TestCase
{
    public function test_empty_ai_recommendations_do_not_hide_rule_recommendations(): void
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'ai-assistant' => [
                'project_pulse' => [
                    'ai_enabled' => true,
                    'limits' => [
                        'recommendations' => 12,
                    ],
                ],
                'llm' => [
                    'provider' => 'test',
                ],
            ],
        ]));
        Container::setInstance($container);

        $facts = collect([
            new ProjectPulseFact(
                id: 'schedule_task:56:overdue',
                type: 'schedule_task',
                priority: 'critical',
                title: 'Задача графика просрочена',
                text: 'Задача графика не закрыта в срок.',
                projectId: 56,
                projectName: 'Строительство склада Литер А',
                source: 'schedule',
                category: 'schedule',
                nextAction: 'Обновить график, ответственного и следующий контрольный срок.',
            ),
        ]);

        $ruleEngine = new ProjectPulseRuleEngine();
        $ruleRecommendations = $ruleEngine->recommendations($facts);
        $synthesizer = new ProjectPulseAiSynthesizer(
            new class implements LLMProviderInterface {
                public function chat(array $messages, array $options = []): array
                {
                    return [
                        'content' => json_encode([
                            'summary' => [
                                'title' => 'Есть критичные вопросы',
                                'text' => 'Нужно проверить график.',
                            ],
                            'recommendations' => [],
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                }

                public function countTokens(string $text): int
                {
                    return 0;
                }

                public function isAvailable(): bool
                {
                    return true;
                }

                public function getModel(): string
                {
                    return 'test';
                }
            },
            $ruleEngine,
        );

        $result = $synthesizer->synthesize(
            $facts,
            $ruleRecommendations,
            true,
            [],
            [],
            null,
        );

        self::assertNotEmpty($result['recommendations']);
        self::assertSame('rules:schedule_task:56:overdue', $result['recommendations'][0]['id']);
        self::assertSame('Обновить график, ответственного и следующий контрольный срок.', $result['recommendations'][0]['action']);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
}
