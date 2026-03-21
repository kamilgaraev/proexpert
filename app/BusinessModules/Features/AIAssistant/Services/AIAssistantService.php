<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Throwable;

class AIAssistantService
{
    protected LLMProviderInterface $llmProvider;
    protected ConversationManager $conversationManager;
    protected ContextBuilder $contextBuilder;
    protected IntentRecognizer $intentRecognizer;
    protected UsageTracker $usageTracker;
    protected LoggingService $logging;
    protected AIToolRegistry $toolRegistry;
    protected AIPermissionChecker $permissionChecker;
    protected AssistantAccessContextResolver $accessContextResolver;
    protected AssistantTaskOrchestrator $taskOrchestrator;

    public function __construct(
        LLMProviderInterface $llmProvider,
        ConversationManager $conversationManager,
        ContextBuilder $contextBuilder,
        IntentRecognizer $intentRecognizer,
        UsageTracker $usageTracker,
        LoggingService $logging,
        AIToolRegistry $toolRegistry,
        AIPermissionChecker $permissionChecker,
        AssistantAccessContextResolver $accessContextResolver,
        AssistantTaskOrchestrator $taskOrchestrator
    ) {
        $this->llmProvider = $llmProvider;
        $this->conversationManager = $conversationManager;
        $this->contextBuilder = $contextBuilder;
        $this->intentRecognizer = $intentRecognizer;
        $this->usageTracker = $usageTracker;
        $this->logging = $logging;
        $this->toolRegistry = $toolRegistry;
        $this->permissionChecker = $permissionChecker;
        $this->accessContextResolver = $accessContextResolver;
        $this->taskOrchestrator = $taskOrchestrator;
    }

    public function ask(
        string $query,
        int $organizationId,
        User $user,
        ?int $conversationId = null,
        array $requestPayload = []
    ): array {
        if (!$this->permissionChecker->canUseAssistant($user, $organizationId)) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.access_denied', 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РїСЂР°РІ РґР»СЏ СЂР°Р±РѕС‚С‹ СЃ AI-Р°СЃСЃРёСЃС‚РµРЅС‚РѕРј.'));
        }

        $this->logging->business('ai.assistant.request', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'query_length' => strlen($query),
        ]);

        if (!$this->usageTracker->canMakeRequest($organizationId)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.limit_exceeded', 'РСЃС‡РµСЂРїР°РЅ РјРµСЃСЏС‡РЅС‹Р№ Р»РёРјРёС‚ Р·Р°РїСЂРѕСЃРѕРІ Рє AI-Р°СЃСЃРёСЃС‚РµРЅС‚Сѓ.'));
        }

        $conversation = $this->getOrCreateConversation($conversationId, $organizationId, $user);
        $accessContext = $this->accessContextResolver->resolve($user, $organizationId);
        $taskPlan = $this->taskOrchestrator->plan($query, $requestPayload, $accessContext);

        $this->conversationManager->addMessage(
            $conversation,
            'user',
            $query,
            0,
            'gpt-4o-mini',
            [
                'request' => $taskPlan['request'],
                'task_type' => $taskPlan['task_type'],
                'capability' => $taskPlan['capability']['id'] ?? null,
                'access_context' => $taskPlan['access_context_public'],
            ]
        );

        $previousIntent = $conversation->context['last_intent'] ?? null;
        $legacyContext = $this->contextBuilder->buildContext(
            $query,
            $organizationId,
            $user->id,
            $previousIntent,
            $conversation->context ?? []
        );

        $this->logging->technical('ai.context.built', [
            'organization_id' => $organizationId,
            'intent' => $legacyContext['intent'] ?? 'unknown',
            'context_keys' => array_keys($legacyContext),
            'task_type' => $taskPlan['task_type'],
            'capability' => $taskPlan['capability']['id'] ?? null,
        ]);

        $currentIntent = $legacyContext['intent'] ?? null;
        $executedAction = null;

        if ($currentIntent) {
            $conversationContext = array_merge($conversation->context ?? [], [
                'last_intent' => $currentIntent,
                'last_task_type' => $taskPlan['task_type'],
                'last_capability' => $taskPlan['capability']['id'] ?? null,
                'last_request_context' => $taskPlan['request']['context'],
                'last_access_context' => $taskPlan['access_context_public'],
            ]);

            if ($this->isWriteIntent($currentIntent) && isset($legacyContext[$currentIntent])) {
                $executedAction = [
                    'type' => $currentIntent,
                    'result' => $legacyContext[$currentIntent],
                    'timestamp' => now()->toISOString(),
                ];
                $conversationContext['last_executed_action'] = $executedAction;
            }

            $conversation->context = $conversationContext;
            $conversation->save();
        }

        $messages = $this->buildMessages($conversation, $legacyContext, $taskPlan);

        try {
            $options = [];
            $tools = $this->toolRegistry->getToolsDefinitions();
            if (!empty($tools)) {
                $options['tools'] = $tools;
            }

            $toolFailures = [];
            $toolEvidence = [];
            $proposedActions = [];
            $degradedMode = false;

            $responseEnvelope = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
            $response = $responseEnvelope['response'];
            $degradedMode = (bool) ($responseEnvelope['degraded_mode'] ?? false);

            if (is_string($responseEnvelope['fallback_reason'] ?? null)) {
                $toolFailures[] = $responseEnvelope['fallback_reason'];
            }

            $loopCount = 0;
            $maxLoops = 5;
            $organization = null;

            while (!empty($response['tool_calls']) && $loopCount < $maxLoops) {
                if (!$organization instanceof Organization) {
                    $organization = Organization::find($organizationId);
                    if (!$organization instanceof Organization) {
                        throw new RuntimeException($this->assistantMessage('ai_assistant.organization_not_found', 'РћСЂРіР°РЅРёР·Р°С†РёСЏ РґР»СЏ AI-Р°СЃСЃРёСЃС‚РµРЅС‚Р° РЅРµ РЅР°Р№РґРµРЅР°.'));
                    }
                }

                $messages[] = [
                    'role' => $response['role'] ?? 'assistant',
                    'content' => $response['content'] ?? '',
                    'tool_calls' => $response['tool_calls'],
                ];

                foreach ($response['tool_calls'] as $toolCall) {
                    $toolResult = $this->handleToolCall(
                        $toolCall,
                        $organization,
                        $user,
                        $organizationId,
                        (bool) ($taskPlan['request']['allow_actions'] ?? false),
                        $executedAction,
                        $toolEvidence,
                        $toolFailures,
                        $proposedActions
                    );

                    $toolContent = is_string($toolResult)
                        ? $toolResult
                        : (json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"tool_result_serialization_failed"}');

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'] ?? uniqid('tool_', true),
                        'name' => $toolCall['function']['name'] ?? 'unknown_tool',
                        'content' => $toolContent,
                    ];
                }

                $responseEnvelope = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
                $response = $responseEnvelope['response'];
                $degradedMode = $degradedMode || (bool) ($responseEnvelope['degraded_mode'] ?? false);

                if (is_string($responseEnvelope['fallback_reason'] ?? null)) {
                    $toolFailures[] = $responseEnvelope['fallback_reason'];
                }

                $loopCount++;
            }

            $toolFailures = array_values(array_unique(array_filter(
                $toolFailures,
                static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
            )));

            $assistantContent = trim((string) ($response['content'] ?? ''));
            if ($assistantContent === '') {
                $assistantContent = $this->assistantMessage('ai_assistant.empty_response', 'Не удалось сформировать содержательный ответ по текущему запросу.');
            }

            $assistantPayload = $this->taskOrchestrator->buildPayload($taskPlan, $assistantContent, [
                'degraded_mode' => $degradedMode,
                'tool_failures' => $toolFailures,
                'tool_evidence' => $toolEvidence,
                'proposed_actions' => $proposedActions,
                'missing_data' => $toolFailures,
                'executed_action' => $executedAction,
            ]);

            $assistantMessage = $this->conversationManager->addMessage(
                $conversation,
                'assistant',
                $assistantContent,
                (int) ($response['tokens_used'] ?? 0),
                (string) ($response['model'] ?? 'gpt-4o-mini'),
                $assistantPayload
            );

            $cost = $this->usageTracker->calculateCost(
                (int) ($response['tokens_used'] ?? 0),
                (string) ($response['model'] ?? 'gpt-4o-mini'),
                isset($response['input_tokens']) ? (int) $response['input_tokens'] : null,
                isset($response['output_tokens']) ? (int) $response['output_tokens'] : null
            );

            $this->usageTracker->trackRequest(
                $organizationId,
                $user,
                (int) ($response['tokens_used'] ?? 0),
                $cost
            );

            $this->logging->business('ai.assistant.success', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tokens_used' => (int) ($response['tokens_used'] ?? 0),
                'cost_rub' => $cost,
                'task_type' => $taskPlan['task_type'],
                'capability' => $taskPlan['capability']['id'] ?? null,
            ]);

            $result = [
                'conversation_id' => $conversation->id,
                'message' => [
                    'id' => $assistantMessage->id,
                    'role' => 'assistant',
                    'content' => $assistantContent,
                    'tokens_used' => (int) ($response['tokens_used'] ?? 0),
                    'metadata' => $assistantPayload,
                    'created_at' => $assistantMessage->created_at?->toISOString(),
                ],
                'tokens_used' => (int) ($response['tokens_used'] ?? 0),
                'usage' => $this->usageTracker->getUsageStats($organizationId),
            ];

            if ($executedAction) {
                $result['executed_action'] = $executedAction;
            }

            return $result;
        } catch (Throwable $exception) {
            $this->logging->technical('ai.assistant.error', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ], 'error');

            throw $exception;
        }
    }

    protected function handleToolCall(
        array $toolCall,
        Organization $organization,
        User $user,
        int $organizationId,
        bool $allowActions,
        ?array &$executedAction,
        array &$toolEvidence,
        array &$toolFailures,
        array &$proposedActions
    ): array|string {
        $toolName = (string) ($toolCall['function']['name'] ?? '');
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
        $args = is_array($arguments) ? $arguments : [];
        $tool = $this->toolRegistry->getTool($toolName);

        if (!$tool) {
            $message = "Tool {$toolName} not found or not registered.";
            $toolFailures[] = $message;

            return ['error' => $message];
        }

        try {
            $isMutationTool = $this->permissionChecker->isMutationTool($toolName);
            $canExecuteTool = $this->permissionChecker->canExecuteTool($user, $toolName, $args);

            if ($isMutationTool) {
                $proposedActions[] = $this->buildProposedToolAction(
                    $toolName,
                    $args,
                    $allowActions,
                    $canExecuteTool
                );

                $message = $allowActions
                    ? $this->assistantMessage('ai_assistant.action_pending_confirmation', 'Действие подготовлено и ожидает подтверждения пользователя.')
                    : $this->assistantMessage('ai_assistant.action_planning_disabled', 'Ассистент подготовил действие, но в текущем режиме оно не будет выполнено.');

                $this->logging->technical('ai.tool.proposed', [
                    'tool' => $toolName,
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'allowed' => $allowActions,
                    'can_execute' => $canExecuteTool,
                ], 'info');

                if (!$canExecuteTool) {
                    $toolFailures[] = $this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения инструмента :tool.', [
                        'tool' => $toolName,
                    ]);
                }

                return [
                    'status' => 'pending_confirmation',
                    'message' => $message,
                    'tool_name' => $toolName,
                ];
            }

            if (!$canExecuteTool) {
                $message = $this->assistantMessage(
                    'ai_assistant.tool_access_denied',
                    "РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РїСЂР°РІ РґР»СЏ РІС‹РїРѕР»РЅРµРЅРёСЏ РёРЅСЃС‚СЂСѓРјРµРЅС‚Р° {$toolName}.",
                    ['tool' => $toolName]
                );
                $toolFailures[] = $message;

                $this->logging->technical('ai.tool.denied', [
                    'tool' => $toolName,
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                ], 'warning');

                return ['error' => $message];
            }

            $toolResult = $tool->execute($args, $user, $organization);

            if (is_array($toolResult) && isset($toolResult['_executed_action']) && is_array($toolResult['_executed_action'])) {
                $executedAction = $toolResult['_executed_action'];
                unset($toolResult['_executed_action']);
            }

            $toolEvidence[] = [
                'label' => $this->humanizeToolName($toolName),
                'value' => 'Инструмент выполнен',
                'source' => 'assistant_tool',
            ];

            return $toolResult;
        } catch (Throwable $exception) {
            $toolFailures[] = $exception->getMessage();

            $this->logging->technical('ai.tool.error', [
                'tool' => $toolName,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ], 'error');

            return ['error' => $exception->getMessage()];
        }
    }

    protected function requestAssistantResponse(array $messages, array $options, int $organizationId, User $user): array
    {
        try {
            return [
                'response' => $this->llmProvider->chat($messages, $options),
                'degraded_mode' => false,
                'fallback_reason' => null,
            ];
        } catch (Throwable $exception) {
            if (empty($options['tools'])) {
                throw $exception;
            }

            $this->logging->technical('ai.assistant.tools_fallback', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'provider' => $this->llmProvider::class,
                'error' => $exception->getMessage(),
            ], 'warning');

            unset($options['tools']);

            return [
                'response' => $this->llmProvider->chat($messages, $options),
                'degraded_mode' => true,
                'fallback_reason' => $this->assistantMessage('ai_assistant.tools_fallback', 'Часть инструментов оказалась недоступна, ответ сформирован в упрощенном режиме.'),
            ];
        }
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        if ($conversationId) {
            $conversation = $this->conversationManager->findUserConversation($conversationId, $user, $organizationId);

            if ($conversation instanceof Conversation) {
                return $conversation;
            }

            if ($this->permissionChecker->canAccessOrganizationConversationsInAdmin($user, $organizationId)) {
                $conversation = $this->conversationManager->findOrganizationConversation($conversationId, $organizationId);

                if ($conversation instanceof Conversation) {
                    return $conversation;
                }
            }

            throw new AuthorizationException($this->assistantMessage('ai_assistant.conversation_not_found', 'Р”РёР°Р»РѕРі РЅРµ РЅР°Р№РґРµРЅ РёР»Рё РЅРµРґРѕСЃС‚СѓРїРµРЅ.'));
        }

        return $this->conversationManager->createConversation($organizationId, $user);
    }

    protected function assistantMessage(string $key, string $fallback, array $replace = []): string
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

    protected function buildMessages(Conversation $conversation, array $context, array $taskPlan): array
    {
        $messages = [];
        $systemPrompt = $this->contextBuilder->buildSystemPrompt();

        if (!empty($context)) {
            $systemPrompt .= "\n\n" . $this->formatContextForLLM($context);
        }

        $systemPrompt .= "\n\n" . $this->formatStructuredContextForLLM($taskPlan);

        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        foreach ($this->conversationManager->getMessagesForContext($conversation, 10) as $message) {
            $messages[] = $message;
        }

        return $messages;
    }

    protected function formatContextForLLM(array $context): string
    {
        $payload = [];

        foreach ($context as $key => $value) {
            if ($key === 'organization') {
                continue;
            }

            $payload[$key] = $value;
        }

        return "=== LEGACY CONTEXT ===\n" . $this->formatValueForLLM($payload);
    }

    protected function formatStructuredContextForLLM(array $taskPlan): string
    {
        $structuredContext = [
            'task_type' => $taskPlan['task_type'] ?? 'summary',
            'capability' => $taskPlan['capability']['label'] ?? null,
            'request' => $taskPlan['request'] ?? [],
            'access_context' => $taskPlan['access_context_public'] ?? [],
            'navigation_target' => $taskPlan['navigation_target'] ?? null,
        ];

        return "=== STRUCTURED WORKSPACE CONTEXT ===\n"
            . $this->formatValueForLLM($structuredContext)
            . "\n\n=== RESPONSE POLICY ===\n"
            . "1. Опирайся только на фактические данные и доступный контекст.\n"
            . "2. Если данных или прав не хватает, прямо укажи ограничение.\n"
            . "3. Не выдумывай технические причины отказа или обходные пути.\n"
            . "4. Предпочитай короткий управленческий ответ, затем конкретные следующие действия.\n";
    }

    protected function formatValueForLLM(mixed $value, int $depth = 0): string
    {
        if ($depth > 3) {
            return '[truncated]';
        }

        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        if (!is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $prefix = str_repeat('  ', $depth);
            $formattedKey = is_string($key) ? $key : (string) $key;
            $formattedValue = is_array($item)
                ? "\n" . $this->formatValueForLLM($item, $depth + 1)
                : $this->formatValueForLLM($item, $depth + 1);

            $lines[] = "{$prefix}{$formattedKey}: {$formattedValue}";
        }

        return implode("\n", $lines);
    }

    protected function humanizeToolName(string $toolName): string
    {
        return ucfirst(str_replace('_', ' ', $toolName));
    }

    protected function buildProposedToolAction(
        string $toolName,
        array $arguments,
        bool $allowActions,
        bool $canExecuteTool
    ): array {
        $allowed = $allowActions && $canExecuteTool;

        return [
            'id' => 'tool-' . $toolName . '-' . substr(md5(json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $toolName), 0, 12),
            'type' => 'act',
            'label' => $this->humanizeToolName($toolName),
            'allowed' => $allowed,
            'reason_if_disabled' => $allowed
                ? null
                : ($canExecuteTool
                    ? $this->assistantMessage('ai_assistant.action_confirmation_required', 'Для выполнения действия требуется отдельное подтверждение.')
                    : $this->assistantMessage('ai_assistant.tool_access_denied', 'Недостаточно прав для выполнения инструмента :tool.', [
                        'tool' => $toolName,
                    ])),
            'target' => null,
            'requires_confirmation' => true,
            'action_class' => $this->resolveActionClass($toolName),
            'required_permissions' => [],
            'tool_name' => $toolName,
            'arguments' => $arguments,
        ];
    }

    protected function resolveActionClass(string $toolName): string
    {
        foreach (['approve_', 'delete_'] as $prefix) {
            if (str_starts_with($toolName, $prefix)) {
                return 'critical';
            }
        }

        return 'confirm';
    }

    protected function isWriteIntent(string $intent): bool
    {
        return in_array($intent, [
            'create_measurement_unit',
            'mass_create_measurement_units',
            'update_measurement_unit',
            'delete_measurement_unit',
        ], true);
    }
}
