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
use PHPUnit\Framework\Attributes\DataProvider;

class ProjectPulseAiSynthesizerTest extends TestCase
{
    #[DataProvider('fallbackModes')]
    public function test_recommendations_preserve_rule_identity_without_trusting_ai_identity(bool $useAi, bool $available, string $status, bool $hasAiRecommendations = false): void
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
                relatedEntity: ['type' => 'schedule_task', 'id' => 56, 'label' => 'Учебная задача'],
            ),
        ]);
        for ($index = 0; $index < 12; $index++) {
            $facts->prepend(new ProjectPulseFact(id: 'warning-'.$index, type: 'schedule_task', priority: 'warning',
                title: 'Предупреждение', text: 'Проверить срок', category: 'schedule', source: 'schedule', nextAction: 'Проверить'));
        }

        $ruleEngine = new ProjectPulseRuleEngine();
        $ruleRecommendations = $ruleEngine->recommendations($facts);
        $synthesizer = new ProjectPulseAiSynthesizer(
            new class($available, $hasAiRecommendations) implements LLMProviderInterface {
                public function __construct(private bool $available, private bool $hasAiRecommendations) {}
                public function chat(array $messages, array $options = []): array
                {
                    return [
                        'content' => json_encode([
                            'summary' => [
                                'title' => 'Есть критичные вопросы',
                                'text' => 'Нужно проверить график.',
                            ],
                            'recommendations' => $this->hasAiRecommendations ? [[
                                'priority' => 'high', 'title' => 'Проверить срок',
                                'action' => 'Актуализировать график', 'reason' => 'Есть просрочка',
                                'related_entity' => ['type' => 'site_request', 'id' => 999999, 'label' => 'Неподтверждённая сущность'],
                            ]] : [],
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                }

                public function countTokens(string $text): int
                {
                    return 0;
                }

                public function isAvailable(): bool
                {
                    return $this->available;
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
            $useAi,
            [],
            [],
            null,
        );

        self::assertNotEmpty($result['recommendations']);
        self::assertSame($status, $result['ai_mode']['status']);
        if ($hasAiRecommendations) {
            self::assertCount(1, $result['recommendations']);
            self::assertSame('ai', $result['recommendations'][0]['source']);
            self::assertNull($result['recommendations'][0]['related_entity'] ?? null);

            return;
        }

        self::assertCount(12, $result['recommendations']);
        self::assertSame('rules:schedule_task:56:overdue', $result['recommendations'][0]['id']);
        self::assertSame('Обновить график, ответственного и следующий контрольный срок.', $result['recommendations'][0]['action']);
        self::assertSame(['type' => 'schedule_task', 'id' => 56, 'label' => 'Учебная задача'], $result['recommendations'][0]['related_entity'] ?? null);
    }

    public static function fallbackModes(): array
    {
        return [[true, true, 'active'], [false, true, 'rules_only'], [true, false, 'unavailable'], [true, true, 'active', true]];
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
}
