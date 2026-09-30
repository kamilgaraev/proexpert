<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Projects\GetProjectStatusAction;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantGreetingContextTest extends TestCase
{
    public function test_greeting_does_not_read_project_status(): void
    {
        $builder = new class($this->createMock(IntentRecognizer::class), $this->createMock(LoggingService::class)) extends ContextBuilder
        {
            public function actionClass(string $intent): ?string
            {
                return $this->getActionClass($intent);
            }
        };

        $this->assertNull($builder->actionClass('greeting'));
        $this->assertSame(GetProjectStatusAction::class, $builder->actionClass('project_status'));
    }

    public function test_standalone_greeting_accepts_actual_navigation_provenance(): void
    {
        $this->assertTrue($this->isStandaloneGreeting('Привет', $this->navigationPayload()));
    }

    #[DataProvider('semanticBlockers')]
    public function test_greeting_keeps_semantic_context_on_the_full_path(array $replacement): void
    {
        $this->assertFalse($this->isStandaloneGreeting('Привет', array_replace_recursive($this->navigationPayload(), $replacement)));
    }

    public static function semanticBlockers(): array
    {
        return [
            'history' => [['conversation_id' => 12]],
            'goal' => [['goal' => 'summary']],
            'mode' => [['desired_mode' => 'grounded']],
            'actions' => [['allow_actions' => true]],
            'attachment' => [['attachment_ids' => ['attachment-fixture']]],
            'period' => [['context' => ['period' => ['from' => '2026-09-01']]]],
            'filters' => [['context' => ['filters' => ['project_id' => 7]]]],
            'entity' => [['context' => ['entity_refs' => [['type' => 'estimate', 'id' => 7]]]]],
            'multiple_entities' => [['context' => ['entity_refs' => [['type' => 'project', 'id' => 7], ['type' => 'project', 'id' => 8]]]]],
            'follow_up' => [['context' => ['ui_state' => ['assistant_follow_up_query' => 'Сравни остатки']]]],
        ];
    }

    public function test_navigation_provenance_does_not_expand_greeting_matching(): void
    {
        $this->assertFalse($this->isStandaloneGreeting('Привет, что у нас по бетону?', $this->navigationPayload()));
        $this->assertFalse($this->isStandaloneGreeting('Что у нас по бетону', $this->navigationPayload()));
    }

    private function navigationPayload(): array
    {
        return ['conversation_id' => null, 'goal' => null, 'desired_mode' => null, 'allow_actions' => false, 'attachment_ids' => [],
            'context' => ['source_module' => 'ai-assistant', 'source_route' => '/dashboard', 'period' => null, 'filters' => [],
                'entity_refs' => [['type' => 'project', 'id' => 7]], 'ui_state' => ['pathname' => '/dashboard', 'assistant_path' => '/ai-assistant']]];
    }

    private function isStandaloneGreeting(string $query, array $payload): bool
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(AIAssistantService::class, 'isStandaloneGreeting'))->invoke($service, $query, $payload);
    }
}
