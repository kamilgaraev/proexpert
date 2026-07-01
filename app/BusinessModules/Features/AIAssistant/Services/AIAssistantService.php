<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
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

    private const FOLLOW_UP_QUERY_CHAR_LIMIT = 1200;

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

    protected AssistantAgentStateStore $agentStateStore;

    protected AssistantAgentPlanner $agentPlanner;

    protected AssistantAgentExecutor $agentExecutor;

    protected AssistantResponseVerifier $responseVerifier;

    protected ?RagRetriever $ragRetriever;

    protected RagPromptContextBuilder $ragPromptContextBuilder;

    protected AssistantToolEligibilityPolicy $toolEligibilityPolicy;

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
        AssistantTaskOrchestrator $taskOrchestrator,
        AssistantAgentStateStore $agentStateStore,
        AssistantAgentPlanner $agentPlanner,
        AssistantAgentExecutor $agentExecutor,
        AssistantResponseVerifier $responseVerifier,
        ?RagRetriever $ragRetriever = null,
        ?RagPromptContextBuilder $ragPromptContextBuilder = null,
        ?AssistantToolEligibilityPolicy $toolEligibilityPolicy = null
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
        $this->agentStateStore = $agentStateStore;
        $this->agentPlanner = $agentPlanner;
        $this->agentExecutor = $agentExecutor;
        $this->responseVerifier = $responseVerifier;
        $this->ragRetriever = $ragRetriever;
        $this->ragPromptContextBuilder = $ragPromptContextBuilder ?? new RagPromptContextBuilder;
        $this->toolEligibilityPolicy = $toolEligibilityPolicy ?? new AssistantToolEligibilityPolicy;
    }

    public function ask(
        string $query,
        int $organizationId,
        User $user,
        ?int $conversationId = null,
        array $requestPayload = []
    ): array {
        if (! $this->permissionChecker->canUseAssistant($user, $organizationId)) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.access_denied', 'Недостаточно прав для работы с AI-ассистентом.'));
        }

        $this->logging->business('ai.assistant.request', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'query_length' => strlen($query),
        ]);

        if (! $this->usageTracker->canMakeRequest($organizationId)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.limit_exceeded', 'Исчерпан месячный лимит запросов к AI-ассистенту.'));
        }

        $conversation = $this->getOrCreateConversation($conversationId, $organizationId, $user);
        $requestPayload = $this->mergeContinuationRequestPayload($query, $requestPayload, $conversation->context ?? []);
        $accessContext = $this->accessContextResolver->resolve($user, $organizationId);
        $taskPlan = $this->taskOrchestrator->plan($query, $requestPayload, $accessContext);
        $this->logRequestUnderstanding($taskPlan, $organizationId, $user);

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
        $legacyConversationContext = array_merge($conversation->context ?? [], [
            'current_request' => $taskPlan['request'],
            'current_request_context' => $taskPlan['request']['context'] ?? [],
        ]);
        $legacyContext = $this->contextBuilder->buildContext(
            $query,
            $organizationId,
            $user->id,
            $previousIntent,
            $legacyConversationContext
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

        $conversationContext = array_merge($conversation->context ?? [], $this->buildLastRequestContext($query, $taskPlan));

        if ($currentIntent) {
            $conversationContext['last_intent'] = $currentIntent;

            if ($this->isWriteIntent($currentIntent) && isset($legacyContext[$currentIntent])) {
                $executedAction = [
                    'type' => $currentIntent,
                    'result' => $legacyContext[$currentIntent],
                    'timestamp' => now()->toISOString(),
                ];
                $conversationContext['last_executed_action'] = $executedAction;
            }
        }

        $conversation->context = $conversationContext;
        $conversation->save();

        $agentResult = $this->handleAgentFlow($query, $organizationId, $user, $conversation, $taskPlan);
        if ($agentResult !== null) {
            return $agentResult;
        }

        $ragContext = $this->buildRagContext($query, $organizationId, $user, $taskPlan, $requestPayload);
        $ragMetadata = is_array($ragContext['metadata'] ?? null) ? $ragContext['metadata'] : [];
        $messages = $this->buildMessages(
            $conversation,
            $legacyContext,
            $taskPlan,
            is_string($ragContext['prompt'] ?? null) ? $ragContext['prompt'] : ''
        );

        try {
            $options = [];
            $options['profile'] = 'assistant';
            $tools = $this->resolveToolDefinitions($taskPlan);
            if (! empty($tools)) {
                $options['tools'] = $tools;
            }

            $toolFailures = [];
            $toolEvidence = [];
            $proposedActions = [];
            $trustedDownloadUrls = [];
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

            while (! empty($response['tool_calls']) && $loopCount < $maxLoops) {
                if (! $organization instanceof Organization) {
                    $organization = $this->resolveOrganization($organizationId);
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
                        $taskPlan,
                        (bool) ($taskPlan['request']['allow_actions'] ?? false),
                        $executedAction,
                        $toolEvidence,
                        $toolFailures,
                        $proposedActions,
                        $trustedDownloadUrls
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
            $assistantContent = $this->stripUntrustedMarkdownLinks($assistantContent, $trustedDownloadUrls);
            $assistantContent = $this->guardUnconfirmedReportCompletion($assistantContent, $taskPlan, $trustedDownloadUrls);
            $assistantContent = $this->responseVerifier->verify($assistantContent, [
                'rag_context' => $ragMetadata,
            ]);
            $assistantContent = $this->softenUnsupportedCriticalClaims($assistantContent, $ragMetadata);

            $assistantPayload = $this->taskOrchestrator->buildPayload($taskPlan, $assistantContent, [
                'degraded_mode' => $degradedMode,
                'tool_failures' => $toolFailures,
                'tool_evidence' => $toolEvidence,
                'proposed_actions' => $proposedActions,
                'missing_data' => $toolFailures,
                'executed_action' => $executedAction,
                'rag_context' => $ragMetadata,
            ]);

            $this->rememberRagContext($conversation, $ragMetadata);

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
                isset($response['output_tokens']) ? (int) $response['output_tokens'] : null,
                false,
                isset($response['provider']) ? (string) $response['provider'] : null
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
                'provider' => $response['provider'] ?? null,
                'model' => $response['model'] ?? null,
                'profile' => $response['profile'] ?? null,
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

    protected function handleAgentFlow(
        string $query,
        int $organizationId,
        User $user,
        Conversation $conversation,
        array $taskPlan
    ): ?array {
        $pendingState = $this->agentStateStore->load($conversation);
        $context = is_array($taskPlan['request']['context'] ?? null) ? $taskPlan['request']['context'] : [];
        $decision = $this->agentPlanner->decide($query, $context, $pendingState);

        if ($this->isGroundedRequest($taskPlan) && $decision->type === 'answer') {
            return null;
        }

        if ($decision->type === 'answer') {
            return null;
        }

        if ($decision->type === 'ask_clarification' && $decision->state instanceof AssistantTaskState) {
            return $this->answerAgentClarification($conversation, $organizationId, $user, $decision->state, $taskPlan, $decision->clarificationQuestion);
        }

        if (
            $decision->type === 'execute_tool'
            && $decision->state instanceof AssistantTaskState
            && is_string($decision->toolName)
            && $decision->toolName !== ''
        ) {
            if (! $this->canAgentExecuteTool($decision->state, $decision->toolName)) {
                return null;
            }

            return $this->executeAgentTool($conversation, $organizationId, $user, $decision->state, $taskPlan, $decision->toolName, $decision->toolArguments);
        }

        return null;
    }

    private function isGroundedRequest(array $taskPlan): bool
    {
        $request = is_array($taskPlan['request'] ?? null) ? $taskPlan['request'] : [];
        $desiredMode = mb_strtolower(trim((string) ($request['desired_mode'] ?? '')));

        return $desiredMode === 'grounded';
    }

    private function logRequestUnderstanding(array $taskPlan, int $organizationId, User $user): void
    {
        $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);
        if (! $requestUnderstanding instanceof AssistantRequestUnderstanding) {
            return;
        }

        $this->logging->technical('ai.assistant.request_understanding', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'primary_intent' => $requestUnderstanding->primaryIntent,
            'output_format' => $requestUnderstanding->outputFormat,
            'action_policy' => $requestUnderstanding->actionPolicy,
            'constraints' => $requestUnderstanding->constraints,
            'requested_entities' => $requestUnderstanding->requestedEntities,
            'confidence' => $requestUnderstanding->confidence,
            'evidence' => array_slice($requestUnderstanding->evidence, 0, 12),
        ]);
    }

    protected function answerAgentClarification(
        Conversation $conversation,
        int $organizationId,
        User $user,
        AssistantTaskState $state,
        array $taskPlan,
        ?string $question
    ): array {
        $this->agentStateStore->save($conversation, $state);

        $answer = trim((string) $question);
        if ($answer === '') {
            $answer = $this->assistantMessage(
                'ai_assistant.agent_clarification_required',
                'Уточните недостающие данные, чтобы я мог продолжить.'
            );
        }

        $missingSlots = $state->missingRequiredSlotNames();
        $payload = $this->taskOrchestrator->buildPayload($taskPlan, $answer, [
            'missing_data' => $missingSlots,
            'agent_state' => $state->toArray(),
            'confidence' => 'medium',
        ]);
        if ($this->isReportAgentState($state)) {
            $payload = $this->sanitizeReportPayload($payload);
        }

        $assistantMessage = $this->conversationManager->addMessage(
            $conversation,
            'assistant',
            $answer,
            0,
            'agent-flow',
            $payload
        );

        return $this->agentAskResult($conversation, $assistantMessage, $answer, $payload, $organizationId, $user);
    }

    protected function executeAgentTool(
        Conversation $conversation,
        int $organizationId,
        User $user,
        AssistantTaskState $state,
        array $taskPlan,
        string $toolName,
        array $toolArguments
    ): array {
        $organization = $this->resolveOrganization($organizationId);
        $toolResult = $this->agentExecutor->execute(
            $toolName,
            $toolArguments,
            $user,
            $organization,
            $taskPlan['request_understanding'] ?? null
        );

        $artifacts = $this->filterAgentArtifactsForOrganization($organizationId, array_values(array_filter(
            $toolResult['artifacts'] ?? [],
            static fn (mixed $artifact): bool => is_array($artifact)
        )));

        $toolStatus = (string) ($toolResult['status'] ?? 'error');
        $finalState = $this->stateWithStatus(
            $state,
            $toolStatus === 'error' || $artifacts === [] ? 'failed' : 'completed'
        );

        $answer = $this->buildAgentToolAnswer($toolStatus, $artifacts);
        $answer = $this->responseVerifier->verify($answer, [
            'task_id' => $finalState->id,
            'state' => $finalState->toArray(),
            'artifacts' => $artifacts,
        ]);

        $this->agentStateStore->save($conversation, $finalState);

        $payload = $this->taskOrchestrator->buildPayload($taskPlan, $answer, [
            'agent_state' => $finalState->toArray(),
            'artifacts' => $artifacts,
            'tool_result' => [
                'status' => $toolStatus,
                'tool_name' => (string) ($toolResult['tool_name'] ?? $toolName),
                'evidence' => array_values($toolResult['evidence'] ?? []),
            ],
            'tool_evidence' => array_values($toolResult['evidence'] ?? []),
            'missing_data' => $artifacts === [] ? ['artifacts'] : [],
            'confidence' => $artifacts === [] ? 'low' : 'high',
        ]);
        if ($this->isReportAgentState($finalState)) {
            $payload = $this->sanitizeReportPayload($payload);
        }

        $assistantMessage = $this->conversationManager->addMessage(
            $conversation,
            'assistant',
            $answer,
            0,
            'agent-flow',
            $payload
        );

        return $this->agentAskResult($conversation, $assistantMessage, $answer, $payload, $organizationId, $user);
    }

    protected function canAgentExecuteTool(AssistantTaskState $state, string $toolName): bool
    {
        return str_starts_with($state->id, 'report.')
            && str_starts_with($toolName, 'generate_')
            && str_ends_with($toolName, '_report');
    }

    protected function isReportAgentState(AssistantTaskState $state): bool
    {
        return str_starts_with($state->id, 'report.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizeReportPayload(array $payload): array
    {
        $payload['evidence'] = [];
        $payload['missing_data'] = [];
        $payload['next_actions'] = [];
        $payload['navigation_target'] = null;
        $payload['access_limits'] = [];
        $payload['requires_confirmation'] = false;

        unset(
            $payload['access_context'],
            $payload['confidence'],
            $payload['degraded_mode'],
            $payload['telemetry']
        );

        return $payload;
    }

    protected function filterAgentArtifactsForOrganization(int $organizationId, array $artifacts): array
    {
        $expectedPrefix = sprintf('org-%d/reports/', $organizationId);

        return array_values(array_filter($artifacts, static function (array $artifact) use ($expectedPrefix): bool {
            return ($artifact['storage_disk'] ?? null) === 's3'
                && is_string($artifact['storage_path'] ?? null)
                && str_starts_with($artifact['storage_path'], $expectedPrefix);
        }));
    }

    protected function buildAgentToolAnswer(string $status, array $artifacts): string
    {
        if ($status === 'error' || $artifacts === []) {
            return $this->assistantMessage(
                'ai_assistant.report_download_missing',
                'Не удалось сформировать файл отчета по текущему запросу.'
            );
        }

        $artifact = $artifacts[0];
        $url = trim((string) ($artifact['url'] ?? ''));

        return $url !== ''
            ? $this->assistantMessage('ai_assistant.report_ready', 'Отчет сформирован. Файл доступен ниже.')
            : $this->assistantMessage(
                'ai_assistant.report_download_missing',
                'Не удалось сформировать файл отчета по текущему запросу.'
            );
    }

    protected function stateWithStatus(AssistantTaskState $state, string $status): AssistantTaskState
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

    protected function agentAskResult(
        Conversation $conversation,
        mixed $assistantMessage,
        string $answer,
        array $payload,
        int $organizationId,
        User $user
    ): array {
        $this->usageTracker->trackRequest($organizationId, $user, 0, 0.0);

        return [
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $assistantMessage->id ?? null,
                'role' => 'assistant',
                'content' => $answer,
                'tokens_used' => 0,
                'metadata' => $payload,
                'created_at' => $assistantMessage->created_at?->toISOString(),
            ],
            'tokens_used' => 0,
            'usage' => $this->usageTracker->getUsageStats($organizationId),
        ];
    }

    protected function resolveOrganization(int $organizationId): Organization
    {
        $organization = Organization::find($organizationId);
        if (! $organization instanceof Organization) {
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.organization_not_found',
                'Организация для AI-ассистента не найдена.'
            ));
        }

        return $organization;
    }

    protected function handleToolCall(
        array $toolCall,
        Organization $organization,
        User $user,
        int $organizationId,
        array $taskPlan,
        bool $allowActions,
        ?array &$executedAction,
        array &$toolEvidence,
        array &$toolFailures,
        array &$proposedActions,
        array &$trustedDownloadUrls
    ): array|string {
        $toolName = (string) ($toolCall['function']['name'] ?? '');
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
        $args = is_array($arguments) ? $arguments : [];
        $tool = $this->toolRegistry->getTool($toolName);

        if (! $tool) {
            $message = "Tool {$toolName} not found or not registered.";
            $toolFailures[] = $message;

            return ['error' => $message];
        }

        try {
            $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);
            if ($requestUnderstanding instanceof AssistantRequestUnderstanding) {
                $eligibility = $this->toolEligibilityPolicy->canExposeTool($toolName, $requestUnderstanding);
                if (! $eligibility->allowed) {
                    $message = $this->toolBlockedMessage($eligibility->reason);
                    $toolFailures[] = $message;

                    $this->logging->technical('ai.tool.blocked_by_request_policy', [
                        'tool' => $toolName,
                        'organization_id' => $organizationId,
                        'user_id' => $user->id,
                        'category' => $eligibility->category,
                        'reason' => $eligibility->reason,
                        'request_understanding' => $requestUnderstanding->toArray(),
                    ], 'warning');

                    return [
                        'status' => 'blocked_by_request_policy',
                        'error' => $message,
                        'tool_name' => $toolName,
                    ];
                }
            }

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

                if (! $canExecuteTool) {
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

            if (! $canExecuteTool) {
                $message = $this->assistantMessage(
                    'ai_assistant.tool_access_denied',
                    "Недостаточно прав для выполнения инструмента {$toolName}.",
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
            $trustedDownloadUrls = array_values(array_unique(array_merge(
                $trustedDownloadUrls,
                $this->collectTrustedDownloadUrls($toolResult)
            )));

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

            throw new AuthorizationException($this->assistantMessage('ai_assistant.conversation_not_found', 'Диалог не найден или недоступен.'));
        }

        return $this->conversationManager->createConversation($organizationId, $user);
    }

    protected function mergeContinuationRequestPayload(
        string $query,
        array $requestPayload,
        array $conversationContext
    ): array {
        $previousCapability = (string) ($conversationContext['last_capability'] ?? '');
        $previousRequest = is_array($conversationContext['last_request'] ?? null)
            ? $conversationContext['last_request']
            : [];

        if (! $this->shouldContinuePreviousRequest($query, $requestPayload, $previousCapability, $previousRequest)) {
            return $requestPayload;
        }

        $payload = $requestPayload;
        $isDetailContinuation = $this->isDetailContinuation($query);
        $currentContext = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $previousContext = is_array($previousRequest['context'] ?? null) ? $previousRequest['context'] : [];

        if ($this->isGenericAssistantSource($currentContext['source_module'] ?? null)) {
            $currentContext['source_module'] = $previousContext['source_module'] ?? $previousCapability;
        }

        foreach (['source_route', 'period'] as $key) {
            if (($currentContext[$key] ?? null) === null && array_key_exists($key, $previousContext)) {
                $currentContext[$key] = $previousContext[$key];
            }
        }

        if (
            ($currentContext['period'] ?? null) === null
            && ! $isDetailContinuation
            && $this->expectsPeriodContinuation($previousContext, $query)
        ) {
            $currentContext['period'] = trim($query);
        }

        if (empty($currentContext['entity_refs']) && ! empty($previousContext['entity_refs'])) {
            $currentContext['entity_refs'] = $previousContext['entity_refs'];
        }

        $currentContext['filters'] = is_array($currentContext['filters'] ?? null)
            ? $currentContext['filters']
            : (is_array($previousContext['filters'] ?? null) ? $previousContext['filters'] : []);
        $currentContext['ui_state'] = is_array($currentContext['ui_state'] ?? null)
            ? $currentContext['ui_state']
            : [];

        if (is_array($previousContext['ui_state'] ?? null)) {
            $currentContext['ui_state'] = array_merge($previousContext['ui_state'], $currentContext['ui_state']);
        }

        $reportFocus = (string) ($conversationContext['last_report_focus'] ?? '');
        if ($reportFocus !== '') {
            $currentContext['ui_state']['assistant_report_focus'] = $reportFocus;
        }

        if ($isDetailContinuation) {
            $currentContext['ui_state']['assistant_follow_up_query'] = $this->buildContinuationSearchQuery(
                $query,
                $previousRequest,
                $conversationContext
            );
        }

        $payload['context'] = $currentContext;

        if (empty($payload['desired_mode']) && ! empty($conversationContext['last_task_type'])) {
            $payload['desired_mode'] = $conversationContext['last_task_type'];
        }

        if (empty($payload['goal']) && ! empty($previousRequest['goal'])) {
            $payload['goal'] = $previousRequest['goal'];
        }

        return $payload;
    }

    protected function buildLastRequestContext(string $query, array $taskPlan): array
    {
        $capabilityId = $taskPlan['capability']['id'] ?? null;

        return [
            'last_task_type' => $taskPlan['task_type'],
            'last_capability' => $capabilityId,
            'last_request' => $taskPlan['request'],
            'last_request_context' => $taskPlan['request']['context'],
            'last_access_context' => $taskPlan['access_context_public'],
            'last_report_focus' => $this->resolveReportFocus($query, $capabilityId, $taskPlan['request']['context'] ?? []),
        ];
    }

    private function shouldContinuePreviousRequest(
        string $query,
        array $requestPayload,
        string $previousCapability,
        array $previousRequest
    ): bool {
        if ($previousRequest === []) {
            return false;
        }

        $normalizedQuery = mb_strtolower(trim($query));
        if ($this->looksLikeStandaloneRequest($normalizedQuery)) {
            return false;
        }

        if ($this->isDetailContinuation($query)) {
            return true;
        }

        if (! in_array($previousCapability, ['reports', 'schedules'], true)) {
            return false;
        }

        $context = is_array($requestPayload['context'] ?? null) ? $requestPayload['context'] : [];

        if (! empty($context['period']) || ! empty($context['entity_refs'])) {
            return true;
        }

        if (preg_match('/\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}/u', $normalizedQuery) === 1) {
            return true;
        }

        if (str_contains($normalizedQuery, 'текущ') && str_contains($normalizedQuery, 'проект')) {
            return true;
        }

        $previousContext = is_array($previousRequest['context'] ?? null) ? $previousRequest['context'] : [];

        return $this->expectsPeriodContinuation($previousContext, $query);
    }

    private function isDetailContinuation(string $query): bool
    {
        $normalized = $this->normalizeText(mb_strtolower($query));
        if ($normalized === '' || mb_strlen($normalized) > 160) {
            return false;
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = $this->normalizeText($normalized);

        if ($normalized === '') {
            return false;
        }

        $exactPhrases = [
            'а подробнее',
            'давай детальнее',
            'давай подробнее',
            'детальнее',
            'можно детальнее',
            'можно подробнее',
            'покажи детали',
            'подробнее',
            'подробней',
            'поясни',
            'распиши',
            'расскажи детальнее',
            'расскажи подробнее',
            'расшифруй',
            'разверни',
            'что именно',
            'еще подробнее',
            'ещё подробнее',
        ];

        if (in_array($normalized, $exactPhrases, true)) {
            return true;
        }

        return preg_match(
            '/^(?:а\s+)?(?:(?:давай|можно|расскажи|покажи)\s+)?(?:(?:еще|ещё|чуть|более)\s+)?(?:подробнее|подробней|детальнее|поясни|распиши|расшифруй|разверни|детали)(?:\s+(?:это|его|её|ее|их|тут|там|здесь|об\s+этом|в\s+этом|на\s+этом|по\s+(?:этому|нему|ней|ним|этому\s+вопросу|этим\s+данным|этой\s+части|этому\s+пункту)))?$/u',
            $normalized
        ) === 1;
    }

    private function buildContinuationSearchQuery(string $query, array $previousRequest, array $conversationContext): string
    {
        $parts = [$this->normalizeText($query)];
        $previousMessage = $this->normalizeText((string) ($previousRequest['message'] ?? ''));
        if ($previousMessage !== '') {
            $parts[] = 'Предыдущий запрос: '.$previousMessage;
        }

        $lastRagContext = is_array($conversationContext['last_rag_context'] ?? null)
            ? $conversationContext['last_rag_context']
            : [];

        $lastRagQuery = $this->normalizeText((string) ($lastRagContext['query'] ?? ''));
        if ($lastRagQuery !== '' && $lastRagQuery !== $previousMessage) {
            $parts[] = 'Предыдущий поиск: '.$lastRagQuery;
        }

        $sources = is_array($lastRagContext['sources'] ?? null) ? array_slice($lastRagContext['sources'], 0, 4) : [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceLine = $this->normalizeText(trim(implode(' ', array_filter([
                (string) ($source['title'] ?? ''),
                (string) ($source['excerpt'] ?? ''),
            ]))));

            if ($sourceLine !== '') {
                $parts[] = 'Источник: '.$sourceLine;
            }
        }

        return $this->truncateText(
            implode("\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''))),
            self::FOLLOW_UP_QUERY_CHAR_LIMIT
        );
    }

    private function expectsPeriodContinuation(array $previousContext, string $query): bool
    {
        if (($previousContext['period'] ?? null) !== null) {
            return false;
        }

        $normalizedQuery = trim($query);

        return $normalizedQuery !== ''
            && mb_strlen($normalizedQuery) <= 140
            && preg_match('/[?]/u', $normalizedQuery) !== 1;
    }

    private function looksLikeStandaloneRequest(string $normalizedQuery): bool
    {
        if ($this->containsAnyText($normalizedQuery, [
            'открой',
            'перейди',
            'найди',
            'создай',
            'измени',
        ])) {
            return true;
        }

        return $this->containsAnyText($normalizedQuery, [
            'сделай',
            'сформируй',
        ]) && $this->containsAnyText($normalizedQuery, [
            'отчет',
            'график',
            'закуп',
            'договор',
            'склад',
            'платеж',
        ]);
    }

    private function isGenericAssistantSource(mixed $sourceModule): bool
    {
        $sourceModule = mb_strtolower(trim((string) $sourceModule));

        return $sourceModule === '' || $sourceModule === 'ai-assistant' || $sourceModule === 'assistant';
    }

    private function resolveReportFocus(string $query, mixed $capabilityId, array $context): ?string
    {
        $uiState = is_array($context['ui_state'] ?? null) ? $context['ui_state'] : [];
        if (! empty($uiState['assistant_report_focus']) && is_string($uiState['assistant_report_focus'])) {
            return $uiState['assistant_report_focus'];
        }

        $normalizedQuery = mb_strtolower($query);
        if ($this->containsAnyText($normalizedQuery, ['график', 'срок', 'этап', 'работ'])) {
            return 'schedules';
        }

        return is_string($capabilityId) && in_array($capabilityId, ['reports', 'schedules'], true)
            ? $capabilityId
            : null;
    }

    private function containsAnyText(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function stripUntrustedMarkdownLinks(string $content, array $trustedUrls = []): string
    {
        $trustedLookup = array_flip(array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $trustedUrls),
            static fn (string $value): bool => $value !== ''
        ))));

        return preg_replace_callback(
            '/\[([^\]\n]+)\]\(([^)\s]+)\)/u',
            static function (array $matches) use ($trustedLookup): string {
                $label = trim((string) ($matches[1] ?? ''));
                $href = trim((string) ($matches[2] ?? ''));

                return isset($trustedLookup[$href]) ? $matches[0] : $label;
            },
            $content
        ) ?? $content;
    }

    protected function guardUnconfirmedReportCompletion(string $content, array $taskPlan, array $trustedUrls = []): string
    {
        if (! $this->isReportTaskPlan($taskPlan) || $trustedUrls !== []) {
            return $content;
        }

        $normalized = mb_strtolower($content);
        $mentionsReport = $this->containsAnyText($normalized, ['отчет', 'отчёт', 'pdf']);
        $claimsCompletion = $this->containsAnyText($normalized, ['готов', 'сформирован', 'скачать']);

        if (! $mentionsReport || ! $claimsCompletion) {
            return $content;
        }

        return $this->assistantMessage(
            'ai_assistant.report_download_missing',
            'Не удалось сформировать файл отчета по текущему запросу. Попробуйте повторить запрос или уточнить период и проект.'
        );
    }

    protected function collectTrustedDownloadUrls(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $key => $item) {
            if (in_array($key, ['pdf_url', 'excel_url', 'download_url', 'file_url'], true) && $this->isTrustedDownloadUrl($item)) {
                $urls[] = trim((string) $item);

                continue;
            }

            if (is_array($item)) {
                $urls = array_merge($urls, $this->collectTrustedDownloadUrls($item));
            }
        }

        return array_values(array_unique($urls));
    }

    protected function isTrustedDownloadUrl(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $url = trim($value);
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/api/') || str_starts_with($url, '/storage/')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme)
            && in_array(mb_strtolower($scheme), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function isReportTaskPlan(array $taskPlan): bool
    {
        $capabilityId = $taskPlan['capability']['id'] ?? null;
        if ($capabilityId === 'reports') {
            return true;
        }

        $request = is_array($taskPlan['request'] ?? null) ? $taskPlan['request'] : [];
        $context = is_array($request['context'] ?? null) ? $request['context'] : [];

        return ($context['source_module'] ?? null) === 'reports'
            || ($context['ui_state']['assistant_report_focus'] ?? null) !== null;
    }

    protected function assistantMessage(string $key, string $fallback, array $replace = []): string
    {
        try {
            $translated = trans_message($key, $replace, 'ru');
        } catch (Throwable) {
            return $this->replaceMessagePlaceholders($fallback, $replace);
        }

        if (! is_string($translated)) {
            return $this->replaceMessagePlaceholders($fallback, $replace);
        }

        $translated = trim($translated);

        if ($translated === '' || $translated === $key) {
            return $this->replaceMessagePlaceholders($fallback, $replace);
        }

        return $this->replaceMessagePlaceholders($translated, $replace);
    }

    protected function replaceMessagePlaceholders(string $message, array $replace): string
    {
        foreach ($replace as $key => $value) {
            if (! is_scalar($value) && ! $value instanceof \Stringable) {
                continue;
            }

            $message = str_replace(':'.$key, (string) $value, $message);
        }

        return $message;
    }

    private function softenUnsupportedCriticalClaims(string $content, array $ragMetadata): string
    {
        if ($content === '' || $this->ragEvidenceHasCriticalMarker($ragMetadata)) {
            return $content;
        }

        return str_replace(
            [
                'Проект находится в критическом статусе',
                'проект находится в критическом статусе',
                'находится в критическом статусе',
                'критический статус',
                'критическом статусе',
            ],
            [
                'По найденным данным есть признаки проблемного статуса проекта',
                'по найденным данным есть признаки проблемного статуса проекта',
                'имеет признаки проблемного статуса',
                'проблемный статус',
                'проблемном статусе',
            ],
            $content
        );
    }

    private function ragEvidenceHasCriticalMarker(array $ragMetadata): bool
    {
        $sources = is_array($ragMetadata['sources'] ?? null) ? $ragMetadata['sources'] : [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                (string) ($source['title'] ?? ''),
                (string) ($source['excerpt'] ?? ''),
                json_encode($source['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
            ])));

            foreach (['critical', 'urgent', 'hard', 'критич', 'срочн', 'аварийн'] as $marker) {
                if (str_contains($haystack, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function buildRagContext(
        string $query,
        int $organizationId,
        User $user,
        array $taskPlan,
        array $requestPayload
    ): array {
        try {
            $ragSearchQuery = $this->resolveRagSearchQuery($query, $requestPayload);
            $results = $this->ragRetriever instanceof RagRetriever
                ? $this->ragRetriever->search(
                    $ragSearchQuery,
                    $organizationId,
                    $user,
                    $this->buildRagRequestContext($taskPlan, $requestPayload)
                )
                : [];

            $context = $this->ragPromptContextBuilder->build($query, $results);
            if ($ragSearchQuery !== $query && is_array($context['metadata'] ?? null)) {
                $context['metadata']['search_query'] = $ragSearchQuery;
            }

            return $context;
        } catch (Throwable $exception) {
            $this->logging->technical('ai.rag.context_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ], 'warning');

            return $this->ragPromptContextBuilder->build($query, []);
        }
    }

    protected function resolveRagSearchQuery(string $query, array $requestPayload): string
    {
        $context = is_array($requestPayload['context'] ?? null) ? $requestPayload['context'] : [];
        $uiState = is_array($context['ui_state'] ?? null) ? $context['ui_state'] : [];
        $followUpQuery = isset($uiState['assistant_follow_up_query'])
            ? $this->normalizeText((string) $uiState['assistant_follow_up_query'])
            : '';

        return $followUpQuery !== ''
            ? $this->truncateText($followUpQuery, self::FOLLOW_UP_QUERY_CHAR_LIMIT)
            : $query;
    }

    private function rememberRagContext(Conversation $conversation, array $ragMetadata): void
    {
        $context = is_array($conversation->context ?? null) ? $conversation->context : [];
        $compact = $this->compactRagContextForContinuation($ragMetadata);

        if ($compact === null) {
            unset($context['last_rag_context']);
        } else {
            $context['last_rag_context'] = $compact;
        }

        $conversation->context = $context;
        $conversation->save();
    }

    protected function compactRagContextForContinuation(array $ragMetadata): ?array
    {
        if (($ragMetadata['used'] ?? false) !== true || ! is_array($ragMetadata['sources'] ?? null)) {
            return null;
        }

        $sources = [];
        foreach (array_slice($ragMetadata['sources'], 0, 6) as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sources[] = [
                'source_type' => (string) ($source['source_type'] ?? ''),
                'entity_type' => (string) ($source['entity_type'] ?? ''),
                'entity_id' => $source['entity_id'] ?? null,
                'project_id' => $source['project_id'] ?? null,
                'title' => $this->truncateText($this->normalizeText((string) ($source['title'] ?? '')), 180),
                'excerpt' => $this->truncateText($this->normalizeText((string) ($source['excerpt'] ?? '')), 260),
            ];
        }

        $query = $this->normalizeText((string) ($ragMetadata['search_query'] ?? $ragMetadata['query'] ?? ''));

        return $sources === []
            ? null
            : [
                'query' => $this->truncateText($query, 300),
                'sources' => $sources,
            ];
    }

    private function buildRagRequestContext(array $taskPlan, array $requestPayload): array
    {
        $request = is_array($taskPlan['request'] ?? null) ? $taskPlan['request'] : [];
        $planContext = is_array($request['context'] ?? null) ? $request['context'] : [];
        $payloadContext = is_array($requestPayload['context'] ?? null) ? $requestPayload['context'] : [];
        $context = array_merge($payloadContext, $planContext);
        $projectId = $this->resolveRagProjectId($planContext)
            ?? $this->resolveRagProjectId($payloadContext)
            ?? $this->resolveRagProjectId($context);

        if ($projectId !== null) {
            $context['project_id'] = $projectId;
        }

        return $context;
    }

    private function resolveRagProjectId(array $context): ?int
    {
        $projectId = $context['project_id'] ?? null;
        if (is_numeric($projectId)) {
            return (int) $projectId;
        }

        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $filterProjectId = $filters['project_id'] ?? null;
        if (is_numeric($filterProjectId)) {
            return (int) $filterProjectId;
        }

        foreach (($context['entity_refs'] ?? []) as $entityRef) {
            if (! is_array($entityRef) || ($entityRef['type'] ?? null) !== 'project') {
                continue;
            }

            $entityId = $entityRef['id'] ?? null;
            if (is_numeric($entityId)) {
                return (int) $entityId;
            }
        }

        return null;
    }

    protected function buildMessages(Conversation $conversation, array $context, array $taskPlan, string $ragPrompt = ''): array
    {
        $messages = [];
        $systemSections = [$this->contextBuilder->buildSystemPrompt()];

        if (! empty($context)) {
            $systemSections[] = $this->formatContextForLLM($context);
        }

        if (trim($ragPrompt) !== '') {
            $systemSections[] = $ragPrompt;
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
            "=== LEGACY CONTEXT ===\n".$this->formatValueForLLM($payload),
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
                'source_route' => $taskPlan['request']['context']['source_route'] ?? null,
                'entity_refs' => array_slice($taskPlan['request']['context']['entity_refs'] ?? [], 0, 3),
                'period' => $taskPlan['request']['context']['period'] ?? null,
                'report_focus' => $taskPlan['request']['context']['ui_state']['assistant_report_focus'] ?? null,
                'follow_up_query' => $taskPlan['request']['context']['ui_state']['assistant_follow_up_query'] ?? null,
                'filters_count' => is_array($taskPlan['request']['context']['filters'] ?? null)
                    ? count($taskPlan['request']['context']['filters'])
                    : 0,
                'assistant_path' => $taskPlan['request']['context']['ui_state']['assistant_path'] ?? null,
            ],
            'request_understanding' => $this->compactValueForLLM($taskPlan['request_understanding'] ?? []),
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
            ."1. Опирайся только на подтвержденные данные и доступный контекст.\n"
            ."2. Если данных или прав не хватает, прямо скажи об ограничении.\n"
            ."3. Не придумывай технические причины отказа и обходные пути.\n"
            ."4. Отвечай коротко и по делу, затем предлагай конкретный следующий шаг.\n"
            ."5. Если последняя реплика короткая и просит подробнее, продолжай предыдущий вопрос без повторного уточнения темы.\n"
            ."6. Соблюдай request_understanding: не создавай PDF, файл, отчет, навигацию или действие, если constraints/action_policy это запрещают.\n";

        return $this->truncateText(
            "=== STRUCTURED WORKSPACE CONTEXT ===\n"
            .$this->formatValueForLLM($this->compactValueForLLM($structuredContext))
            ."\n\n"
            .$policy,
            self::STRUCTURED_CONTEXT_CHARS
        );

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

        if (! is_array($value)) {
            return $this->normalizeText((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $prefix = str_repeat('  ', $depth);
            $formattedKey = is_string($key) ? $key : (string) $key;
            $formattedValue = is_array($item)
                ? "\n".$this->formatValueForLLM($item, $depth + 1)
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

        if (! is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $prefix = str_repeat('  ', $depth);
            $formattedKey = is_string($key) ? $key : (string) $key;
            $formattedValue = is_array($item)
                ? "\n".$this->formatValueForLLM($item, $depth + 1)
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

        if (! is_array($value)) {
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
        $toolNames = $this->resolveRelevantToolNames($taskPlan);
        $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);

        if (! $requestUnderstanding instanceof AssistantRequestUnderstanding) {
            return $this->toolRegistry->getToolsDefinitions($toolNames);
        }

        $allowedToolNames = [];
        $blockedTools = [];

        foreach ($toolNames as $toolName) {
            $eligibility = $this->toolEligibilityPolicy->canExposeTool($toolName, $requestUnderstanding);

            if ($eligibility->allowed) {
                $allowedToolNames[] = $toolName;
                continue;
            }

            $blockedTools[] = [
                'tool' => $toolName,
                ...$eligibility->toArray(),
            ];
        }

        $this->logging->technical('ai.assistant.tool_eligibility', [
            'primary_intent' => $requestUnderstanding->primaryIntent,
            'constraints' => $requestUnderstanding->constraints,
            'action_policy' => $requestUnderstanding->actionPolicy,
            'allowed_tools' => $allowedToolNames,
            'blocked_tools' => array_slice($blockedTools, 0, 12),
        ]);

        return $this->toolRegistry->getToolsDefinitions($allowedToolNames);
    }

    private function requestUnderstandingFromPlan(array $taskPlan): ?AssistantRequestUnderstanding
    {
        return is_array($taskPlan['request_understanding'] ?? null)
            ? AssistantRequestUnderstanding::fromArray($taskPlan['request_understanding'])
            : null;
    }

    private function toolBlockedMessage(?string $reason): string
    {
        unset($reason);

        return $this->assistantMessage(
            'ai_assistant.tool_blocked_by_request_policy',
            'Инструмент не выполнен, потому что текущий запрос ограничивает формат ответа или действия.'
        );
    }

    protected function resolveRelevantToolNames(array $taskPlan): array
    {
        $taskType = (string) ($taskPlan['task_type'] ?? 'summary');
        $capabilityId = $taskPlan['capability']['id'] ?? null;

        $capabilityTools = match ($capabilityId) {
            'projects' => ['get_project_snapshot', 'search_projects'],
            'contracts' => ['get_contract_snapshot', 'search_contractors'],
            'reports' => ['get_project_snapshot', 'get_procurement_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot', 'generate_profitability_report', 'generate_work_completion_report', 'generate_material_movements_report', 'generate_contractor_settlements_report', 'generate_contract_payments_report', 'generate_project_timelines_report', 'generate_time_tracking_report', 'generate_warehouse_stock_report', 'generate_operational_pdf_report'],
            'warehouse' => ['search_warehouse', 'search_materials'],
            'payments' => ['get_contract_snapshot', 'get_project_snapshot', 'approve_payment_request', 'generate_contract_payments_report'],
            'schedules' => ['get_schedule_snapshot', 'search_projects', 'create_schedule_task', 'update_schedule_task_status'],
            'procurement' => ['get_procurement_snapshot', 'get_project_snapshot', 'search_materials', 'search_contractors'],
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
                'get_project_snapshot',
                'get_procurement_snapshot',
                'get_contract_snapshot',
                'get_schedule_snapshot',
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

        if ($estimatedTokens > self::PROVIDER_INPUT_TOKEN_BUDGET && ! empty($preparedOptions['tools'])) {
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

            if (! empty($message['tool_calls'])) {
                $payload .= (string) json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $payload .= "\n";
            }
        }

        if (! empty($options['tools'])) {
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

        return rtrim(mb_substr($value, 0, $maxChars - 3)).'...';
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
            'id' => 'tool-'.$toolName.'-'.substr(md5(json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $toolName), 0, 12),
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
