<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIPendingAction;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AssistantActionService
{
    private const PREVIEW_TTL_MINUTES = 10;

    public function __construct(
        private readonly AIToolRegistry $toolRegistry,
        private readonly AIPermissionChecker $permissionChecker,
        private readonly ConversationManager $conversationManager,
        private readonly LoggingService $logging,
        private readonly PendingActionStateResolver $stateResolver = new PendingActionStateResolver()
    ) {
    }

    public function preview(array $actionPayload, int $organizationId, User $user, ?Conversation $conversation = null): array
    {
        if (($actionPayload['allow_actions'] ?? null) === false) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.actions_disabled', 'Изменяющие действия отключены в текущем запросе.'));
        }

        return DB::transaction(function () use ($actionPayload, $organizationId, $user, $conversation): array {
            $conversation = $this->assertConversationContext($conversation, $organizationId, $user);
            $proposal = (new AssistantActionProposalService())->resolve($conversation, $user, $actionPayload);
            if (($proposal['allowed'] ?? null) !== true) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.action_origin_invalid', 'Исходный запрос действия недействителен.'));
            }
            $action = $this->normalizeProposal($proposal);
            $origin = $this->originRequest($conversation, $user, (string) $proposal['origin_request_id']);
            $requestHash = $this->requestHash($origin);
            $bindingHash = $this->canonicalHash([$organizationId, (int) $user->id, (int) $conversation->id, $proposal['origin_request_id'], $action['tool_name'], $action['arguments']]);
            $existing = AIPendingAction::query()->where('binding_hash', $bindingHash)->lockForUpdate()->first();
            if ($existing !== null && ! in_array($existing->status, ['pending', 'expired'], true)) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_preview_required', 'Подтверждение этого действия уже подготовлено.'));
            }

            $tool = $this->toolRegistry->getTool($action['tool_name']);
            if ($tool === null || ! $this->permissionChecker->isMutationTool($action['tool_name'])) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
            }

            $this->assertToolArguments($action['arguments'], $tool->getParametersSchema());

            if (! $this->permissionChecker->canUseAssistant($user, $organizationId)
                || ! $this->permissionChecker->canExecuteTool($user, $action['tool_name'], $action['arguments'])) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения действия.'));
            }

            $organization = Organization::query()->find($organizationId);
            if (! $organization instanceof Organization) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.organization_not_found', 'Организация не найдена.'));
            }

            $targetState = $this->lockTarget($action['tool_name'], $action['arguments'], $organizationId);
            $state = $this->stateResolver->snapshot($action['tool_name'], $action['arguments'], $organizationId);
            $token = Str::random(64);
            $pending = $existing ?? new AIPendingAction();
            $pending->fill([
                'id' => $existing?->id ?? (string) Str::uuid(),
                'organization_id' => $organization->id,
                'actor_user_id' => $user->id,
                'conversation_id' => $conversation?->id,
                'origin_request_id' => $proposal['origin_request_id'],
                'origin_request_payload' => $origin->metadata['request'],
                'origin_request_hash' => $requestHash,
                'binding_hash' => $bindingHash,
                'conversation_context_version' => (int) $conversation->context_version,
                'tool_name' => $action['tool_name'],
                'arguments' => $action['arguments'],
                'action_class' => 'mutation',
                'entity_state' => $state,
                'entity_state_hash' => $this->canonicalHash([$state, $targetState]),
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addMinutes(self::PREVIEW_TTL_MINUTES),
            ])->save();

            return [
                'title' => $action['label'],
                'description' => trim($tool->getDescription()),
                'requires_confirmation' => true,
                'action_class' => 'mutation',
                'action' => [
                    'id' => $pending->id,
                    'tool_name' => $pending->tool_name,
                    'arguments' => $pending->arguments,
                    'requires_confirmation' => true,
                    'action_class' => 'mutation',
                ],
                'preview_token' => $token,
                'expires_at' => $pending->expires_at->toIso8601String(),
                'before' => $state,
                'after' => $this->buildAfterState($action['tool_name'], $action['arguments'], $state),
                'warnings' => [],
                'summary_items' => $this->summaryItems($action['arguments']),
                'navigation_target' => null,
                'executable' => true,
            ];
        });
    }

    public function execute(array $actionPayload, int $organizationId, User $user, ?Conversation $conversation = null): array
    {
        if (($actionPayload['confirmed'] ?? false) !== true) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_confirmation_required', 'Для выполнения действия требуется подтверждение.'));
        }
        if (($actionPayload['allow_actions'] ?? null) === false) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.actions_disabled', 'Изменяющие действия отключены в текущем запросе.'));
        }

        $actionId = isset($actionPayload['id']) ? (string) $actionPayload['id'] : '';
        $token = isset($actionPayload['preview_token']) ? (string) $actionPayload['preview_token'] : '';
        if ($actionId === '' || $token === '') {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_preview_required', 'Сначала подготовьте предварительный просмотр действия.'));
        }

        return DB::transaction(function () use ($actionId, $token, $organizationId, $user, $conversation): array {
            $conversation = $this->assertConversationContext($conversation, $organizationId, $user);
            $pending = AIPendingAction::query()->lockForUpdate()->find($actionId);
            if (! $pending instanceof AIPendingAction
                || (int) $pending->organization_id !== $organizationId
                || (int) $pending->actor_user_id !== (int) $user->id
                || ! hash_equals($pending->token_hash, hash('sha256', $token))) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.action_preview_required', 'Предварительный просмотр действия недействителен.'));
            }

            $conversationId = $conversation?->id;
            if ($conversationId !== ($pending->conversation_id !== null ? (int) $pending->conversation_id : null)) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.action_conversation_mismatch', 'Действие подготовлено для другого диалога.'));
            }

            if (! $this->permissionChecker->canUseAssistant($user, $organizationId)
                || ! $this->permissionChecker->canExecuteTool($user, $pending->tool_name, $pending->arguments)) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения действия.'));
            }
            $origin = $this->originRequest($conversation, $user, (string) $pending->origin_request_id);
            if (! is_string($pending->origin_request_hash) || ! hash_equals($pending->origin_request_hash, $this->requestHash($origin))) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.action_origin_invalid', 'Исходный запрос действия недействителен.'));
            }
            $proposal = (new AssistantActionProposalService())->resolve($conversation, $user, [
                'tool_name' => $pending->tool_name, 'arguments' => $pending->arguments,
                'origin_request_id' => $pending->origin_request_id,
            ]);
            if (($proposal['allowed'] ?? null) !== true || $proposal['origin_request_id'] !== $pending->origin_request_id) {
                throw new AuthorizationException($this->assistantMessage('ai_assistant.action_origin_invalid', 'Исходный запрос действия недействителен.'));
            }
            if ($pending->status === 'executed') {
                return $this->storedResult($pending);
            }

            if ($pending->status !== 'pending' || $pending->expires_at->isPast()) {
                if ($pending->status === 'pending') {
                    $pending->forceFill(['status' => 'expired'])->save();
                }

                throw new RuntimeException($this->assistantMessage('ai_assistant.action_preview_expired', 'Срок подтверждения действия истёк. Подготовьте его заново.'));
            }

            if ((int) $pending->conversation_context_version !== (int) $conversation->context_version) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_stale', 'Данные изменились. Подготовьте действие заново.'));
            }

            $targetState = $this->lockTarget($pending->tool_name, $pending->arguments, $organizationId);
            $currentHash = $this->canonicalHash([$this->stateResolver->snapshot($pending->tool_name, $pending->arguments, $organizationId), $targetState]);
            if (! hash_equals((string) $pending->entity_state_hash, $currentHash)) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_stale', 'Данные изменились. Подготовьте действие заново.'));
            }

            $tool = $this->toolRegistry->getTool($pending->tool_name);
            $organization = Organization::query()->find($organizationId);
            if ($tool === null || ! $organization instanceof Organization || ! $this->permissionChecker->isMutationTool($pending->tool_name)) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
            }

            $this->assertToolArguments($pending->arguments, $tool->getParametersSchema());
            $pending->forceFill(['status' => 'executing', 'claimed_at' => now()])->save();
            $toolResult = $tool->execute($pending->arguments, $user, $organization);
            $result = [
                'message' => $this->resultMessage($toolResult),
                'navigation_target' => null,
                'action' => [
                    'id' => $pending->id,
                    'tool_name' => $pending->tool_name,
                    'arguments' => $pending->arguments,
                    'requires_confirmation' => true,
                    'action_class' => 'mutation',
                ],
                'result' => $toolResult,
            ];

            $pending->forceFill(['status' => 'executed', 'result' => $result, 'executed_at' => now()])->save();
            $this->logging->audit('ai.assistant.action.executed', [
                'action_id' => $pending->id,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'tool_name' => $pending->tool_name,
            ]);

            return $result;
        });
    }

    private function normalizeProposal(array $payload): array
    {
        $toolName = isset($payload['tool_name']) ? trim((string) $payload['tool_name']) : '';
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        if ($toolName === '') {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
        }

        return [
            'tool_name' => $toolName,
            'arguments' => $arguments,
            'label' => isset($payload['label']) && trim((string) $payload['label']) !== '' ? trim((string) $payload['label']) : $toolName,
        ];
    }

    private function assertConversationContext(?Conversation $conversation, int $organizationId, User $user): Conversation
    {
        $fresh = $conversation === null ? null : Conversation::query()->lockForUpdate()->find($conversation->id);
        if ($fresh === null || (int) $fresh->organization_id !== $organizationId
            || (int) $user->current_organization_id !== $organizationId
            || ! $this->permissionChecker->canUseAssistant($user, $organizationId)
            || $this->conversationManager->findAccessibleConversation((int) $fresh->id, $user, $organizationId, true) === null) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.conversation_not_found', 'Диалог не найден или недоступен.'));
        }
        return $fresh;
    }

    private function originRequest(Conversation $conversation, User $user, string $requestId): Message
    {
        $origin = $conversation->messages()->where('role', 'user')->where('metadata->request_id', $requestId)
            ->where('metadata->actor_user_id', $user->id)->first();
        if (! $origin instanceof Message || ($origin->metadata['request']['allow_actions'] ?? null) !== true) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.action_origin_invalid', 'Исходный запрос действия недействителен.'));
        }
        return $origin;
    }

    private function requestHash(Message $origin): string
    {
        return $this->canonicalHash([(int) $origin->id, $origin->content, $origin->metadata]);
    }

    private function lockTarget(string $toolName, array $arguments, int $organizationId): ?array
    {
        $target = match ($toolName) {
            'update_measurement_unit', 'delete_measurement_unit' => [\App\Models\MeasurementUnit::class, 'id'],
            'create_schedule_task' => [\App\Models\ProjectSchedule::class, 'schedule_id'],
            'update_schedule_task_status' => [\App\Models\ScheduleTask::class, 'task_id'],
            'approve_payment_request' => [\App\BusinessModules\Core\Payments\Models\PaymentDocument::class, 'payment_document_id'],
            'send_project_notification' => [\App\Models\Project::class, 'project_id'],
            default => null,
        };
        if ($target === null) {
            return null;
        }
        $entity = $target[0]::query()->whereKey($arguments[$target[1]] ?? null)
            ->where('organization_id', $organizationId)->lockForUpdate()->first();
        if ($entity === null) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения действия.'));
        }
        if ($toolName === 'create_schedule_task') {
            if ((int) $entity->project_id !== (int) ($arguments['project_id'] ?? 0)) {
                throw new AuthorizationException(trans_message('ai_assistant.tool_access_denied'));
            }
            if (isset($arguments['parent_task_id'])) {
                $parent = \App\Models\ScheduleTask::query()->whereKey($arguments['parent_task_id'])
                    ->where('organization_id', $organizationId)->where('schedule_id', $entity->id)
                    ->whereIn('task_type', ['summary', 'container'])->lockForUpdate()->first();
                if ($parent === null) {
                    throw new AuthorizationException(trans_message('ai_assistant.tool_access_denied'));
                }
                return ['schedule' => $entity->getAttributes(), 'parent' => $parent->getAttributes()];
            }
        }
        return $entity->getAttributes();
    }

    private function canonicalHash(array $payload): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }
            return $value;
        };
        return hash('sha256', json_encode($sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertToolArguments(array $arguments, array $schema): void
    {
        if (($schema['type'] ?? 'object') !== 'object' || ! is_array($schema['properties'] ?? null)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
        }

        $properties = $schema['properties'];
        foreach ($schema['required'] ?? [] as $required) {
            if (! is_string($required) || ! array_key_exists($required, $arguments)) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_arguments_invalid', 'Параметры действия заполнены некорректно.'));
            }
        }

        foreach ($arguments as $name => $value) {
            if (! is_string($name) || ! array_key_exists($name, $properties) || ! is_array($properties[$name])) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.action_arguments_invalid', 'Параметры действия заполнены некорректно.'));
            }
            $this->assertSchemaValue($value, $properties[$name]);
        }
    }

    private function assertSchemaValue(mixed $value, array $schema): void
    {
        $valid = match ($schema['type'] ?? null) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };

        if (! $valid
            || (isset($schema['enum']) && (! is_array($schema['enum']) || ! in_array($value, $schema['enum'], true)))
            || (isset($schema['minimum']) && is_numeric($value) && $value < $schema['minimum'])
            || (isset($schema['maximum']) && is_numeric($value) && $value > $schema['maximum'])
            || (isset($schema['maxLength']) && is_string($value) && mb_strlen($value) > $schema['maxLength'])
            || (isset($schema['minItems']) && is_array($value) && count($value) < $schema['minItems'])
            || (isset($schema['maxItems']) && is_array($value) && count($value) > $schema['maxItems'])) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_arguments_invalid', 'Параметры действия заполнены некорректно.'));
        }

        if (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $item) {
                $this->assertSchemaValue($item, $schema['items']);
            }
        }

        if (($schema['type'] ?? null) === 'object' && is_array($value)) {
            $this->assertToolArguments($value, $schema);
        }
    }

    private function buildAfterState(string $toolName, array $arguments, array $before): array
    {
        return match ($toolName) {
            'update_measurement_unit' => array_merge($before, ['proposed_changes' => $arguments]),
            'delete_measurement_unit' => ['entity' => 'measurement_unit', 'id' => $arguments['id'] ?? null, 'exists' => false],
            default => ['tool_name' => $toolName, 'proposed_arguments' => $arguments],
        };
    }

    private function summaryItems(array $arguments): array
    {
        $items = [];
        foreach ($arguments as $key => $value) {
            $items[] = ['label' => ucfirst(str_replace('_', ' ', (string) $key)), 'value' => $this->stringifyValue($value)];
        }
        return $items;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[complex]';
    }

    private function resultMessage(array|string $toolResult): string
    {
        return is_array($toolResult) ? (string) ($toolResult['message'] ?? $this->assistantMessage('ai_assistant.action_completed', 'Действие выполнено.')) : $toolResult;
    }

    private function storedResult(AIPendingAction $pending): array
    {
        if (! is_array($pending->result)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_result_unavailable', 'Результат действия недоступен.'));
        }
        return $pending->result;
    }

    private function assistantMessage(string $key, string $fallback, array $replace = []): string
    {
        try {
            $translated = trans_message($key, $replace, 'ru');
        } catch (Throwable) {
            return $fallback;
        }
        return is_string($translated) && trim($translated) !== '' && $translated !== $key ? trim($translated) : $fallback;
    }
}
