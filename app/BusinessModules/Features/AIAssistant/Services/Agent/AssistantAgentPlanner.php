<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantAgentDecision;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportIntentResolver;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSlotResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
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
    public function decide(string $message, array $context, ?AssistantTaskState $pendingState = null, ?callable $resolveEntityScope = null): AssistantAgentDecision
    {
        $requestUnderstanding = $this->requestUnderstandingResolver->resolve($message, $context);
        if (\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportIntent::matches($message, $requestUnderstanding)) {
            return new AssistantAgentDecision(type: 'answer');
        }
        if ($this->blocksReportPlanning($requestUnderstanding, $pendingState)) {
            return new AssistantAgentDecision(type: 'answer');
        }

        if ($pendingState instanceof AssistantTaskState && $pendingState->status === 'waiting_for_slots') {
            if ($pendingState->id === 'report.unspecified') {
                return $this->continueUnspecifiedReport($message, $context, $pendingState, $resolveEntityScope);
            }

            $task = $this->catalog->findById($pendingState->id);
            $state = $this->fillState($pendingState, $message, $context, $resolveEntityScope);

            return $this->decisionForState($state, $task);
        }

        $reportIntent = $this->reportIntentResolver->resolve($message, $context);
        if (($reportIntent['status'] ?? null) === 'matched' && ($reportIntent['definition'] ?? null) instanceof AssistantReportDefinition) {
            $this->logReportIntent('report_intent_detected', $reportIntent);
            $task = $reportIntent['definition']->toAgentTask();
            $state = $this->fillState($this->makeState($task, $message), $message, $context, $resolveEntityScope);

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

        $state = $this->fillState($this->makeState($task, $message), $message, $context, $resolveEntityScope);

        return $this->decisionForState($state, $task);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function continueUnspecifiedReport(
        string $message,
        array $context,
        AssistantTaskState $pendingState,
        ?callable $resolveEntityScope
    ): AssistantAgentDecision {
        if ($this->isAnyReportChoice($message)) {
            $definition = $this->reportIntentResolver->defaultReportDefinition();

            if ($definition instanceof AssistantReportDefinition) {
                $task = $definition->toAgentTask();
                $state = $this->fillState(
                    $this->makeState($task, $pendingState->sourceMessage),
                    $pendingState->sourceMessage.' '.$message,
                    $context,
                    $resolveEntityScope
                );

                return $this->decisionForState($state, $task);
            }
        }

        $reportIntent = $this->reportIntentResolver->resolve($message, $context);

        if (($reportIntent['status'] ?? null) === 'matched' && ($reportIntent['definition'] ?? null) instanceof AssistantReportDefinition) {
            $this->logReportIntent('report_intent_detected', $reportIntent);
            $task = $reportIntent['definition']->toAgentTask();
            $state = $this->fillState($this->makeState($task, $pendingState->sourceMessage), $message, $context, $resolveEntityScope);

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

    private function blocksReportPlanning(
        AssistantRequestUnderstanding $requestUnderstanding,
        ?AssistantTaskState $pendingState
    ): bool {
        if (
            $pendingState instanceof AssistantTaskState
            && $pendingState->status === 'waiting_for_slots'
            && str_starts_with($pendingState->id, 'report.')
            && ! $requestUnderstanding->blocksFileGeneration()
        ) {
            return false;
        }

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
    private function fillState(AssistantTaskState $state, string $message, array $context, ?callable $resolveEntityScope): AssistantTaskState
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
            'contract_id' => 'contract',
        ] as $slotName => $entityType) {
            if (! $this->hasStateSlot($state, $slotName)) {
                if ($this->namedEntityQuery($message, $entityType) !== null
                    || $this->namedEntityQuery($state->sourceMessage, $entityType) !== null
                    || $this->refersToCurrentEntity($message, $entityType)
                    || $this->refersToCurrentEntity($state->sourceMessage, $entityType)) {
                    $slots = $state->slots;
                    if (! $this->hasStateSlot($state, '__unsupported_scope')) {
                        $slots[] = new AssistantTaskSlot('__unsupported_scope', true);
                    }
                    $state = new AssistantTaskState($state->id, $state->domain, $state->capability, $state->toolName,
                        $state->status, $slots, $state->sourceMessage);
                }

                continue;
            }
            $scopeSlot = '__scope_'.$entityType;
            $namedQuery = $this->namedEntityQuery($message, $entityType);
            $requestedCurrent = $this->refersToCurrentEntity($message, $entityType);
            $currentScope = $requestedCurrent || $this->refersToCurrentEntity($state->sourceMessage, $entityType);
            $query = $namedQuery ?? $state->slotValue($scopeSlot) ?? $this->namedEntityQuery($state->sourceMessage, $entityType);
            $query = ! $requestedCurrent && is_string($query) ? $query : null;
            if ($namedQuery === null && ($state->slotValue($scopeSlot) === '__organization' && ! $requestedCurrent
                || preg_match('/\b(?:всей\s+организации|всем\s+проектам)\b/iu', $message) === 1)) {
                $slots = array_values(array_filter($state->slots, static fn (AssistantTaskSlot $slot): bool => $slot->name !== $scopeSlot));
                $slots = array_map(static fn (AssistantTaskSlot $slot): AssistantTaskSlot => $slot->name === $slotName
                    ? new AssistantTaskSlot($slotName, false) : $slot, $slots);
                $slots[] = new AssistantTaskSlot($scopeSlot, false, '__organization');
                $state = new AssistantTaskState($state->id, $state->domain, $state->capability, $state->toolName,
                    $state->status, $slots, $state->sourceMessage);

                continue;
            }
            $contextEntity = $this->contextEntityScope($context, $entityType);
            $id = $query === null ? ($requestedCurrent
                ? ($contextEntity['id'] ?? $state->slotValue($slotName))
                : ($state->slotValue($slotName) ?? $contextEntity['id'] ?? null)) : null;
            if ($query === null && $id === null && ! $currentScope) {
                continue;
            }
            $numericId = (is_int($id) || is_string($id)) && preg_match('/^[1-9]\d*$/', (string) $id) === 1 ? (int) $id : null;
            if ($query !== null && preg_match('/^(?:id\s*|№\s*)?([1-9]\d*)$/iu', $query, $idMatch) === 1) {
                $numericId = (int) $idMatch[1];
                $query = null;
            }
            $entity = null;
            try {
                if ($resolveEntityScope !== null && ($query !== null || $numericId !== null)) {
                    $entity = $resolveEntityScope($entityType, $query, $numericId);
                }
            } catch (Throwable) {
            }
            $validId = is_array($entity) && is_int($entity['id'] ?? null) && $entity['id'] > 0
                ? $entity['id'] : null;
            $slots = array_map(
                static fn (AssistantTaskSlot $slot): AssistantTaskSlot => $slot->name === $slotName
                    ? new AssistantTaskSlot($slotName, true, $validId, is_array($entity) && is_string($entity['label'] ?? null) ? $entity['label'] : null)
                    : $slot,
                $state->slots
            );
            $scopeQuery = $namedQuery ?? ($numericId !== null && $query === null ? (string) $numericId : $query);
            if ($scopeQuery !== null) {
                $slots = array_values(array_filter($slots, static fn (AssistantTaskSlot $slot): bool => $slot->name !== $scopeSlot));
                $slots[] = new AssistantTaskSlot($scopeSlot, false, $scopeQuery);
            }
            $state = new AssistantTaskState($state->id, $state->domain, $state->capability, $state->toolName,
                $state->status, $slots, $state->sourceMessage);
        }

        return $this->stateWithStatus(
            $state,
            $state->missingRequiredSlotNames() === [] ? 'ready_to_execute' : 'waiting_for_slots'
        );
    }

    private function refersToCurrentEntity(string $message, string $type): bool
    {
        $noun = match ($type) {
            'project' => '(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)',
            'warehouse' => 'склад(?:а|у|ом|е)?',
            'contractor' => '(?:подрядчик(?:а|у|ом|е)?|контрагент(?:а|у|ом|е)?)',
            'user' => '(?:сотрудник(?:а|у|ом|е)?|пользовател(?:ь|я|ю|ем|е))',
            'contract' => 'договор(?:а|у|ом|е)?',
            default => '',
        };

        return $noun !== '' && preg_match('/\b(?:текущ|выбранн|эт(?:от|ого|ом|ому|им))[а-яё]*\s+'.$noun.'\b|\b'.$noun.'\s+(?:текущ|выбранн)[а-яё]*\b/iu', $message) === 1;
    }

    private function contextEntityScope(array $context, string $type): ?array
    {
        $refs = $context['entity_refs'] ?? null;
        if (! is_array($refs)) {
            return null;
        }
        foreach ($refs as $ref) {
            if (is_array($ref) && ($ref['type'] ?? null) === $type && array_key_exists('id', $ref)) {
                return ['id' => $ref['id']];
            }
        }

        return null;
    }

    private function namedEntityQuery(string $message, string $type): ?string
    {
        $noun = match ($type) {
            'project' => '(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)',
            'warehouse' => 'склад(?:а|у|ом|е)?',
            'contract' => 'договор(?:а|у|ом|е)?',
            'contractor' => '(?:подрядчик(?:а|у|ом|е)?|контрагент(?:а|у|ом|е)?)',
            'user' => '(?:сотрудник(?:а|у|ом|е)?|пользовател(?:ь|я|ю|ем|е))',
            default => '',
        };
        if ($noun === '' || preg_match('/\b'.$noun.'\s+(.+?)$/iu', $message, $matches) !== 1) {
            return null;
        }
        $parts = preg_split('/\s+(?:за|с|по|для|на|от|проекта|объекта|склада|сотрудника|подрядчика)\b|[,;\n]/iu', $matches[1], 2);
        $name = trim((string) ($parts[0] ?? ''), " \t\r\n\"'«»“”.:");
        if ($name === '' || preg_match('/^(?:(?:за|с|по|для|на|от|этот|этого|весь|всем|все|сегодня|проекта|объекта|склада|подрядчика|сотрудника)\b|текущ|выполн)/iu', $name) === 1) {
            return null;
        }

        return $name;
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
            'project_id', 'warehouse_id', 'contractor_id', 'user_id' => trans_message('ai_assistant.report_entity_scope_unresolved'),
            '__unsupported_scope' => trans_message('ai_assistant.report_entity_scope_unsupported'),
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
