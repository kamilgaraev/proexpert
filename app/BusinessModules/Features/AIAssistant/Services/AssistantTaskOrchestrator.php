<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

class AssistantTaskOrchestrator
{
    public function __construct(
        private readonly AssistantCapabilityRegistry $capabilityRegistry,
        private readonly AssistantAccessContextResolver $accessContextResolver
    ) {
    }

    public function plan(string $query, array $requestPayload, array $accessContext): array
    {
        $request = $this->normalizeRequest($query, $requestPayload);
        $taskType = $this->resolveTaskType($request);
        $capability = $this->capabilityRegistry->match($query, $request['context'], $request['goal']);
        $navigationTarget = $this->resolveNavigationTarget($capability, $request['context']);
        $nextActions = $this->buildNextActions($capability, $accessContext, $request, $navigationTarget);
        $accessLimits = $this->buildAccessLimits($capability, $accessContext, $request, $nextActions);

        return [
            'request' => $request,
            'task_type' => $taskType,
            'capability' => $capability,
            'navigation_target' => $navigationTarget,
            'next_actions' => $nextActions,
            'access_limits' => $accessLimits,
            'access_context_public' => $this->accessContextResolver->toPublicContext($accessContext),
        ];
    }

    public function buildPayload(
        array $plan,
        string $answer,
        array $options = []
    ): array {
        $nextActions = array_values(array_merge(
            $plan['next_actions'] ?? [],
            array_values(array_filter(
                $options['proposed_actions'] ?? [],
                static fn (mixed $action): bool => is_array($action)
            ))
        ));

        $missingData = array_values(array_unique(array_filter(array_merge(
            $this->defaultMissingData($plan),
            $options['missing_data'] ?? []
        ), static fn (mixed $item): bool => is_string($item) && trim($item) !== '')));

        $executedActions = [];
        if (!empty($options['executed_action']) && is_array($options['executed_action'])) {
            $executedActions[] = $options['executed_action'];
        }

        $wizard = null;
        if (($plan['task_type'] ?? null) === 'wizard') {
            $wizard = $this->buildWizard($plan);
        }

        return [
            'answer' => trim($answer),
            'task_type' => $plan['task_type'] ?? 'summary',
            'confidence' => $this->resolveConfidence($plan, $missingData, (bool) ($options['degraded_mode'] ?? false)),
            'capability' => $plan['capability']['id'] ?? null,
            'evidence' => $this->buildEvidence($plan, $options),
            'missing_data' => $missingData,
            'next_actions' => $nextActions,
            'navigation_target' => $plan['navigation_target'] ?? null,
            'wizard' => $wizard,
            'executed_actions' => $executedActions,
            'requires_confirmation' => $this->requiresConfirmation($nextActions),
            'access_limits' => array_values(array_unique(array_merge(
                $plan['access_limits'] ?? [],
                $options['access_limits'] ?? []
            ), SORT_REGULAR)),
            'access_context' => $plan['access_context_public'] ?? [],
            'degraded_mode' => (bool) ($options['degraded_mode'] ?? false),
            'telemetry' => [
                'selected_task' => $plan['task_type'] ?? 'summary',
                'selected_capability' => $plan['capability']['id'] ?? null,
                'tool_failures' => array_values($options['tool_failures'] ?? []),
            ],
        ];
    }

    private function normalizeRequest(string $query, array $requestPayload): array
    {
        $context = is_array($requestPayload['context'] ?? null) ? $requestPayload['context'] : [];
        $entityRefs = [];

        foreach (($context['entity_refs'] ?? []) as $entityRef) {
            if (!is_array($entityRef)) {
                continue;
            }

            $type = trim((string) ($entityRef['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $entityRefs[] = [
                'type' => $type,
                'id' => $entityRef['id'] ?? null,
                'label' => isset($entityRef['label']) ? trim((string) $entityRef['label']) : null,
            ];
        }

        return [
            'message' => trim($query),
            'goal' => isset($requestPayload['goal']) ? trim((string) $requestPayload['goal']) : null,
            'desired_mode' => isset($requestPayload['desired_mode']) ? trim((string) $requestPayload['desired_mode']) : null,
            'allow_actions' => (bool) ($requestPayload['allow_actions'] ?? false),
            'context' => [
                'source_module' => isset($context['source_module']) ? trim((string) $context['source_module']) : null,
                'entity_refs' => $entityRefs,
                'period' => $context['period'] ?? null,
                'filters' => is_array($context['filters'] ?? null) ? $context['filters'] : [],
                'ui_state' => is_array($context['ui_state'] ?? null) ? $context['ui_state'] : [],
            ],
        ];
    }

    private function resolveTaskType(array $request): string
    {
        $goal = mb_strtolower(trim((string) ($request['goal'] ?? '')));
        if (in_array($goal, ['summary', 'find', 'analyze', 'navigate', 'wizard', 'act'], true)) {
            return $goal;
        }

        $desiredMode = mb_strtolower(trim((string) ($request['desired_mode'] ?? '')));
        if (in_array($desiredMode, ['summary', 'find', 'analyze', 'navigate', 'wizard', 'act'], true)) {
            return $desiredMode;
        }

        $query = mb_strtolower((string) ($request['message'] ?? ''));

        if ($this->containsAny($query, ['открой', 'перейди', 'веди', 'покажи раздел'])) {
            return 'navigate';
        }

        if ($this->containsAny($query, ['создай', 'отправь', 'утверди', 'измени', 'назначь'])) {
            return 'act';
        }

        if ($this->containsAny($query, ['пошагово', 'мастер', 'проведи', 'помоги оформить'])) {
            return 'wizard';
        }

        if ($this->containsAny($query, ['найди', 'поиск', 'где', 'кто'])) {
            return 'find';
        }

        if ($this->containsAny($query, ['почему', 'риск', 'риски', 'проанализируй', 'что критично', 'что горит', 'проблем'])) {
            return 'analyze';
        }

        return 'summary';
    }

    private function buildNextActions(?array $capability, array $accessContext, array $request, ?array $navigationTarget): array
    {
        if (!is_array($capability)) {
            return [];
        }

        $actions = [];

        foreach (($capability['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }

            $requiredPermissions = array_values(array_filter(
                $action['required_permissions'] ?? ($capability['read_permissions'] ?? []),
                static fn (mixed $permission): bool => is_string($permission) && $permission !== ''
            ));

            $allowedByPermissions = $this->accessContextResolver->hasAnyPermission($accessContext, $requiredPermissions);
            $requiresConfirmation = (bool) ($action['requires_confirmation'] ?? false);
            $actionType = (string) ($action['type'] ?? 'navigate');
            $allowedByMode = !$requiresConfirmation || (bool) ($request['allow_actions'] ?? false);
            $target = is_array($action['target'] ?? null) ? $action['target'] : $navigationTarget;

            $actions[] = [
                'id' => "{$capability['id']}-" . count($actions),
                'type' => $actionType,
                'label' => (string) ($action['label'] ?? 'Открыть раздел'),
                'allowed' => $allowedByPermissions && $allowedByMode,
                'reason_if_disabled' => $this->resolveDisabledReason($allowedByPermissions, $allowedByMode, $requiresConfirmation),
                'target' => $target,
                'requires_confirmation' => $requiresConfirmation,
                'action_class' => (string) ($action['action_class'] ?? 'safe'),
                'required_permissions' => $requiredPermissions,
                'tool_name' => $action['tool_name'] ?? null,
                'arguments' => is_array($action['arguments'] ?? null) ? $action['arguments'] : [],
            ];
        }

        return $actions;
    }

    private function buildAccessLimits(?array $capability, array $accessContext, array $request, array $nextActions): array
    {
        $limits = [];

        if (is_array($capability)) {
            $readPermissions = array_values(array_filter(
                $capability['read_permissions'] ?? [],
                static fn (mixed $permission): bool => is_string($permission) && $permission !== ''
            ));

            if (!$this->accessContextResolver->hasAnyPermission($accessContext, $readPermissions)) {
                $limits[] = [
                    'code' => 'read_permission_required',
                    'message' => "Доступ к разделу \"{$capability['label']}\" ограничен текущими правами пользователя.",
                    'required_permissions' => $readPermissions,
                    'domain' => $capability['domain'] ?? null,
                ];
            }
        }

        if (!(bool) ($request['allow_actions'] ?? false)) {
            $limits[] = [
                'code' => 'actions_locked',
                'message' => 'Изменяющие действия отключены до отдельного подтверждения.',
                'required_permissions' => [],
                'domain' => $capability['domain'] ?? null,
            ];
        }

        foreach ($nextActions as $action) {
            if (($action['allowed'] ?? false) === false && is_string($action['reason_if_disabled'] ?? null)) {
                $limits[] = [
                    'code' => 'action_unavailable',
                    'message' => $action['reason_if_disabled'],
                    'required_permissions' => [],
                    'domain' => $capability['domain'] ?? null,
                ];
            }
        }

        return $limits;
    }

    private function resolveNavigationTarget(?array $capability, array $context): ?array
    {
        if (!is_array($capability)) {
            return null;
        }

        $projectId = $this->findEntityId($context, 'project');
        $scheduleId = $this->findEntityId($context, 'schedule');

        return match ($capability['id']) {
            'projects' => $projectId !== null
                ? ['route' => "/projects/{$projectId}"]
                : ['route' => '/projects'],
            'schedules' => $scheduleId !== null && $projectId !== null
                ? ['route' => "/projects/{$projectId}/schedules/{$scheduleId}"]
                : ($projectId !== null
                    ? ['route' => "/projects/{$projectId}/schedules"]
                    : ['route' => '/schedules']),
            'payments' => ['route' => '/payments/documents'],
            'warehouse' => ['route' => '/warehouse'],
            'reports' => ['route' => '/reports'],
            'contracts' => ['route' => '/contracts'],
            'procurement' => ['route' => '/procurement/contracts'],
            'notifications' => ['route' => '/notifications'],
            default => null,
        };
    }

    private function buildEvidence(array $plan, array $options): array
    {
        $evidence = [];
        $request = $plan['request'] ?? [];
        $context = is_array($request['context'] ?? null) ? $request['context'] : [];

        if (!empty($plan['capability']['label'])) {
            $evidence[] = [
                'label' => 'Домен',
                'value' => (string) $plan['capability']['label'],
                'source' => 'assistant_capability_registry',
            ];
        }

        if (!empty($context['source_module'])) {
            $evidence[] = [
                'label' => 'Источник запроса',
                'value' => (string) $context['source_module'],
                'source' => 'assistant_request_context',
            ];
        }

        foreach (($context['entity_refs'] ?? []) as $entityRef) {
            if (!is_array($entityRef)) {
                continue;
            }

            $type = (string) ($entityRef['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $value = $entityRef['label'] ?: ($entityRef['id'] !== null ? "{$type} #{$entityRef['id']}" : $type);
            $evidence[] = [
                'label' => ucfirst($type),
                'value' => (string) $value,
                'source' => 'assistant_request_context',
            ];
        }

        foreach (($options['tool_evidence'] ?? []) as $item) {
            if (is_array($item) && isset($item['label'], $item['value'])) {
                $evidence[] = $item;
            }
        }

        return $evidence;
    }

    private function defaultMissingData(array $plan): array
    {
        $missingData = [];

        if (empty($plan['capability'])) {
            $missingData[] = 'Ассистент не смог однозначно определить домен запроса по текущему контексту.';
        }

        return $missingData;
    }

    private function buildWizard(array $plan): ?array
    {
        $capability = $plan['capability'] ?? null;
        if (!is_array($capability)) {
            return null;
        }

        $steps = [];
        $navigationTarget = $plan['navigation_target'] ?? null;

        if (is_array($navigationTarget)) {
            $steps[] = [
                'id' => 'open-section',
                'title' => "Откройте раздел \"{$capability['label']}\"",
                'description' => 'Ассистент подготовил точку входа в нужный раздел системы.',
                'target' => $navigationTarget,
                'action_label' => 'Перейти',
            ];
        }

        $steps[] = [
            'id' => 'review-data',
            'title' => 'Проверьте данные и ограничения доступа',
            'description' => 'Перед изменяющими действиями убедитесь, что нужные сущности доступны по правам.',
            'target' => null,
            'action_label' => null,
        ];

        return [
            'title' => "Пошаговый сценарий по разделу \"{$capability['label']}\"",
            'steps' => $steps,
        ];
    }

    private function resolveConfidence(array $plan, array $missingData, bool $degradedMode): string
    {
        if ($degradedMode) {
            return 'low';
        }

        if ($missingData !== []) {
            return 'medium';
        }

        return !empty($plan['capability']) ? 'high' : 'medium';
    }

    private function requiresConfirmation(array $nextActions): bool
    {
        foreach ($nextActions as $action) {
            if (($action['requires_confirmation'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function resolveDisabledReason(bool $allowedByPermissions, bool $allowedByMode, bool $requiresConfirmation): ?string
    {
        if (!$allowedByPermissions) {
            return 'Недостаточно прав для этого действия.';
        }

        if ($requiresConfirmation && !$allowedByMode) {
            return 'Действие станет доступно после отдельного подтверждения.';
        }

        return null;
    }

    private function findEntityId(array $context, string $entityType): int|string|null
    {
        foreach (($context['entity_refs'] ?? []) as $entityRef) {
            if (!is_array($entityRef)) {
                continue;
            }

            if (($entityRef['type'] ?? null) === $entityType) {
                return $entityRef['id'] ?? null;
            }
        }

        return null;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
