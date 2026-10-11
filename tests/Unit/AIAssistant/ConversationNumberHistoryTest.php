<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ConversationNumberHistoryTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_owner_can_recall_completed_source_free_calculation_without_business_fact_status(): void
    {
        $content = $this->context();
        self::assertStringContainsString('12 + 8 + 5 = 25', $content);
        self::assertStringContainsString('не подтверждённые данные МОСТ', $content);
    }

    public function test_calculation_history_keeps_pending_owner_and_business_source_boundaries(): void
    {
        foreach ([
            ['conversation_number_context' => false],
            ['conversation_number_context' => 'true'],
            ['request_state' => 'pending'],
            ['source_refs' => [['entity_type' => 'estimate', 'entity_id' => 5]]],
            ['source_refs' => null],
            ['actor_user_id' => 8],
            ['validation_status' => 'verified'],
            ['validation_status' => 'failed'],
            ['validation_status' => null],
            ['validation_status' => 'partial', 'request_state' => 'pending'],
            ['validation_status' => 'partial', 'source_refs' => [['entity_type' => 'estimate', 'entity_id' => 5]]],
            ['validation_status' => 'partial', 'conversation_number_context' => false],
        ] as $metadata) {
            self::assertSame('', $this->context($metadata));
        }
        self::assertSame('', $this->context(actorId: 8));
        self::assertSame('', $this->context(actorId: null));
    }

    public function test_partial_source_free_calculation_is_kept_with_its_conditional_label(): void
    {
        $content = $this->context(['validation_status' => 'partial']);
        self::assertStringContainsString('12 + 8 + 5 = 25', $content);
        self::assertStringContainsString('не подтверждённые данные МОСТ', $content);
        self::assertSame('', $this->context(['validation_status' => 'partial'], actorId: 8));
    }

    private function context(array $metadata = [], ?int $actorId = 7): string
    {
        $manager = (new ReflectionClass(ConversationManager::class))->newInstanceWithoutConstructor();
        $message = new Message;
        $message->role = 'assistant';
        $message->content = 'Учебный результат: 12 + 8 + 5 = 25.';
        $message->metadata = array_merge([
            'conversation_number_context' => true,
            'source_refs' => [],
            'validation_status' => 'unverified',
            'actor_user_id' => 7,
            'request_state' => 'completed',
        ], $metadata);
        $actor = $actorId === null ? null : new User;
        if ($actor !== null) {
            $actor->id = $actorId;
        }

        return (new ReflectionMethod(ConversationManager::class, 'contextContent'))->invoke($manager, $message, $actor, 1);
    }
}
