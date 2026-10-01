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
    public function test_empty_ai_recommendations_do_not_hide_rule_recommendations(bool $useAi, bool $available, string $status): void
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
        for ($index = 0; $index < 12; $index++) {
            $facts->prepend(new ProjectPulseFact(id: 'warning-'.$index, type: 'schedule_task', priority: 'warning',
                title: 'Предупреждение', text: 'Проверить срок', category: 'schedule', source: 'schedule', nextAction: 'Проверить'));
        }

        $ruleEngine = new ProjectPulseRuleEngine();
        $ruleRecommendations = $ruleEngine->recommendations($facts);
        $synthesizer = new ProjectPulseAiSynthesizer(
            new class($available) implements LLMProviderInterface {
                public function __construct(private bool $available) {}
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
        self::assertCount(12, $result['recommendations']);
        self::assertSame($status, $result['ai_mode']['status']);
        self::assertSame('rules:schedule_task:56:overdue', $result['recommendations'][0]['id']);
        self::assertSame('Обновить график, ответственного и следующий контрольный срок.', $result['recommendations'][0]['action']);
    }

    public static function fallbackModes(): array
    {
        return [[true, true, 'active'], [false, true, 'rules_only'], [true, false, 'unavailable']];
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }
}
