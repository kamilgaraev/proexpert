<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use PHPUnit\Framework\TestCase;

class AssistantAgentStateStoreTest extends TestCase
{
    public function test_state_round_trips_through_conversation_context(): void
    {
        $conversation = new Conversation;
        $conversation->context = [];

        $state = new AssistantTaskState(
            id: 'report.project_timelines',
            domain: 'reports',
            capability: 'schedules',
            toolName: 'generate_project_timelines_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('project_id', true, 56, 'Строительство склада Литер А'),
                new AssistantTaskSlot('period', true, null, null),
            ],
            sourceMessage: 'Сделай отчет по графику работ'
        );

        $store = new AssistantAgentStateStore;
        $store->save($conversation, $state);

        $restored = $store->load($conversation);

        $this->assertInstanceOf(AssistantTaskState::class, $restored);
        $this->assertSame('report.project_timelines', $restored->id);
        $this->assertSame(['period'], $restored->missingRequiredSlotNames());
        $this->assertSame(56, $restored->slotValue('project_id'));
    }

    public function test_completed_state_is_cleared(): void
    {
        $conversation = new Conversation;
        $conversation->context = [
            'agent_state' => [
                'id' => 'report.project_timelines',
                'domain' => 'reports',
                'capability' => 'schedules',
                'tool_name' => 'generate_project_timelines_report',
                'status' => 'completed',
                'slots' => [],
                'source_message' => 'Сделай отчет',
            ],
        ];

        $store = new AssistantAgentStateStore;
        $store->clear($conversation);

        $this->assertArrayNotHasKey('agent_state', $conversation->context);
    }

    public function test_non_array_context_is_normalized_before_save(): void
    {
        $conversation = new Conversation;
        $conversation->setRawAttributes(['context' => '"legacy"'], true);

        $store = new AssistantAgentStateStore;
        $store->save($conversation, $this->makeState());

        $this->assertIsArray($conversation->context);
        $this->assertArrayHasKey('agent_state', $conversation->context);
    }

    public function test_existing_conversation_is_persisted_when_state_changes(): void
    {
        $conversation = new class extends Conversation
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $conversation->exists = true;
        $conversation->context = [];

        $store = new AssistantAgentStateStore;
        $store->save($conversation, $this->makeState());

        $this->assertTrue($conversation->saveCalled);
    }

    private function makeState(): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.project_timelines',
            domain: 'reports',
            capability: 'schedules',
            toolName: 'generate_project_timelines_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('project_id', true, 56, 'Строительство склада Литер А'),
                new AssistantTaskSlot('period', true, null, null),
            ],
            sourceMessage: 'Сделай отчет по графику работ'
        );
    }
}
