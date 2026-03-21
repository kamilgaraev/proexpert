<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

class AssistantActionService
{
    public function __construct(
        private readonly AIToolRegistry $toolRegistry,
        private readonly AIPermissionChecker $permissionChecker,
        private readonly ConversationManager $conversationManager,
        private readonly LoggingService $logging
    ) {
    }

    public function preview(array $actionPayload, int $organizationId, User $user): array
    {
        $action = $this->normalizeAction($actionPayload);

        if ($action['type'] === 'navigate') {
            return [
                'title' => $action['label'],
                'description' => 'Ассистент подготовил переход в нужный раздел системы.',
                'requires_confirmation' => false,
                'action_class' => $action['action_class'],
                'action' => $action,
                'warnings' => [],
                'summary_items' => [
                    ['label' => 'Маршрут', 'value' => (string) ($action['target']['route'] ?? 'Не задан')],
                ],
                'navigation_target' => $action['target'],
                'executable' => (bool) $action['allowed'],
            ];
        }

        if (!is_string($action['tool_name']) || $action['tool_name'] === '') {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
        }

        $tool = $this->toolRegistry->getTool($action['tool_name']);
        if (!$tool) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.tool_not_found', 'Инструмент действия не найден.'));
        }

        $allowed = $this->permissionChecker->canExecuteTool($user, $action['tool_name'], $action['arguments']);
        $summaryItems = [];

        foreach ($action['arguments'] as $key => $value) {
            $summaryItems[] = [
                'label' => $this->humanizeKey((string) $key),
                'value' => $this->stringifyValue($value),
            ];
        }

        return [
            'title' => $action['label'],
            'description' => trim($tool->getDescription()),
            'requires_confirmation' => true,
            'action_class' => $action['action_class'],
            'action' => array_merge($action, [
                'allowed' => $allowed,
                'reason_if_disabled' => $allowed
                    ? null
                    : $this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения инструмента :tool.', [
                        'tool' => $action['tool_name'],
                    ]),
            ]),
            'warnings' => $allowed ? [] : [
                $this->assistantMessage('ai_assistant.action_access_warning', 'Это действие недоступно по текущим правам пользователя.'),
            ],
            'summary_items' => $summaryItems,
            'navigation_target' => null,
            'executable' => $allowed,
        ];
    }

    public function execute(
        array $actionPayload,
        int $organizationId,
        User $user,
        ?Conversation $conversation = null
    ): array {
        $action = $this->normalizeAction($actionPayload);

        if ($action['requires_confirmation'] && !($actionPayload['confirmed'] ?? false)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_confirmation_required', 'Для выполнения действия требуется подтверждение.'));
        }

        if ($action['type'] === 'navigate') {
            $result = [
                'message' => $this->assistantMessage('ai_assistant.navigation_ready', 'Переход подготовлен.'),
                'navigation_target' => $action['target'],
                'action' => $action,
                'result' => null,
            ];

            $this->logging->business('ai.assistant.navigation.executed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'route' => $action['target']['route'] ?? null,
            ]);

            if ($conversation instanceof Conversation) {
                $message = $this->conversationManager->addMessage(
                    $conversation,
                    'assistant',
                    (string) $result['message'],
                    0,
                    'assistant-action',
                    [
                        'answer' => $result['message'],
                        'task_type' => 'navigate',
                        'navigation_target' => $action['target'],
                        'executed_actions' => [$action],
                    ]
                );
                $result['message_resource'] = $message;
            }

            return $result;
        }

        if (!is_string($action['tool_name']) || $action['tool_name'] === '') {
            throw new RuntimeException($this->assistantMessage('ai_assistant.action_invalid', 'Ассистент не смог подготовить корректное действие.'));
        }

        if (!$this->permissionChecker->canExecuteTool($user, $action['tool_name'], $action['arguments'])) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения инструмента :tool.', [
                'tool' => $action['tool_name'],
            ]));
        }

        $tool = $this->toolRegistry->getTool($action['tool_name']);
        $organization = Organization::find($organizationId);
        if (!$tool || !$organization instanceof Organization) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.tool_not_found', 'Инструмент действия не найден.'));
        }

        $toolResult = $tool->execute($action['arguments'], $user, $organization);
        $messageText = is_array($toolResult)
            ? (string) ($toolResult['message'] ?? $this->assistantMessage('ai_assistant.action_completed', 'Действие выполнено.'))
            : (string) $toolResult;

        $this->logging->audit('ai.assistant.action.executed', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'tool_name' => $action['tool_name'],
            'arguments' => $action['arguments'],
            'action_class' => $action['action_class'],
        ]);

        $result = [
            'message' => $messageText,
            'navigation_target' => $action['target'],
            'action' => $action,
            'result' => $toolResult,
        ];

        if ($conversation instanceof Conversation) {
            $message = $this->conversationManager->addMessage(
                $conversation,
                'assistant',
                $messageText,
                0,
                'assistant-action',
                [
                    'answer' => $messageText,
                    'task_type' => 'act',
                    'executed_actions' => [[
                        'tool_name' => $action['tool_name'],
                        'arguments' => $action['arguments'],
                        'result' => $toolResult,
                    ]],
                    'requires_confirmation' => false,
                ]
            );
            $result['message_resource'] = $message;
        }

        return $result;
    }

    private function normalizeAction(array $actionPayload): array
    {
        $target = is_array($actionPayload['target'] ?? null) ? $actionPayload['target'] : [];

        return [
            'id' => isset($actionPayload['id']) ? (string) $actionPayload['id'] : null,
            'type' => isset($actionPayload['type']) ? (string) $actionPayload['type'] : 'navigate',
            'label' => isset($actionPayload['label']) ? (string) $actionPayload['label'] : 'Действие ассистента',
            'allowed' => (bool) ($actionPayload['allowed'] ?? false),
            'reason_if_disabled' => isset($actionPayload['reason_if_disabled']) ? (string) $actionPayload['reason_if_disabled'] : null,
            'target' => $target,
            'requires_confirmation' => (bool) ($actionPayload['requires_confirmation'] ?? false),
            'action_class' => isset($actionPayload['action_class']) ? (string) $actionPayload['action_class'] : 'safe',
            'tool_name' => isset($actionPayload['tool_name']) ? (string) $actionPayload['tool_name'] : null,
            'arguments' => is_array($actionPayload['arguments'] ?? null) ? $actionPayload['arguments'] : [],
            'required_permissions' => is_array($actionPayload['required_permissions'] ?? null)
                ? $actionPayload['required_permissions']
                : [],
        ];
    }

    private function humanizeKey(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
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

    private function assistantMessage(string $key, string $fallback, array $replace = []): string
    {
        $translated = trans_message($key, $replace, 'ru');

        if (!is_string($translated)) {
            return $fallback;
        }

        $translated = trim($translated);

        if ($translated === '' || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }
}
