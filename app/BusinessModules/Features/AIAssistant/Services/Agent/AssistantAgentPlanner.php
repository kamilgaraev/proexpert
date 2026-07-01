<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantAgentDecision;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportIntentResolver;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSlotResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AssistantAgentPlanner
{
    private AssistantReportIntentResolver $reportIntentResolver;

    private AssistantReportSlotResolver $reportSlotResolver;

    private AssistantRequestUnderstandingResolver $requestUnderstandingResolver;

    private AssistantToolEligibilityPolicy $toolEligibilityPolicy;

    public function __construct(
        private AssistantCapabilityCatalog $catalog,
        private AssistantPeriodResolver $periodResolver,
        ?AssistantReportIntentResolver $reportIntentResolver = null,
        ?AssistantReportSlotResolver $reportSlotResolver = null,
        ?AssistantRequestUnderstandingResolver $requestUnderstandingResolver = null,
        ?AssistantToolEligibilityPolicy $toolEligibilityPolicy = null
    ) {
        $this->reportIntentResolver = $reportIntentResolver ?? new AssistantReportIntentResolver;
        $this->reportSlotResolver = $reportSlotResolver ?? new AssistantReportSlotResolver($periodResolver);
        $this->requestUnderstandingResolver = $requestUnderstandingResolver ?? new AssistantRequestUnderstandingResolver;
        $this->toolEligibilityPolicy = $toolEligibilityPolicy ?? new AssistantToolEligibilityPolicy;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function decide(string $message, array $context, ?AssistantTaskState $pendingState = null): AssistantAgentDecision
    {
        $requestUnderstanding = $this->requestUnderstandingResolver->resolve($message, $context);
        if ($this->blocksReportPlanning($requestUnderstanding)) {
            return new AssistantAgentDecision(type: 'answer');
        }

        if ($pendingState instanceof AssistantTaskState && $pendingState->status === 'waiting_for_slots') {
            if ($pendingState->id === 'report.unspecified') {
                return $this->continueUnspecifiedReport($message, $context, $pendingState);
            }

            $task = $this->catalog->findById($pendingState->id);
            $state = $this->fillState($pendingState, $message, $context);

            return $this->decisionForState($state, $task);
        }

        $reportIntent = $this->reportIntentResolver->resolve($message, $context);
        if (($reportIntent['status'] ?? null) === 'matched' && ($reportIntent['definition'] ?? null) instanceof AssistantReportDefinition) {
            $this->logReportIntent('report_intent_detected', $reportIntent);
            $task = $reportIntent['definition']->toAgentTask();
            $state = $this->fillState($this->makeState($task, $message), $message, $context);

            return $this->decisionForState($state, $task);
        }

        if (in_array($reportIntent['status'] ?? null, ['missing_type', 'ambiguous'], true)) {
            $this->logReportIntent('report_slot_missing', $reportIntent, ['slot' => 'report_type']);

            return new AssistantAgentDecision(
                type: 'ask_clarification',
                state: $this->unspecifiedReportState($message),
                clarificationQuestion: $this->reportTypeQuestion($reportIntent['candidates'] ?? [])
            );
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
     */
    private function continueUnspecifiedReport(
        string $message,
        array $context,
        AssistantTaskState $pendingState
    ): AssistantAgentDecision {
        if ($this->isAnyReportChoice($message)) {
            $definition = $this->reportIntentResolver->defaultReportDefinition();

            if ($definition instanceof AssistantReportDefinition) {
                $task = $definition->toAgentTask();
                $state = $this->fillState(
                    $this->makeState($task, $pendingState->sourceMessage),
                    $pendingState->sourceMessage.' '.$message,
                    $context
                );

                return $this->decisionForState($state, $task);
            }
        }

        $reportIntent = $this->reportIntentResolver->resolve($message, $context);

        if (($reportIntent['status'] ?? null) === 'matched' && ($reportIntent['definition'] ?? null) instanceof AssistantReportDefinition) {
            $this->logReportIntent('report_intent_detected', $reportIntent);
            $task = $reportIntent['definition']->toAgentTask();
            $state = $this->fillState($this->makeState($task, $pendingState->sourceMessage), $message, $context);

            return $this->decisionForState($state, $task);
        }

        return new AssistantAgentDecision(
            type: 'ask_clarification',
            state: $pendingState,
            clarificationQuestion: $this->reportTypeQuestion($reportIntent['candidates'] ?? [])
        );
    }

    private function isAnyReportChoice(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        return preg_match('/\b(любой|любые|любую|на твой выбор|выбери сам|без разницы|неважно)\b/u', $normalized) === 1;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function matchTask(string $message, array $context): ?array
    {
        return $this->catalog->match($message, $context);
    }

    private function blocksReportPlanning(AssistantRequestUnderstanding $requestUnderstanding): bool
    {
        return ! $this->toolEligibilityPolicy
            ->canExposeTool('generate_operational_pdf_report', $requestUnderstanding)
            ->allowed;
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

    private function unspecifiedReportState(string $message): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.unspecified',
            domain: 'reports',
            capability: 'reports',
            toolName: '',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('report_type', true),
            ],
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
                $state = $state->withSlotValue('period', $period->toArray());
            }
        }

        foreach ([
            'project_id' => 'project',
            'warehouse_id' => 'warehouse',
            'contractor_id' => 'contractor',
            'user_id' => 'user',
        ] as $slotName => $entityType) {
            if ($this->hasStateSlot($state, $slotName) && $state->slotValue($slotName) === null) {
                $entity = $this->reportSlotResolver->entityFromContext($context, $entityType);
                if ($entity !== null) {
                    $state = $state->withSlotValue($slotName, $entity['id'], $entity['label']);
                }
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
        return $this->reportSlotResolver->resolvePeriod($message);
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function decisionForState(AssistantTaskState $state, ?array $task): AssistantAgentDecision
    {
        $missingSlots = $state->missingRequiredSlotNames();
        if ($missingSlots !== []) {
            $this->logInfo('report_slot_missing', [
                'task_id' => $state->id,
                'tool_name' => $state->toolName,
                'slot' => $missingSlots[0],
            ]);

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

                if ($question !== '') {
                    return $question;
                }
            }
        }

        return match ($slotName) {
            'period' => 'За какой период сформировать отчет?',
            'report_type' => 'Какой отчет нужно сформировать?',
            default => 'Уточните недостающие данные, чтобы я мог продолжить.',
        };
    }

    /**
     * @param  AssistantReportDefinition[]  $candidates
     */
    private function reportTypeQuestion(array $candidates): string
    {
        $labels = array_values(array_unique(array_map(
            static fn (AssistantReportDefinition $definition): string => $definition->label,
            array_filter($candidates, static fn (mixed $candidate): bool => $candidate instanceof AssistantReportDefinition)
        )));

        if ($labels === []) {
            return 'Какой отчет нужно сформировать? Например: по графику работ, движению материалов, рентабельности, остаткам склада или платежам договоров.';
        }

        return 'Какой отчет нужно сформировать? Доступные варианты: '.implode(', ', array_slice($labels, 0, 8)).'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function toolArguments(AssistantTaskState $state): array
    {
        return $this->reportSlotResolver->toolArguments($state);
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

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $context
     */
    private function logReportIntent(string $event, array $intent, array $context = []): void
    {
        $definition = $intent['definition'] ?? null;

        $this->logInfo($event, array_filter([
            'status' => is_string($intent['status'] ?? null) ? $intent['status'] : null,
            'report_type' => $definition instanceof AssistantReportDefinition ? $definition->id : null,
            'tool_name' => $definition instanceof AssistantReportDefinition ? $definition->toolName : null,
            ...$context,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logInfo(string $event, array $context): void
    {
        try {
            Log::info($event, $context);
        } catch (Throwable) {
        }
    }
}
