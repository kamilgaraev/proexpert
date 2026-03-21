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
    private const HISTORY_MESSAGE_LIMIT = 6;
    private const HISTORY_TOTAL_CHARS = 4000;
    private const HISTORY_USER_MESSAGE_CHARS = 500;
    private const HISTORY_ASSISTANT_MESSAGE_CHARS = 900;
    private const LEGACY_CONTEXT_CHARS = 3500;
    private const STRUCTURED_CONTEXT_CHARS = 2500;
    private const MESSAGE_CHAR_BUDGET = 18000;
    private const STRICT_MESSAGE_CHAR_BUDGET = 12000;
    private const SYSTEM_PROMPT_CHAR_LIMIT = 8000;
    private const USER_MESSAGE_CHAR_LIMIT = 1200;
    private const ASSISTANT_MESSAGE_CHAR_LIMIT = 1800;
    private const TOOL_MESSAGE_CHAR_LIMIT = 1400;
    private const PROVIDER_INPUT_TOKEN_BUDGET = 12000;
    private const CONTEXT_MAX_DEPTH = 3;
    private const CONTEXT_LIST_LIMIT = 5;
    private const CONTEXT_MAP_LIMIT = 8;

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
            $tools = $this->resolveToolDefinitions($taskPlan);
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
        [$preparedMessages, $preparedOptions, $budgetDegraded] = $this->prepareProviderPayload($messages, $options, $organizationId, $user);

        try {
            return [
                'response' => $this->llmProvider->chat($preparedMessages, $preparedOptions),
                'degraded_mode' => $budgetDegraded,
                'fallback_reason' => null,
            ];
        } catch (Throwable $exception) {
            if (empty($preparedOptions['tools'])) {
                throw $exception;
            }

            $this->logging->technical('ai.assistant.tools_fallback', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'provider' => $this->llmProvider::class,
                'error' => $exception->getMessage(),
            ], 'warning');

            unset($preparedOptions['tools']);

            return [
                'response' => $this->llmProvider->chat($preparedMessages, $preparedOptions),
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
        $systemSections = [$this->contextBuilder->buildSystemPrompt()];

        if (!empty($context)) {
            $systemSections[] = $this->formatContextForLLM($context);
        }

        $systemSections[] = $this->formatStructuredContextForLLM($taskPlan);
        $systemPrompt = $this->truncateText(implode("\n\n", array_filter($systemSections)), self::SYSTEM_PROMPT_CHAR_LIMIT);

        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        foreach ($this->conversationManager->getMessagesForContextWithBudget(
            $conversation,
            self::HISTORY_MESSAGE_LIMIT,
            self::HISTORY_TOTAL_CHARS,
            self::HISTORY_USER_MESSAGE_CHARS,
            self::HISTORY_ASSISTANT_MESSAGE_CHARS
        ) as $message) {
            $messages[] = $message;
        }

        return $this->enforceMessageBudget($messages, self::MESSAGE_CHAR_BUDGET);
    }

    protected function formatContextForLLM(array $context): string
    {
        $payload = [];

        foreach ($context as $key => $value) {
            if ($key === 'organization') {
                continue;
            }

            $payload[$key] = $this->compactValueForLLM($value);
        }

        return $this->truncateText(
            "=== LEGACY CONTEXT ===\n" . $this->formatValueForLLM($payload),
            self::LEGACY_CONTEXT_CHARS
        );
    }

    protected function formatStructuredContextForLLM(array $taskPlan): string
    {
        $structuredContext = [
            'task_type' => $taskPlan['task_type'] ?? 'summary',
            'capability' => $taskPlan['capability']['label'] ?? null,
            'request' => [
                'goal' => $taskPlan['request']['goal'] ?? null,
                'desired_mode' => $taskPlan['request']['desired_mode'] ?? null,
                'allow_actions' => (bool) ($taskPlan['request']['allow_actions'] ?? false),
                'source_module' => $taskPlan['request']['context']['source_module'] ?? null,
                'entity_refs' => array_slice($taskPlan['request']['context']['entity_refs'] ?? [], 0, 3),
                'period' => $taskPlan['request']['context']['period'] ?? null,
                'filters_count' => is_array($taskPlan['request']['context']['filters'] ?? null)
                    ? count($taskPlan['request']['context']['filters'])
                    : 0,
                'assistant_path' => $taskPlan['request']['context']['ui_state']['assistant_path'] ?? null,
            ],
            'access_context' => [
                'available_modules' => array_slice($taskPlan['access_context_public']['available_modules'] ?? [], 0, 6),
                'permission_count' => $taskPlan['access_context_public']['permission_count'] ?? 0,
                'is_read_only' => (bool) ($taskPlan['access_context_public']['is_read_only'] ?? true),
                'allowed_action_types' => array_slice($taskPlan['access_context_public']['allowed_action_types'] ?? [], 0, 6),
            ],
            'navigation_target' => $this->compactValueForLLM($taskPlan['navigation_target'] ?? null),
            'next_actions' => array_values(array_filter(array_map(
                static fn (mixed $action): ?string => is_array($action) && isset($action['label'])
                    ? trim((string) $action['label'])
                    : null,
                array_slice($taskPlan['next_actions'] ?? [], 0, 3)
            ))),
        ];

        $policy = "=== RESPONSE POLICY ===\n"
            . "1. Опирайся только на подтвержденные данные и доступный контекст.\n"
            . "2. Если данных или прав не хватает, прямо скажи об ограничении.\n"
            . "3. Не придумывай технические причины отказа и обходные пути.\n"
            . "4. Отвечай коротко и по делу, затем предлагай конкретный следующий шаг.\n";

        return $this->truncateText(
            "=== STRUCTURED WORKSPACE CONTEXT ===\n"
            . $this->formatValueForLLM($this->compactValueForLLM($structuredContext))
            . "\n\n"
            . $policy,
            self::STRUCTURED_CONTEXT_CHARS
        );

        $structuredContext = [
            'task_type' => $taskPlan['task_type'] ?? 'summary',
            'capability' => $taskPlan['capability']['label'] ?? null,
            'request' => [
                'goal' => $taskPlan['request']['goal'] ?? null,
                'desired_mode' => $taskPlan['request']['desired_mode'] ?? null,
                'allow_actions' => (bool) ($taskPlan['request']['allow_actions'] ?? false),
                'source_module' => $taskPlan['request']['context']['source_module'] ?? null,
                'entity_refs' => array_slice($taskPlan['request']['context']['entity_refs'] ?? [], 0, 3),
                'period' => $taskPlan['request']['context']['period'] ?? null,
                'filters_count' => is_array($taskPlan['request']['context']['filters'] ?? null)
                    ? count($taskPlan['request']['context']['filters'])
                    : 0,
                'assistant_path' => $taskPlan['request']['context']['ui_state']['assistant_path'] ?? null,
            ],
            'access_context' => [
                'available_modules' => array_slice($taskPlan['access_context_public']['available_modules'] ?? [], 0, 6),
                'permission_count' => $taskPlan['access_context_public']['permission_count'] ?? 0,
                'is_read_only' => (bool) ($taskPlan['access_context_public']['is_read_only'] ?? true),
                'allowed_action_types' => array_slice($taskPlan['access_context_public']['allowed_action_types'] ?? [], 0, 6),
            ],
            'navigation_target' => $this->compactValueForLLM($taskPlan['navigation_target'] ?? null),
            'next_actions' => array_values(array_filter(array_map(
                static fn (mixed $action): ?string => is_array($action) && isset($action['label'])
                    ? trim((string) $action['label'])
                    : null,
                array_slice($taskPlan['next_actions'] ?? [], 0, 3)
            ))),
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
        if ($depth > self::CONTEXT_MAX_DEPTH) {
            return '[truncated]';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return $this->normalizeText($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_array($value)) {
            return $this->normalizeText((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

    protected function compactValueForLLM(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::CONTEXT_MAX_DEPTH) {
            return '[truncated]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->truncateText($this->normalizeText($value), $depth === 0 ? 300 : 180);
        }

        if (!is_array($value)) {
            return $this->truncateText(
                $this->normalizeText((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                200
            );
        }

        $isList = array_is_list($value);
        $limit = $isList ? self::CONTEXT_LIST_LIMIT : self::CONTEXT_MAP_LIMIT;
        $compacted = [];
        $items = array_slice($value, 0, $limit, true);

        foreach ($items as $key => $item) {
            $compacted[$key] = $this->compactValueForLLM($item, $depth + 1);
        }

        $remaining = count($value) - count($items);
        if ($remaining > 0) {
            $compacted['__truncated'] = $isList
                ? "Еще {$remaining} элементов"
                : "Еще {$remaining} полей";
        }

        return $compacted;
    }

    protected function resolveToolDefinitions(array $taskPlan): array
    {
        return $this->toolRegistry->getToolsDefinitions($this->resolveRelevantToolNames($taskPlan));
    }

    protected function resolveRelevantToolNames(array $taskPlan): array
    {
        $taskType = (string) ($taskPlan['task_type'] ?? 'summary');
        $capabilityId = $taskPlan['capability']['id'] ?? null;

        $capabilityTools = match ($capabilityId) {
            'projects' => ['search_projects', 'generate_profitability_report', 'generate_work_completion_report', 'generate_project_timelines_report'],
            'contracts' => ['search_contractors', 'generate_contract_payments_report', 'generate_contractor_settlements_report'],
            'reports' => ['generate_profitability_report', 'generate_work_completion_report', 'generate_material_movements_report', 'generate_contractor_settlements_report', 'generate_contract_payments_report', 'generate_project_timelines_report', 'generate_time_tracking_report', 'generate_warehouse_stock_report'],
            'warehouse' => ['search_warehouse', 'search_materials', 'generate_warehouse_stock_report', 'generate_material_movements_report'],
            'payments' => ['approve_payment_request', 'generate_contract_payments_report'],
            'schedules' => ['search_projects', 'create_schedule_task', 'update_schedule_task_status', 'generate_project_timelines_report', 'generate_work_completion_report'],
            'procurement' => ['search_materials', 'search_contractors'],
            'notifications' => ['search_projects', 'search_users', 'send_project_notification'],
            default => [],
        };

        $toolNames = $capabilityTools;

        if ($taskType === 'find') {
            $toolNames = array_merge($toolNames, [
                'search_projects',
                'search_contractors',
                'search_materials',
                'search_users',
                'search_warehouse',
            ]);
        }

        if (in_array($taskType, ['act', 'wizard'], true)) {
            $toolNames = array_merge($toolNames, [
                'create_schedule_task',
                'update_schedule_task_status',
                'send_project_notification',
                'approve_payment_request',
            ]);
        }

        if ($capabilityId === null && in_array($taskType, ['summary', 'analyze'], true)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $toolNames,
            static fn (mixed $toolName): bool => is_string($toolName) && $toolName !== ''
        )));
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $preparedMessages = $this->prepareMessagesForProvider($messages);
        $preparedOptions = $options;
        $degraded = false;

        $estimatedTokens = $this->estimateProviderInputTokens($preparedMessages, $preparedOptions);

        if ($estimatedTokens > self::PROVIDER_INPUT_TOKEN_BUDGET && !empty($preparedOptions['tools'])) {
            unset($preparedOptions['tools']);
            $degraded = true;

            $this->logging->technical('ai.assistant.tools_budget_limited', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'estimated_tokens' => $estimatedTokens,
            ], 'warning');

            $estimatedTokens = $this->estimateProviderInputTokens($preparedMessages, $preparedOptions);
        }

        if ($estimatedTokens > self::PROVIDER_INPUT_TOKEN_BUDGET) {
            $preparedMessages = $this->enforceMessageBudget($preparedMessages, self::STRICT_MESSAGE_CHAR_BUDGET);
            $estimatedTokens = $this->estimateProviderInputTokens($preparedMessages, $preparedOptions);
            $degraded = true;
        }

        if ($estimatedTokens > self::PROVIDER_INPUT_TOKEN_BUDGET) {
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.prompt_too_large',
                'Запрос получился слишком широким. Уточни объект, период или задачу.'
            ));
        }

        return [$preparedMessages, $preparedOptions, $degraded];
    }

    protected function prepareMessagesForProvider(array $messages): array
    {
        $prepared = [];

        foreach ($messages as $message) {
            $normalized = $this->normalizeMessageForProvider($message);
            if ($normalized !== null) {
                $prepared[] = $normalized;
            }
        }

        return $this->enforceMessageBudget($prepared, self::MESSAGE_CHAR_BUDGET);
    }

    protected function normalizeMessageForProvider(array $message): ?array
    {
        $role = (string) ($message['role'] ?? 'user');
        $normalized = $message;
        $content = (string) ($message['content'] ?? '');

        $limit = match ($role) {
            'system' => self::SYSTEM_PROMPT_CHAR_LIMIT,
            'assistant' => self::ASSISTANT_MESSAGE_CHAR_LIMIT,
            'tool' => self::TOOL_MESSAGE_CHAR_LIMIT,
            default => self::USER_MESSAGE_CHAR_LIMIT,
        };

        if ($role === 'tool') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $content = $this->formatValueForLLM($this->compactValueForLLM($decoded));
            }
        }

        $normalized['content'] = $this->truncateText($this->normalizeText($content), $limit);

        if ($normalized['content'] === '' && empty($normalized['tool_calls'])) {
            return null;
        }

        return $normalized;
    }

    protected function enforceMessageBudget(array $messages, int $maxChars): array
    {
        if ($messages === []) {
            return [];
        }

        $systemMessage = null;
        if (($messages[0]['role'] ?? null) === 'system') {
            $systemMessage = $messages[0];
            array_shift($messages);
        }

        $selected = [];
        $usedChars = $systemMessage !== null
            ? mb_strlen((string) ($systemMessage['content'] ?? ''))
            : 0;

        $lastUserIndex = null;
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                $lastUserIndex = $index;
                break;
            }
        }

        if ($lastUserIndex !== null) {
            $selected[$lastUserIndex] = $messages[$lastUserIndex];
            $usedChars += mb_strlen((string) ($messages[$lastUserIndex]['content'] ?? ''));
        }

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (isset($selected[$index])) {
                continue;
            }

            $messageChars = mb_strlen((string) ($messages[$index]['content'] ?? ''));
            if ($usedChars + $messageChars > $maxChars && $selected !== []) {
                continue;
            }

            $selected[$index] = $messages[$index];
            $usedChars += $messageChars;
        }

        ksort($selected);
        $result = array_values($selected);

        if ($systemMessage !== null) {
            array_unshift($result, $systemMessage);
        }

        return $result;
    }

    protected function estimateProviderInputTokens(array $messages, array $options = []): int
    {
        $payload = '';

        foreach ($messages as $message) {
            $payload .= (string) ($message['role'] ?? 'user');
            $payload .= "\n";
            $payload .= (string) ($message['content'] ?? '');
            $payload .= "\n";

            if (!empty($message['tool_calls'])) {
                $payload .= (string) json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $payload .= "\n";
            }
        }

        if (!empty($options['tools'])) {
            $payload .= (string) json_encode($options['tools'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (int) ceil(mb_strlen($payload) / 2);
    }

    protected function normalizeText(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }

    protected function truncateText(string $value, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        if ($maxChars <= 3) {
            return mb_substr($value, 0, $maxChars);
        }

        return rtrim(mb_substr($value, 0, $maxChars - 3)) . '...';
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
