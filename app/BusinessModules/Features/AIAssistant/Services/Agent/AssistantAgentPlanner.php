<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantAgentDecision;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;

final readonly class AssistantAgentPlanner
{
    public function __construct(
        private AssistantCapabilityCatalog $catalog,
        private AssistantPeriodResolver $periodResolver
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function decide(string $message, array $context, ?AssistantTaskState $pendingState = null): AssistantAgentDecision
    {
        if ($pendingState instanceof AssistantTaskState && $pendingState->status === 'waiting_for_slots') {
            $task = $this->catalog->findById($pendingState->id);
            $state = $this->fillState($pendingState, $message, $context);

            return $this->decisionForState($state, $task);
        }

        $task = $this->matchTask($message, $context);
        if ($task === null) {
            return new AssistantAgentDecision(
                type: 'answer',
                clarificationQuestion: 'Пока не нашел подходящее действие. Уточните, какой отчет или операцию нужно выполнить.'
            );
        }

        $state = $this->fillState($this->makeState($task, $message), $message, $context);

        return $this->decisionForState($state, $task);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function matchTask(string $message, array $context): ?array
    {
        return $this->catalog->match($message, $context);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function makeState(array $task, string $message): AssistantTaskState
    {
        return new AssistantTaskState(
            id: (string) $task['id'],
            domain: (string) $task['domain'],
            capability: (string) $task['capability'],
            toolName: (string) $task['tool_name'],
            status: 'waiting_for_slots',
            slots: $this->makeSlots($task),
            sourceMessage: $message
        );
    }

    /**
     * @param  array<string, mixed>  $task
     * @return AssistantTaskSlot[]
     */
    private function makeSlots(array $task): array
    {
        $slots = [];

        foreach (($task['required_slots'] ?? []) as $slot) {
            if (is_array($slot)) {
                $slots[] = new AssistantTaskSlot((string) ($slot['name'] ?? ''), true);
            }
        }

        foreach (($task['optional_slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $name = (string) ($slot['name'] ?? '');
            if ($name !== '' && ! $this->hasSlot($slots, $name)) {
                $slots[] = new AssistantTaskSlot($name, false);
            }
        }

        return $slots;
    }

    /**
     * @param  AssistantTaskSlot[]  $slots
     */
    private function hasSlot(array $slots, string $name): bool
    {
        foreach ($slots as $slot) {
            if ($slot instanceof AssistantTaskSlot && $slot->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillState(AssistantTaskState $state, string $message, array $context): AssistantTaskState
    {
        if ($this->hasStateSlot($state, 'period') && $state->slotValue('period') === null) {
            $period = $this->resolvePeriod($message);
            if ($period instanceof AssistantResolvedPeriod) {
                $state = $state->withSlotValue('period', [
                    'date_from' => $period->dateFrom,
                    'date_to' => $period->dateTo,
                    'label' => $period->label,
                    'source_text' => $period->sourceText,
                ]);
            }
        }

        if ($this->hasStateSlot($state, 'project_id') && $state->slotValue('project_id') === null) {
            $project = $this->projectFromContext($context);
            if ($project !== null) {
                $state = $state->withSlotValue('project_id', $project['id'], $project['label']);
            }
        }

        return $this->stateWithStatus(
            $state,
            $state->missingRequiredSlotNames() === [] ? 'ready_to_execute' : 'waiting_for_slots'
        );
    }

    private function hasStateSlot(AssistantTaskState $state, string $name): bool
    {
        foreach ($state->slots as $slot) {
            if ($slot instanceof AssistantTaskSlot && $slot->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function resolvePeriod(string $message): ?AssistantResolvedPeriod
    {
        return $this->periodResolver->resolve($message);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{id: int|string, label: string|null}|null
     */
    private function projectFromContext(array $context): ?array
    {
        $entityRefs = $context['entity_refs'] ?? null;
        if (! is_array($entityRefs)) {
            return null;
        }

        foreach ($entityRefs as $ref) {
            if (! is_array($ref) || ($ref['type'] ?? null) !== 'project' || ! array_key_exists('id', $ref)) {
                continue;
            }

            $id = $ref['id'];
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            return [
                'id' => is_numeric($id) ? (int) $id : $id,
                'label' => isset($ref['label']) && is_scalar($ref['label']) ? (string) $ref['label'] : null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function decisionForState(AssistantTaskState $state, ?array $task): AssistantAgentDecision
    {
        $missingSlots = $state->missingRequiredSlotNames();
        if ($missingSlots !== []) {
            return new AssistantAgentDecision(
                type: 'ask_clarification',
                state: $this->stateWithStatus($state, 'waiting_for_slots'),
                clarificationQuestion: $this->questionForSlot($task, $missingSlots[0])
            );
        }

        $readyState = $this->stateWithStatus($state, 'ready_to_execute');

        return new AssistantAgentDecision(
            type: 'execute_tool',
            state: $readyState,
            toolName: $readyState->toolName,
            toolArguments: $this->toolArguments($readyState)
        );
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function questionForSlot(?array $task, string $slotName): string
    {
        foreach (($task['required_slots'] ?? []) as $slot) {
            if (is_array($slot) && ($slot['name'] ?? null) === $slotName && isset($slot['question']) && is_string($slot['question'])) {
                $question = $slot['question'];

                return $question !== '' ? $question : 'Уточните недостающие данные для продолжения.';
            }
        }

        return $slotName === 'period'
            ? 'За какой период сформировать отчет?'
            : 'Уточните недостающие данные для продолжения.';
    }

    /**
     * @return array<string, mixed>
     */
    private function toolArguments(AssistantTaskState $state): array
    {
        $period = $state->slotValue('period');
        $periodData = is_array($period) ? $period : [];

        $arguments = [
            'period' => isset($periodData['source_text']) && is_scalar($periodData['source_text'])
                ? (string) $periodData['source_text']
                : null,
            'date_from' => isset($periodData['date_from']) && is_scalar($periodData['date_from'])
                ? (string) $periodData['date_from']
                : null,
            'date_to' => isset($periodData['date_to']) && is_scalar($periodData['date_to'])
                ? (string) $periodData['date_to']
                : null,
            'project_id' => $state->slotValue('project_id'),
        ];

        return array_filter($arguments, static fn (mixed $value): bool => $value !== null);
    }

    private function stateWithStatus(AssistantTaskState $state, string $status): AssistantTaskState
    {
        return new AssistantTaskState(
            id: $state->id,
            domain: $state->domain,
            capability: $state->capability,
            toolName: $state->toolName,
            status: $status,
            slots: $state->slots,
            sourceMessage: $state->sourceMessage
        );
    }
}
