<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialAnswerService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\LunaModelPolicy;
use App\Support\AI\TokenBudgetService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

class AIAssistantService
{
    private const HISTORY_MESSAGE_LIMIT = 100;

    private const HISTORY_TOTAL_CHARS = 120000;

    private const UNRESOLVED_DOMAIN_MESSAGE = 'Ассистент не смог однозначно определить домен запроса по текущему контексту.';

    private const HISTORY_USER_MESSAGE_CHARS = 4000;

    private const HISTORY_ASSISTANT_MESSAGE_CHARS = 24000;

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

    private ?AssistantRequest $activeRequest = null;

    private bool $runtimeTimingEnabled = false;

    private ?User $activeActor = null;

    private string $activeProfile = 'normal';

    private array $activeToolResults = [];

    private array $providerUsageAttempts = [];

    private ?array $pendingSummary = null;

    private ?string $requestOutcome = null;

    private bool $estimateResolutionAttempted = false;

    private ?int $resolvedEstimateId = null;

    private ?array $activeEstimateSelection = null;

    private array $currentAttachmentIds = [];

    private array $currentImageParts = [];

    private bool $documentContextBlocked = false;

    private ?array $precomputedCapabilityHints = null;

    private bool $preparationMetadataFrameActive = false;

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
        ?AssistantToolEligibilityPolicy $toolEligibilityPolicy = null,
        private readonly ?AssistantRequestLifecycle $requestLifecycle = null,
        private readonly TokenBudgetService $tokenBudget = new TokenBudgetService(),
        private readonly ?AssistantMemoryService $memoryService = null,
        private readonly ?AssistantFinancialAnswerService $financialAnswers = null,
        private readonly ?AssistantFinancialClaimVerifier $financialClaims = null,
        private readonly AssistantToolArgumentValidator $toolArguments = new AssistantToolArgumentValidator(),
        private readonly ?AssistantDataAccessPolicy $dataAccess = null,
        private readonly AssistantStructuredFactVerifier $structuredFacts = new AssistantStructuredFactVerifier(),
        private readonly ?AssistantLegacyLiveEvidenceAdapter $legacyLiveEvidence = null,
        private readonly ?AssistantDocumentCoverageService $documentCoverageService = null,
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
        array $requestPayload = [],
        ?string $surface = null
    ): array {
        $this->activeActor = $user;
        $this->activeProfile = (string) ($requestPayload['profile'] ?? 'normal');
        $this->activeToolResults = [];
        $this->documentContextBlocked = false;
        $this->pendingSummary = null;
        $this->requestOutcome = null;
        $this->estimateResolutionAttempted = false;
        $this->resolvedEstimateId = null;
        $this->currentAttachmentIds = $requestPayload['attachment_ids'] ?? [];
        $this->currentAttachmentIds = array_map('strtolower', $this->currentAttachmentIds);
        sort($this->currentAttachmentIds, SORT_STRING);
        $this->currentImageParts = [];
        if ($this->requestLifecycle === null) {
            try {
                return $this->performAsk($query, $organizationId, $user, $conversationId, $requestPayload);
            } finally {
                $this->activeActor = null;
                $this->activeToolResults = [];
                $this->documentContextBlocked = false;
            }
        }

        $payload = array_merge($requestPayload, ['message' => $query, 'conversation_id' => $conversationId]);
        $started = $this->requestLifecycle->start($this->resolveOrganization($organizationId), $user, $conversationId, $payload, $surface);
        if (is_array($started['response'])) {
            $this->activeActor = null;
            return $started['response'];
        }
        $this->activeRequest = $started['request'];
        $requestPayload['request_id'] = $this->activeRequest->request_id;
        try {
            $result = $this->performAsk($query, $organizationId, $user, $conversationId, $requestPayload);
            return $this->requestLifecycle->complete($this->activeRequest, $user, $result, $this->isUsefulAnswer($result), fn () => $this->savePendingSummary($user));
        } catch (Throwable $exception) {
            $this->requestLifecycle->fail($this->activeRequest, $this->requestErrorCode($exception));
            throw $exception;
        } finally {
            $this->activeRequest = null;
            $this->activeActor = null;
            $this->activeToolResults = [];
            $this->pendingSummary = null;
            $this->currentAttachmentIds = [];
            $this->currentImageParts = [];
            $this->documentContextBlocked = false;
        }
    }

    public function executeStartedRequest(AssistantRequest $request, User $user): array
    {
        if ($this->requestLifecycle === null || $request->started_at === null || !is_array($request->payload)
            || (int) $request->user_id !== (int) $user->id) {
            throw new \InvalidArgumentException('Invalid queued assistant request');
        }
        $payload = $request->payload;
        $query = $payload['message'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new \InvalidArgumentException('Invalid queued assistant payload');
        }
        $conversationId = isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null;
        $this->activeActor = $user;
        $this->activeProfile = (string) ($payload['profile'] ?? 'normal');
        $this->activeToolResults = [];
        $this->documentContextBlocked = false;
        $this->pendingSummary = null;
        $this->requestOutcome = null;
        $this->estimateResolutionAttempted = false;
        $this->resolvedEstimateId = null;
        $this->activeRequest = $request;
        $this->runtimeTimingEnabled = true;
        $this->currentAttachmentIds = $payload['attachment_ids'] ?? [];
        $this->currentImageParts = [];
        try {
            if ($this->currentAttachmentIds !== []) {
                $validated = $this->measurePhase('attachment_prepare', fn (): array => app(AssistantChatAttachmentService::class)->prepareRequest($payload, $user, (int) $request->organization_id));
                if (!hash_equals($request->request_hash, app(\App\Services\Credits\AICreditService::class)->canonicalAssistantRequest($validated))) {
                    throw new AuthorizationException(trans_message('ai_assistant.request_mismatch'));
                }
            }
            $this->measurePhase('request_context', fn () => $this->requestLifecycle->stage($request, $user, 'reading'));
            $result = $this->performAsk($query, (int) $request->organization_id, $user, $conversationId, $payload);
            return $this->measurePhase('request_complete', fn (): array => $this->requestLifecycle->complete($request, $user, $result, $this->isUsefulAnswer($result), fn () => $this->savePendingSummary($user)));
        } catch (Throwable $exception) {
            $errorCode = $this->requestErrorCode($exception);
            $this->requestLifecycle->fail($request, $errorCode);
            throw $exception;
        } finally {
            $this->runtimeTimingEnabled = false;
            $this->activeRequest = null;
            $this->activeActor = null;
            $this->activeToolResults = [];
            $this->pendingSummary = null;
            $this->currentAttachmentIds = [];
            $this->currentImageParts = [];
            $this->documentContextBlocked = false;
        }
    }

    private function shouldCheckDocumentCorpus(string $query, array $conversationContext): bool
    {
        if ($this->isDocumentKnowledgeRequest($query)) {
            return true;
        }

        if ($this->isLiveFinancialContinuation($query) || ! $this->isDetailContinuation($query)) {
            return false;
        }

        $lastRagContext = is_array($conversationContext['last_rag_context'] ?? null)
            ? $conversationContext['last_rag_context'] : [];
        foreach ($lastRagContext['sources'] ?? [] as $source) {
            if (is_array($source) && (($source['source_type'] ?? null) === 'file_document'
                || ($source['entity_type'] ?? null) === 'assistant_document')) {
                return true;
            }
        }

        return false;
    }

    private function isLiveFinancialContinuation(string $query): bool
    {
        $normalized = mb_strtolower(trim($query));

        return $this->containsAnyText($normalized, [
            'по деньгам', 'деньгам', 'платеж', 'платёж', 'оплат', 'сумм', 'смет', 'счет', 'счёт',
        ]);
    }

    private function suppressDocumentContinuationContext(array $conversationContext): array
    {
        unset($conversationContext['last_rag_context']);

        foreach (['last_request', 'last_request_context'] as $key) {
            if (! is_array($conversationContext[$key] ?? null)) {
                continue;
            }

            $requestContext = $key === 'last_request'
                ? (is_array($conversationContext[$key]['context'] ?? null) ? $conversationContext[$key]['context'] : [])
                : $conversationContext[$key];
            $uiState = is_array($requestContext['ui_state'] ?? null) ? $requestContext['ui_state'] : [];
            unset($uiState['assistant_follow_up_query']);
            $requestContext['ui_state'] = $uiState;
            if ($key === 'last_request') {
                $conversationContext[$key]['context'] = $requestContext;
            } else {
                $conversationContext[$key] = $requestContext;
            }
        }

        return $conversationContext;
    }

    private function isDocumentKnowledgeRequest(string $query): bool
    {
        $normalized = mb_strtolower(trim($query));
        if ($this->containsAnyText($normalized, [
            'база знаний', 'базы знаний', 'базе знаний', 'фрагмент', 'фрагменты',
        ])) {
            return true;
        }

        if (preg_match('/(?:загруженн\p{L}*|прикрепл\p{L}*|вложенн\p{L}*)\s+(?:[\p{L}-]+\s+){0,2}(?:документ\p{L}*|файл\p{L}*|договор\p{L}*)/u', $normalized) === 1) {
            return true;
        }

        $mentionsDocument = preg_match('/(?:документ\p{L}*|файл\p{L}*|договор\p{L}*|стать\p{L}*|инструкц\p{L}*|регламент\p{L}*|методич\p{L}*|руководств\p{L}*)/u', $normalized) === 1;
        if (! $mentionsDocument) {
            $financialRequest = $this->containsAnyText($normalized, [
                'платеж', 'платёж', 'оплат', 'сумм', 'смет', 'счет', 'счёт', 'склад', 'остатк',
            ]);

            return ! $financialRequest && $this->containsAnyText($normalized, ['источник', 'источники']);
        }

        return $this->containsAnyText($normalized, [
            'что написано', 'что в документ', 'что в стать', 'что в инструкц', 'что в регламент',
            'содержание', 'текст', 'перескаж', 'прочитай', 'прочт', 'изучи', 'проанализируй', 'услови',
            'пункт', 'правил', 'требован', 'неустойк', 'гаранти', 'ответственност', 'расторжен',
        ]);
    }

    private function documentCorpusIsComplete(int $organizationId, User $actor): bool
    {
        try {
            $coverageService = $this->documentCoverageService ?? app(AssistantDocumentCoverageService::class);
            $execution = app()->bound(AssistantRequestExecutionContext::class)
                ? app(AssistantRequestExecutionContext::class) : null;
            $coverage = fn (): array => $coverageService->coverage(
                $organizationId,
                $actor,
                $execution === null ? null : $execution->assertCanContinue(...),
                $execution === null ? null : fn (): int => $execution->remainingMilliseconds()
            );
            $result = $execution === null ? $coverage() : $execution->withDatabaseStatementTimeout(
                fn (): array => $execution->withOperationBudget($coverage, 30_000, 'pgsql')
            );

            return AssistantDocumentCoverageService::isCompleteForAnswer($result);
        } catch (AssistantRequestCancelled|AssistantRequestDeadlineExceeded|AuthorizationException|AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    private function performAsk(string $query, int $organizationId, User $user, ?int $conversationId, array $requestPayload): array
    {
        $this->providerUsageAttempts = [];
        if (! $this->measurePhase('request_permission', fn (): bool => $this->permissionChecker->canUseAssistant($user, $organizationId))) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.access_denied', 'Недостаточно прав для работы с AI-ассистентом.'));
        }

        $this->logging->business('ai.assistant.request', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'query_length' => strlen($query),
        ]);

        $standaloneGreeting = $this->isStandaloneGreeting($query, $requestPayload);
        if (! $standaloneGreeting && $this->activeRequest !== null) {
            $this->requestLifecycle?->assertReservedBudget($this->activeRequest, $user);
        } elseif (!$standaloneGreeting && ! $this->usageTracker->canMakeRequest($organizationId)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.limit_exceeded', 'Исчерпан месячный лимит запросов к AI-ассистенту.'));
        }

        $conversation = $this->measurePhase('conversation', function () use ($conversationId, $organizationId, $user): Conversation {
            $conversation = $this->getOrCreateConversation($conversationId, $organizationId, $user);
            if ($this->activeRequest !== null) {
                $this->requestLifecycle?->bindConversation($this->activeRequest, $conversation, $user);
            }
            $this->stage('reading');

            return $conversation;
        });
        if ($standaloneGreeting) {
            return $this->measurePhase('greeting', fn (): array => $this->answerStandaloneGreeting($query, $organizationId, $user, $conversation, $requestPayload));
        }
        $navigationRequestContext = $requestPayload['context'] ?? [];
        $continuationContext = $conversation->context ?? [];
        $previousImageDiscussion = (bool) ($continuationContext['last_image_discussion'] ?? false);
        if (! array_key_exists('last_image_discussion', $continuationContext) && $this->currentAttachmentIds === []
            && AssistantImageDiscussionPolicy::isDiscussion($query, false, true)) {
            foreach ($this->conversationManager->getHistory($conversation, 10, $user)->reverse() as $previousMessage) {
                if ($previousMessage->role !== 'user') {
                    continue;
                }
                if (! empty($previousMessage->metadata['attachments'])) {
                    $previousImageDiscussion = AssistantImageDiscussionPolicy::isDiscussion($previousMessage->content, true, false);
                    break;
                }
                if (! AssistantImageDiscussionPolicy::isDiscussion($previousMessage->content, false, true)) {
                    break;
                }
            }
        }
        $imageDiscussion = AssistantImageDiscussionPolicy::isDiscussion($query, $this->currentAttachmentIds !== [], $previousImageDiscussion);
        $requestPayload['image_discussion'] = $imageDiscussion;
        if ($imageDiscussion) {
            $requestPayload['context'] = [];
            $requestPayload['goal'] = 'analyze';
            $requestPayload['desired_mode'] = 'analyze';
            $requestPayload['allow_actions'] = false;
        }
        if (! $imageDiscussion && $this->currentAttachmentIds === [] && $this->shouldCheckDocumentCorpus($query, $continuationContext)) {
            $this->documentContextBlocked = ! $this->documentCorpusIsComplete($organizationId, $user);
            if ($this->documentContextBlocked) {
                $continuationContext = $this->suppressDocumentContinuationContext($continuationContext);
            }
        }
        if (! $imageDiscussion) {
            $requestPayload = $this->mergeContinuationRequestPayload($query, $requestPayload, $continuationContext);
        }
        if ($this->documentContextBlocked && is_array($requestPayload['context'] ?? null)) {
            $requestContext = $requestPayload['context'];
            $uiState = is_array($requestContext['ui_state'] ?? null) ? $requestContext['ui_state'] : [];
            unset($uiState['assistant_follow_up_query']);
            $requestContext['ui_state'] = $uiState;
            $requestPayload['context'] = $requestContext;
        }
        $requestPayload = $this->measurePhase('request_context', fn (): array => $this->filterRequestEntityContext($requestPayload, $user, $organizationId));
        $this->activeEstimateSelection = null;
        $selection = $conversation->context['selected_estimate'] ?? null;
        if (! $imageDiscussion && is_array($selection) && is_int($selection['estimate_id'] ?? null) && $this->dataAccess !== null
            && $this->dataAccess->withCurrentChecks($user, $organizationId,
                fn (): bool => $this->dataAccess->canReadEntityContent($user, $organizationId, 'estimate', $selection['estimate_id']), true)) {
            $this->activeEstimateSelection = array_intersect_key($selection, array_flip(['estimate_id', 'position_filter', 'position_numbers']));
        }
        $accessContext = $this->measurePhase('access_context', fn (): array => $this->accessContextResolver->resolve($user, $organizationId));
        $taskPlan = $this->measurePhase('task_plan', fn (): array => $this->taskOrchestrator->plan($query, $requestPayload, $accessContext));
        $taskPlan['image_discussion'] = $imageDiscussion;
        $this->logRequestUnderstanding($taskPlan, $organizationId, $user);
        $businessDomain = $this->businessDataDomain($taskPlan);
        if ($businessDomain !== null && $this->dataAccess !== null && !$this->dataAccess->canReadDomain($user, $organizationId, $businessDomain)) {
            $this->recordRequestOutcome('access_denied');
        }

        $userMessage = $this->conversationManager->addMessage(
            $conversation,
            'user',
            $query,
            0,
            LunaModelPolicy::forProvider((string) config('ai-assistant.llm.provider', 'timeweb')),
            [
                'actor_user_id' => (int) $user->id,
                'request_id' => $requestPayload['request_id'] ?? null,
                'request' => $taskPlan['request'],
                'task_type' => $taskPlan['task_type'],
                'capability' => $taskPlan['capability']['id'] ?? null,
                'access_context' => $taskPlan['access_context_public'],
            ]
        );

        if ($this->currentAttachmentIds !== []) {
            app(AssistantChatAttachmentService::class)->linkMessage($this->currentAttachmentIds, $userMessage, $user);
        }

        $legacyContext = ['intent' => $taskPlan['request_understanding']['primary_intent'] ?? $taskPlan['task_type'] ?? 'summary'];
        $currentIntent = $legacyContext['intent'];
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

        if (($taskPlan['section_navigation'] ?? false) && $this->activeEstimateSelection === null
            && empty($conversationContext['selected_estimate']) && empty($conversationContext['selected_estimate_id'])
            && is_array($navigationRequestContext) && AssistantRequestUnderstandingResolver::hasOnlyBackgroundProjectReferences($navigationRequestContext)
            && empty($navigationRequestContext['entity_references']) && empty($navigationRequestContext['filters'])
            && empty($navigationRequestContext['period'])
            && array_diff(array_keys($navigationRequestContext), ['source_module', 'source_route', 'ui_state', 'entity_refs', 'period', 'filters']) === []) {
            return $this->answerSectionNavigation($query, $organizationId, $user, $conversation, $requestPayload);
        }

        $agentResult = ! $imageDiscussion && $this->currentAttachmentIds === [] ? $this->handleAgentFlow($query, $organizationId, $user, $conversation, $taskPlan) : null;
        if ($agentResult !== null) {
            return $agentResult;
        }

        $ragMetadata = ['used' => false, 'sources' => []];
        [$messages, $tools] = $this->measurePhase('preparation', fn (): array => $this->readPhase(function () use ($taskPlan, $user, $organizationId, $conversation, $legacyContext, $query): array {
            $metadata = $this->buildPreparationMetadata($taskPlan, $user, $organizationId);
            $messages = $this->measurePhase('messages', fn (): array => $this->buildMessagesWithCapabilityHints($conversation, $legacyContext, $taskPlan, $query, $metadata['capability_hints']));

            return [$messages, $metadata['tools']];
        }, $user, $organizationId));

        $verificationTimer = null;
        try {
            $options = [];
            $options['profile'] = 'assistant';
            $options['budget_profile'] = $this->activeProfile;
            if (! $imageDiscussion) {
                $this->recordUnavailableBusinessTools($taskPlan, $tools);
            }
            if (! empty($tools)) {
                $options['tools'] = $tools;
            }

            $toolFailures = [];
            $toolEvidence = [];
            $proposedActions = [];
            $trustedDownloadUrls = [];
            $degradedMode = false;
            $budgetStopNotice = null;

            $responseEnvelope = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
            $response = $responseEnvelope['response'];
            $degradedMode = (bool) ($responseEnvelope['degraded_mode'] ?? false);

            if (is_string($responseEnvelope['fallback_reason'] ?? null)) {
                $toolFailures[] = $responseEnvelope['fallback_reason'];
            }

            $loopCount = 0;
            $maxLoops = $this->activeRequest !== null ? max(0, $this->activeRequest->max_calls - 1)
                : (int) config('ai-assistant-credits.profiles.'.$this->activeProfile.'.max_calls', TokenBudgetService::limits($this->activeProfile)['calls']) - 1;
            $organization = null;

            while (! empty($response['function_calls']) && $loopCount < $maxLoops) {
                if (! $organization instanceof Organization) {
                    $organization = $this->resolveOrganization($organizationId);
                }

                array_push($messages, ...$response['output']);

                foreach ($response['function_calls'] as $toolCall) {
                    $this->stage('tools');
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

                    $toolName = (string) ($toolCall['name'] ?? '');
                    $providerResult = $this->toolResultForProvider($toolName, $toolResult);
                    $toolContent = is_string($providerResult)
                        ? $providerResult
                        : (json_encode($providerResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"tool_result_serialization_failed"}');

                    $messages[] = [
                        'type' => 'function_call_output',
                        'call_id' => $toolCall['call_id'],
                        'output' => $toolContent,
                    ];

                    $serverResult = $this->activeToolResults[0] ?? null;
                    $serverAnswer = is_array($serverResult) ? ($serverResult['server_formatted_answer'] ?? null) : null;
                    $financialEvidence = is_array($serverResult) ? ($serverResult['financial_evidence'] ?? null) : null;
                    $sourceRefs = is_array($serverResult) ? ($serverResult['source_refs'] ?? null) : null;
                    $resolution = is_array($serverResult) ? ($serverResult['resolution'] ?? null) : null;
                    $selection = is_array($serverResult) ? ($serverResult['selection'] ?? null) : null;
                    $estimateEvidence = is_array($financialEvidence) ? ($financialEvidence['estimate'] ?? null) : null;
                    $stockEvidence = is_array($serverResult) ? ($serverResult['stock_evidence'] ?? null) : null;
                    $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);
                    $requestPlan = is_array($taskPlan['request'] ?? null) ? $taskPlan['request'] : [];
                    $requestMessage = is_string($requestPlan['message'] ?? null) ? $requestPlan['message'] : '';
                    $expectedEntity = match ($toolName) {
                        'get_material_stock' => 'warehouse',
                        'get_estimate_answer' => 'estimate',
                        default => null,
                    };
                    $expectedDomains = match ($toolName) {
                        'get_material_stock' => ['warehouse', 'operations_warehouse'],
                        'get_estimate_answer' => ['estimates', 'core_estimates'],
                        default => [],
                    };
                    $understandingEvidence = $requestUnderstanding?->evidence ?? [];
                    $understoodEntities = array_values(array_unique(array_map(
                        static fn (array $item): string => (string) ($item['value'] ?? ''),
                        array_filter($understandingEvidence, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'entity')
                    )));
                    $understoodDomains = array_values(array_unique(array_map(
                        static fn (array $item): string => (string) ($item['value'] ?? ''),
                        array_filter($understandingEvidence, static fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'primary_domain')
                    )));
                    $classifiedIntent = $requestMessage === '' ? null : $this->intentRecognizer->recognize($requestMessage);
                    $intentMatchesTool = match ($toolName) {
                        'get_material_stock' => $classifiedIntent === 'material_stock',
                        'get_estimate_answer' => $classifiedIntent === 'general',
                        default => false,
                    };
                    $singleIntentRequest = $requestUnderstanding !== null
                        && $expectedEntity !== null
                        && $requestUnderstanding->primaryIntent === 'question'
                        && $requestUnderstanding->outputFormat === 'text'
                        && $requestUnderstanding->actionPolicy === 'read_only'
                        && $requestUnderstanding->constraints === []
                        && $requestUnderstanding->confidence >= 0.65
                        && $requestUnderstanding->requestedEntities === [$expectedEntity]
                        && $understoodEntities === [$expectedEntity]
                        && count($understoodDomains) === 1
                        && in_array($understoodDomains[0], $expectedDomains, true)
                        && in_array($this->businessDataDomain($taskPlan), $expectedDomains, true)
                        && in_array($requestPlan['goal'] ?? null, [null, ''], true)
                        && in_array($requestPlan['desired_mode'] ?? null, [null, ''], true)
                        && $intentMatchesTool;
                    if (
                        $loopCount === 0
                        && is_array($response['function_calls'] ?? null)
                        && count($response['function_calls']) === 1
                        && count($this->activeToolResults) === 1
                        && $toolName === 'get_material_stock'
                        && $this->isTerminalReadOnlyTool($toolName)
                        && $singleIntentRequest
                        && $executedAction === null
                        && $proposedActions === []
                        && $toolFailures === []
                        && ! $degradedMode
                        && is_array($serverResult)
                        && ($serverResult['_tool_name'] ?? null) === $toolName
                        && ($serverResult['status'] ?? null) === 'empty'
                        && ($serverResult['validation_status'] ?? null) === 'verified'
                        && ($serverResult['needs_clarification'] ?? false) === false
                        && is_string($serverAnswer)
                        && trim($serverAnswer) !== ''
                        && is_array($serverResult['stock'] ?? null)
                        && $serverResult['stock'] === []
                        && is_array($sourceRefs)
                        && $sourceRefs === []
                        && is_array($serverResult['quantity_scope'] ?? null)
                        && is_array($stockEvidence)
                        && ($stockEvidence['scope'] ?? null) === 'warehouse_balance_sum'
                        && ($stockEvidence['organization_id'] ?? null) === $organizationId
                        && ($stockEvidence['actor_id'] ?? null) === (int) $user->id
                        && is_array($stockEvidence['filters'] ?? null)
                        && ($stockEvidence['quantity_scope'] ?? null) === $serverResult['quantity_scope']
                        && is_array($stockEvidence['rows'] ?? null)
                        && $stockEvidence['rows'] === []
                        && ($stockEvidence['validation_status'] ?? null) === 'verified'
                        && is_string($stockEvidence['version'] ?? null)
                        && $stockEvidence['version'] !== ''
                    ) {
                        $response['content'] = $serverAnswer;
                        $response['actual_model'] = $response['provider_response_ref'] = $response['api_method'] = null;
                        $response['model_invoked'] = false;
                        $response['function_calls'] = [];
                        break 2;
                    }

                    if (
                        $loopCount === 0
                        && is_array($response['function_calls'] ?? null)
                        && count($response['function_calls']) === 1
                        && count($this->activeToolResults) === 1
                        && $toolName === 'get_estimate_answer'
                        && $this->isTerminalReadOnlyTool($toolName)
                        && $singleIntentRequest
                        && $executedAction === null
                        && $proposedActions === []
                        && $toolFailures === []
                        && ! $degradedMode
                        && is_array($serverResult)
                        && ($serverResult['_tool_name'] ?? null) === $toolName
                        && ($serverResult['status'] ?? null) === 'resolved'
                        && ($serverResult['validation_status'] ?? null) === 'verified'
                        && ($serverResult['needs_clarification'] ?? null) === false
                        && is_array($resolution)
                        && ($resolution['status'] ?? null) === 'resolved'
                        && is_int($resolution['estimate_id'] ?? null)
                        && is_array($selection)
                        && ($selection['estimate_id'] ?? null) === $resolution['estimate_id']
                        && is_string($serverAnswer)
                        && trim($serverAnswer) !== ''
                        && is_array($sourceRefs)
                        && $sourceRefs !== []
                        && is_array($financialEvidence)
                        && ($financialEvidence['validation_status'] ?? null) === 'verified'
                        && ($financialEvidence['totals_validation_status'] ?? null) === 'verified'
                        && is_array($estimateEvidence)
                        && ($estimateEvidence['id'] ?? null) === $resolution['estimate_id']
                        && is_array($financialEvidence['totals'] ?? null)
                        && is_string($financialEvidence['fetched_at'] ?? null)
                        && $financialEvidence['fetched_at'] !== ''
                        && is_string($financialEvidence['version'] ?? null)
                        && $financialEvidence['version'] !== ''
                        && ($financialEvidence['source_refs'] ?? null) === $sourceRefs
                    ) {
                        $financialProof = $this->financialClaims?->guard($serverAnswer, [$serverResult]);
                        $structuredProof = $this->structuredFacts->guard($query, $serverAnswer, [$serverResult]);
                        if (
                            is_array($financialProof)
                            && ($financialProof['validation_status'] ?? null) === 'verified'
                            && ($financialProof['source_refs'] ?? null) === $sourceRefs
                            && ($financialProof['text'] ?? null) === $serverAnswer
                            && is_array($structuredProof)
                            && ($structuredProof['validation_status'] ?? null) === 'verified'
                            && ($structuredProof['needs_clarification'] ?? null) === false
                            && ($structuredProof['source_refs'] ?? null) === $sourceRefs
                            && ($structuredProof['text'] ?? null) === $serverAnswer
                        ) {
                            $response['content'] = $serverAnswer;
                            $response['actual_model'] = $response['provider_response_ref'] = $response['api_method'] = null;
                            $response['model_invoked'] = false;
                            $response['function_calls'] = [];
                            break 2;
                        }
                    }
                }

                try {
                    $responseEnvelope = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
                } catch (AssistantBudgetExceeded) {
                    $budgetStopNotice = trans_message('ai_assistant.approved_budget_exceeded');
                    $toolFailures[] = $budgetStopNotice;
                    $response['content'] = trans_message('ai_assistant.budget_partial_answer');
                    $response['actual_model'] = $response['provider_response_ref'] = $response['api_method'] = null;
                    $response['model_invoked'] = false;
                    $response['function_calls'] = [];
                    $degradedMode = true;
                    break;
                } catch (\DomainException $exception) {
                    if ($exception->getMessage() !== 'ai_token_budget_exhausted') {
                        throw $exception;
                    }
                    $budgetStopNotice = trans_message('ai_assistant.context_budget_exhausted');
                    $toolFailures[] = $budgetStopNotice;
                    $response['content'] = trans_message('ai_assistant.context_budget_exhausted');
                    $response['actual_model'] = $response['provider_response_ref'] = $response['api_method'] = null;
                    $response['model_invoked'] = false;
                    $response['function_calls'] = [];
                    $degradedMode = true;
                    break;
                }
                $response = $responseEnvelope['response'];
                $degradedMode = $degradedMode || (bool) ($responseEnvelope['degraded_mode'] ?? false);

                if (is_string($responseEnvelope['fallback_reason'] ?? null)) {
                    $toolFailures[] = $responseEnvelope['fallback_reason'];
                }

                $loopCount++;
            }

            $terminalToolResponse = ! empty($response['function_calls']);
            if ($terminalToolResponse) {
                $user->refresh();
                $this->stage('tools');
                $toolCalls = $response['function_calls'];
                $toolCall = is_array($toolCalls) && count($toolCalls) === 1 ? reset($toolCalls) : null;
                $toolName = is_array($toolCall) ? (string) ($toolCall['name'] ?? '') : '';
                $advertisedTools = array_column($tools, 'name');

                if (is_array($toolCall) && in_array($toolName, $advertisedTools, true)
                    && $this->isTerminalReadOnlyTool($toolName) && $this->toolRegistry->getTool($toolName) !== null) {
                    if (! $organization instanceof Organization) {
                        $organization = $this->resolveOrganization($organizationId);
                    }
                    $this->handleToolCall(
                        $toolCall,
                        $organization,
                        $user,
                        $organizationId,
                        $taskPlan,
                        false,
                        $executedAction,
                        $toolEvidence,
                        $toolFailures,
                        $proposedActions,
                        $trustedDownloadUrls
                    );
                }

                $response['content'] = trans_message('ai_assistant_facts.live_proof_required');
                $response['actual_model'] = $response['provider_response_ref'] = $response['api_method'] = null;
                $response['model_invoked'] = false;
                $response['function_calls'] = [];
            }

            $toolFailures = array_values(array_unique(array_filter(
                $toolFailures,
                static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
            )));
            $hasCurrentChatImages = $this->currentAttachmentIds !== [];
            $documentCorpusIncomplete = ! $hasCurrentChatImages && $this->documentContextBlocked;
            $documentSearchPartial = false;
            foreach ($this->activeToolResults as $toolResult) {
                if ($hasCurrentChatImages || ($toolResult['_tool_name'] ?? null) !== 'search_assistant_documents') {
                    continue;
                }
                $documentCorpusIncomplete = $documentCorpusIncomplete || ($toolResult['status'] ?? null) === 'corpus_incomplete';
                $documentSearchPartial = $documentSearchPartial || ($toolResult['status'] ?? null) === 'partial';
            }
            $verificationToolResults = $documentCorpusIncomplete || $documentSearchPartial
                ? array_values(array_filter($this->activeToolResults,
                    static fn (array $toolResult): bool => ! in_array($toolResult['_tool_name'] ?? null,
                        ['search_assistant_documents', 'generate_rag_pdf_report'], true)))
                : $this->activeToolResults;
            $ragMetadata = $this->ragMetadataFromToolResults($query, $verificationToolResults);

            $verificationTimer = AssistantRequestPhaseTimer::start($this->runtimeTimingEnabled ? $this->activeRequest?->request_id : null, 'final_verification');
            $assistantContent = trim((string) ($response['content'] ?? ''));
            $plannedPresentation = $proposedActions === [] ? (new AssistantPresentationPlanner)->render($assistantContent, $verificationToolResults, $query) : null;
            $rawPresentationPlan = $proposedActions === [] && AssistantPresentationPlanner::isPlanCandidate($assistantContent)
                ? $assistantContent : null;
            $this->stage('verifying');
            if ($assistantContent === '') {
                $assistantContent = $this->assistantMessage('ai_assistant.empty_response', 'Не удалось сформировать содержательный ответ по текущему запросу.');
            }
            $assistantContent = $this->stripUntrustedMarkdownLinks($assistantContent, $trustedDownloadUrls);
            $assistantContent = $this->guardUnconfirmedReportCompletion($assistantContent, $taskPlan, $trustedDownloadUrls);
            $assistantContent = $this->responseVerifier->verify($assistantContent, [
                'rag_context' => $ragMetadata,
            ]);
            $assistantContent = $this->softenUnsupportedCriticalClaims($assistantContent, $ragMetadata);

            $financialCheck = $imageDiscussion ? null : $this->financialClaims?->guard($plannedPresentation ?? $assistantContent, $verificationToolResults, $rawPresentationPlan, $query);
            if (is_array($financialCheck) && is_string($financialCheck['text'] ?? null)) {
                $assistantContent = $financialCheck['text'];
            }
            $rejectedFinancialPlan = $rawPresentationPlan !== null && AssistantPresentationPlanner::hasFinancialSelection($rawPresentationPlan)
                && (!in_array($financialCheck['validation_status'] ?? null, ['verified', 'partial'], true)
                    || ($financialCheck['source_refs'] ?? []) === []
                    || !(new AssistantPresentationPlanner)->financialSelectionCovered($rawPresentationPlan, $verificationToolResults, $financialCheck['source_refs'] ?? []));
            if ($rejectedFinancialPlan) {
                $rawPresentationPlan = null;
            }
            $structuredCheck = ! $imageDiscussion && $proposedActions === []
                ? ($rejectedFinancialPlan ? ($financialCheck ?? ['text' => trans_message('ai_assistant_financial.unverified_claim'),
                    'validation_status' => 'partial', 'source_refs' => []])
                    : $this->structuredFacts->guard($query, $rawPresentationPlan ?? $assistantContent, $verificationToolResults))
                : null;
            if (is_array($structuredCheck)) {
                $assistantContent = $structuredCheck['text'];
            }
            if ($terminalToolResponse && ($structuredCheck['source_refs'] ?? []) === [] && $proposedActions === []
                && ! $rejectedFinancialPlan && ! AssistantFactIntentClassifier::isMoneyOnly($query)) {
                $terminalProof = $this->structuredFacts->confirmedResults($verificationToolResults, $query);
                if (($terminalProof['source_refs'] ?? []) !== []) {
                    $terminalProof['needs_clarification'] = (bool) ($structuredCheck['needs_clarification'] ?? false);
                    $assistantContent = $terminalProof['text'];
                    $structuredCheck = $terminalProof;
                }
            }
            $clarificationSelection = is_array($conversation->context['selected_estimate'] ?? null)
                ? $conversation->context['selected_estimate'] : null;
            $clarificationPinnedId = isset($clarificationSelection['estimate_id']) ? (int) $clarificationSelection['estimate_id'] : null;
            if ($clarificationPinnedId === null) {
                foreach ($taskPlan['request']['context']['entity_refs'] ?? [] as $ref) {
                    if (is_array($ref) && ($ref['type'] ?? null) === 'estimate' && is_numeric($ref['id'] ?? null)) {
                        $clarificationPinnedId = (int) $ref['id'];
                        break;
                    }
                }
            }
            $financialIntentSupported = ! $imageDiscussion && ($this->financialAnswers?->supports($query, $clarificationPinnedId) ?? false);
            $stockDomainResolved = false;
            foreach ($rawPresentationPlan !== null || $rejectedFinancialPlan ? [] : array_reverse($this->activeToolResults) as $toolResult) {
                if (($toolResult['_tool_name'] ?? null) === 'get_material_stock') {
                    $verifiedStock = $this->verifyMaterialStock($toolResult, $user, $organizationId);
                    if ($verifiedStock !== null) {
                        $stockDomainResolved = in_array($toolResult['status'] ?? null, ['success', 'empty'], true)
                            && ($verifiedStock['validation_status'] ?? null) === 'verified'
                            && ($verifiedStock['needs_clarification'] ?? true) === false;
                        $assistantContent = $verifiedStock['text'];
                        $structuredCheck = $verifiedStock;
                        $compoundParts = false;
                        foreach ($this->activeToolResults as $financialResult) {
                            if (($financialResult['_tool_name'] ?? null) === 'get_estimate_answer'
                                && !($financialResult['needs_clarification'] ?? true)
                                && is_array($financialResult['financial_evidence'] ?? null)
                                && ($financialResult['financial_evidence']['fetched_at'] ?? null) !== null
                                && ($financialResult['financial_evidence']['source_refs'] ?? []) !== []
                                && is_string($financialResult['server_formatted_answer'] ?? null)) {
                                $assistantContent = $financialResult['server_formatted_answer']."\n\n".$assistantContent;
                                $compoundParts = true;
                                $structuredCheck['text'] = $assistantContent;
                                $structuredCheck['source_refs'] = array_merge($financialResult['source_refs'] ?? [], $structuredCheck['source_refs']);
                                if (($financialResult['validation_status'] ?? 'partial') !== 'verified') {
                                    $structuredCheck['validation_status'] = 'partial';
                                }
                            }
                        }
                        $otherFacts = $this->structuredFacts->confirmedResults(array_values(array_filter($verificationToolResults,
                            static fn (array $result): bool => !in_array($result['_tool_name'] ?? null, ['get_material_stock', 'get_estimate_answer'], true))), $query);
                        if ($otherFacts['source_refs'] !== []) {
                            $assistantContent .= "\n\n".$otherFacts['text'];
                            $compoundParts = true;
                            $structuredCheck['text'] = $assistantContent;
                            $structuredCheck['source_refs'] = array_merge($structuredCheck['source_refs'], $otherFacts['source_refs']);
                            $structuredCheck['validation_status'] = 'partial';
                            $structuredCheck['structured_evidence_truncated'] = $otherFacts['structured_evidence_truncated'] ?? false;
                        }
                        if ($compoundParts) {
                            $structuredCheck['validation_status'] = 'partial';
                            $assistantContent .= "\n\n".trans_message('ai_assistant.checked_sources_only');
                        }
                        $structuredCheck['text'] = $assistantContent;
                        break;
                    }
                }
                if ($financialIntentSupported && ($toolResult['_tool_name'] ?? null) === 'get_estimate_answer' && ($toolResult['needs_clarification'] ?? false)
                    && is_string($toolResult['server_formatted_answer'] ?? null)) {
                    $assistantContent = $toolResult['server_formatted_answer'];
                    $structuredCheck = ['text' => $assistantContent, 'replaced' => true, 'validation_status' => 'partial',
                        'needs_clarification' => true, 'source_refs' => $toolResult['source_refs'] ?? []];
                    break;
                }
            }

            foreach ($rawPresentationPlan !== null || $rejectedFinancialPlan ? [] : array_reverse($verificationToolResults) as $toolResult) {
                if (($toolResult['_tool_name'] ?? null) === 'get_bim_model_elements' && $proposedActions === []) {
                    $verifiedBim = $this->verifyBimModel($toolResult, $user, $organizationId);
                    if (($structuredCheck['source_refs'] ?? []) !== []) {
                        $verifiedBim['text'] .= "\n\n".$structuredCheck['text'];
                        $verifiedBim['source_refs'] = array_merge($verifiedBim['source_refs'], $structuredCheck['source_refs']);
                        if (($verifiedBim['validation_status'] ?? null) !== 'verified' || ($structuredCheck['validation_status'] ?? null) !== 'verified') {
                            $verifiedBim['validation_status'] = 'partial';
                        }
                        $verifiedBim['needs_clarification'] = (bool) ($verifiedBim['needs_clarification'] ?? false)
                            || (bool) ($structuredCheck['needs_clarification'] ?? false);
                    }
                    $assistantContent = $verifiedBim['text'];
                    $structuredCheck = $verifiedBim;
                    break;
                }
            }
            $documentNotices = [];
            $documentUnavailable = false;
            foreach ($this->activeToolResults as $toolResult) {
                if ($hasCurrentChatImages || ($toolResult['_tool_name'] ?? null) !== 'search_assistant_documents') {
                    continue;
                }
                if (($toolResult['status'] ?? null) === 'unavailable') {
                    $documentUnavailable = true;
                    $documentNotices[] = trans_message('ai_assistant.document_search_unavailable');
                } elseif (($toolResult['status'] ?? null) === 'partial') {
                    $documentNotices[] = trans_message('ai_assistant.document_search_partial');
                }
            }
            if ($documentCorpusIncomplete || $documentSearchPartial) {
                $documentNotice = trans_message($documentCorpusIncomplete
                    ? 'ai_assistant.document_corpus_incomplete' : 'ai_assistant.document_search_partial');
                $serverVerifiedText = null;
                $serverVerifiedRefs = [];
                if (is_array($structuredCheck) && (($structuredCheck['source_refs'] ?? []) !== []
                    || ($stockDomainResolved && ($structuredCheck['validation_status'] ?? null) === 'verified'))
                    && is_string($structuredCheck['text'] ?? null) && trim($structuredCheck['text']) !== '') {
                    $serverVerifiedText = trim($structuredCheck['text']);
                    $serverVerifiedRefs = $structuredCheck['source_refs'];
                } elseif (is_array($financialCheck) && ($financialCheck['source_refs'] ?? []) !== []
                    && is_string($financialCheck['text'] ?? null) && trim($financialCheck['text']) !== '') {
                    $serverVerifiedText = trim($financialCheck['text']);
                    $serverVerifiedRefs = $financialCheck['source_refs'];
                }
                $assistantContent = $serverVerifiedText === null
                    ? $documentNotice
                    : $serverVerifiedText."\n\n".$documentNotice;
                $structuredCheck = ['text' => $assistantContent, 'validation_status' => 'partial',
                    'source_refs' => $serverVerifiedRefs, 'replaced' => true, 'needs_clarification' => false];
                $ragMetadata = ['enabled' => true, 'used' => false, 'query' => $query, 'sources' => [], 'limits' => ['returned' => 0]];
                $degradedMode = true;
            } elseif ($documentNotices !== []) {
                $degradedMode = true;
                $notice = implode("\n", array_unique($documentNotices));
                $hasProof = ($structuredCheck['source_refs'] ?? []) !== [] || ($financialCheck['source_refs'] ?? []) !== []
                    || ($ragMetadata['used'] ?? false);
                $assistantContent = $documentUnavailable && !$hasProof ? $notice : $assistantContent."\n\n".$notice;
                if (is_array($structuredCheck)) {
                    $structuredCheck['text'] = $assistantContent;
                    $structuredCheck['validation_status'] = 'partial';
                }
            }

            if ($budgetStopNotice !== null) {
                $hasConfirmedAnswer = ($structuredCheck['source_refs'] ?? []) !== []
                    || ($financialCheck['source_refs'] ?? []) !== [];
                $assistantContent = $hasConfirmedAnswer ? $assistantContent."\n\n".$budgetStopNotice : $budgetStopNotice;
                if (! $hasConfirmedAnswer && is_array($structuredCheck)) {
                    $structuredCheck['needs_clarification'] = false;
                }
            }

            $assistantPayload = $this->taskOrchestrator->buildPayload($taskPlan, $assistantContent, [
                'degraded_mode' => $degradedMode,
                'tool_failures' => $toolFailures,
                'tool_evidence' => $toolEvidence,
                'proposed_actions' => $proposedActions,
                'missing_data' => $toolFailures,
                'executed_action' => $executedAction,
                'rag_context' => $ragMetadata,
            ]);

            $assistantPayload['source_refs'] = is_array($structuredCheck) && ($structuredCheck['replaced'] || $structuredCheck['source_refs'] !== [])
                ? $structuredCheck['source_refs']
                : ($terminalToolResponse ? [] : $this->collectSourceRefs($ragMetadata, $this->activeToolResults));
            $validationStatus = $structuredCheck['validation_status'] ?? $financialCheck['validation_status'] ?? 'unverified';
            $assistantPayload['validation_status'] = $validationStatus === 'unverified' && $assistantPayload['source_refs'] !== []
                ? 'partial' : $validationStatus;
            if (($stockDomainResolved || (($structuredCheck['replaced'] ?? false)
                && in_array($validationStatus, ['verified', 'partial'], true) && $assistantPayload['source_refs'] !== [])) && empty($taskPlan['capability'])
                && is_array($assistantPayload['missing_data'] ?? null)) {
                $assistantPayload['missing_data'] = array_values(array_filter($assistantPayload['missing_data'],
                    static fn (mixed $reason): bool => $reason !== self::UNRESOLVED_DOMAIN_MESSAGE));
            }
            $assistantPayload['needs_clarification'] = (bool) ($assistantPayload['needs_clarification'] ?? false)
                || (bool) ($structuredCheck['needs_clarification'] ?? false)
                || ($terminalToolResponse && $assistantPayload['source_refs'] === []);
            if (($structuredCheck['structured_evidence_truncated'] ?? false) === true) {
                $assistantPayload['structured_evidence_truncated'] = true;
            }
            foreach (['actual_model', 'provider_response_ref', 'api_method', 'model_invoked'] as $key) {
                $assistantPayload[$key] = $response[$key] ?? ($key === 'model_invoked' ? false : null);
            }
            $assistantPayload['request_id'] = $requestPayload['request_id'] ?? null;
            foreach (['next_actions', 'proposed_actions'] as $key) {
                $assistantPayload[$key] = array_map(static fn (array $action): array =>
                    isset($action['tool_name']) ? array_merge($action, ['origin_request_id' => $requestPayload['request_id'] ?? null]) : $action,
                    $assistantPayload[$key] ?? []);
            }
            $assistantPayload['actor_user_id'] = (int) $user->id;
            $assistantPayload['fetched_at'] = now()->toISOString();
            $assistantPayload = $this->decorateMetadata($assistantPayload, $user);
            $verificationTimer->finish();
            $this->rememberRagContext($conversation, $ragMetadata);
            $this->rememberResolvedEstimate($conversation, $user, $organizationId);

            $this->pendingSummary = [
                'conversation' => $conversation,
                'summary' => json_encode(['user_request' => $query, 'request_id' => $assistantPayload['request_id']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'selected_entities' => $this->selectedSummaryEntities($requestPayload, $organizationId),
                'source_refs' => $assistantPayload['source_refs'],
                'user_decisions' => [['request_id' => $assistantPayload['request_id'], 'user_request' => $query]],
            ];

            $tokensUsed = (int) array_sum(array_column($this->providerUsageAttempts, 'tokens'));
            $cost = (float) array_sum(array_column($this->providerUsageAttempts, 'cost'));
            $assistantMessage = $this->conversationManager->addMessage(
                $conversation,
                'assistant',
                $assistantContent,
                $tokensUsed,
                (string) ($response['model'] ?? $this->llmProvider->getModel()),
                $assistantPayload
            );

            $this->usageTracker->trackRequest(
                $organizationId,
                $user,
                $tokensUsed,
                $cost
            );

            $this->logging->business('ai.assistant.success', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tokens_used' => $tokensUsed,
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
                    'tokens_used' => $tokensUsed,
                    'metadata' => $assistantPayload,
                    'created_at' => $assistantMessage->created_at?->toISOString(),
                ],
                'tokens_used' => $tokensUsed,
                'usage' => $this->usageTracker->getUsageStats($organizationId),
            ];

            if ($executedAction) {
                $result['executed_action'] = $executedAction;
            }

            return $result;
        } catch (Throwable $exception) {
            $verificationTimer?->finish($exception);
            $this->logging->technical('ai.assistant.error', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
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
        $payload['needs_clarification'] = true;
        $payload['validation_status'] = 'unverified';
        $payload = $this->decorateMetadata($payload, $user);

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
        $this->stage('tools');
        $toolResult = $this->agentExecutor->execute(
            $toolName,
            $toolArguments,
            $user,
            $organization,
            $this->requestUnderstandingForAgentTool($taskPlan, $state, $toolName)
        );

        $artifacts = $this->filterAgentArtifactsForOrganization($organizationId, array_values(array_filter(
            $toolResult['artifacts'] ?? [],
            static fn (mixed $artifact): bool => is_array($artifact)
        )));

        $toolStatus = (string) ($toolResult['status'] ?? 'error');
        if ($toolStatus === 'error' || $artifacts === []) {
            $this->recordRequestOutcome('service_error');
        }
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
        $payload['source_refs'] = $this->collectSourceRefs([], [is_array($toolResult['raw'] ?? null) ? $toolResult['raw'] : []]);
        $payload['validation_status'] = $artifacts === [] ? 'unverified' : 'partial';
        $payload['task_status'] = $artifacts === [] ? 'failed' : 'completed';
        $payload = $this->decorateMetadata($payload, $user);

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

    protected function requestUnderstandingForAgentTool(
        array $taskPlan,
        AssistantTaskState $state,
        string $toolName
    ): AssistantRequestUnderstanding|array|null {
        $understanding = $this->requestUnderstandingFromPlan($taskPlan);

        if (! $this->canAgentExecuteTool($state, $toolName)) {
            return $understanding ?? ($taskPlan['request_understanding'] ?? null);
        }

        if ($understanding instanceof AssistantRequestUnderstanding && $understanding->blocksFileGeneration()) {
            return $understanding;
        }

        $requestedEntities = $understanding instanceof AssistantRequestUnderstanding
            ? $understanding->requestedEntities
            : [];

        return new AssistantRequestUnderstanding(
            primaryIntent: 'generate_report',
            outputFormat: 'pdf',
            actionPolicy: 'allow_file_generation',
            constraints: [],
            requestedEntities: $requestedEntities,
            confidence: max(0.7, $understanding?->confidence ?? 0.7),
            evidence: [
                [
                    'type' => 'agent_state',
                    'value' => $state->id,
                ],
                [
                    'type' => 'tool',
                    'value' => $toolName,
                ],
            ],
        );
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

    private function stage(string $stage): void
    {
        if ($this->activeRequest !== null && $this->activeActor !== null) {
            $this->requestLifecycle?->stage($this->activeRequest, $this->activeActor, $stage);
        }
    }

    private function executionCheckpoint(): void
    {
        if (app()->bound(AssistantRequestExecutionContext::class)) {
            app(AssistantRequestExecutionContext::class)->assertCanContinue();
        }
    }

    private function measurePhase(string $phase, callable $operation): mixed
    {
        return AssistantRequestPhaseTimer::run($this->runtimeTimingEnabled ? $this->activeRequest?->request_id : null, $phase, $operation);
    }

    private function requestErrorCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AssistantRequestDeadlineExceeded => 'request_deadline_exceeded',
            $exception instanceof AssistantRequestCancelled => 'request_cancelled',
            $exception instanceof AssistantBudgetExceeded => 'approved_budget_exceeded',
            $exception instanceof AuthorizationException => 'access_revoked',
            default => 'request_failed',
        };
    }

    protected function ragMetadataFromToolResults(string $query, array $toolResults): array
    {
        $sources = [];
        foreach ($toolResults as $result) {
            if (!is_array($result) || !in_array($result['status'] ?? null, ['success', 'partial'], true) || !is_array($result['rag_context'] ?? null)) {
                continue;
            }
            foreach ($result['rag_context']['sources'] ?? [] as $source) {
                if (is_array($source) && isset($source['entity_type'], $source['entity_id'])) {
                    $sources[hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))] = $source;
                }
            }
        }
        return ['enabled' => true, 'used' => $sources !== [], 'query' => $query, 'sources' => array_values($sources),
            'limits' => ['returned' => count($sources)]];
    }

    protected function toolResultForProvider(string $toolName, array|string $result): array|string
    {
        return is_array($result)
            ? AssistantToolResultProjection::forProvider($toolName, $result)
            : $result;
    }

    private function readPhase(callable $read, User $actor, int $organizationId): mixed
    {
        if (!app()->bound(AssistantRequestExecutionContext::class)) {
            return $read();
        }
        $execution = app(AssistantRequestExecutionContext::class);
        $requestId = $this->runtimeTimingEnabled ? $this->activeRequest?->request_id : null;
        $timingCallback = $requestId === null ? null : static function (
            string $phase,
            float $durationMs,
            bool $success,
            ?string $exceptionClass,
        ) use ($requestId): void {
            AssistantRequestPhaseTimer::recordDuration($requestId, $phase, $durationMs, $success, $exceptionClass);
        };

        return app(AssistantReadConcurrencyLimiter::class)->run(
            fn (callable $heartbeat): mixed => $execution->withOperationBudget(function () use ($heartbeat, $read, $actor, $organizationId): mixed {
                $heartbeat();
                $result = $this->dataAccess === null ? $read() : $this->dataAccess->withCurrentChecks(
                    $actor, $organizationId, $read, false, $heartbeat);
                $heartbeat();
                return $result;
            }, 30_000), fn () => $this->executionCheckpoint(), $organizationId, fn (): int => $execution->remainingMilliseconds(), $timingCallback);
    }

    protected function verifyMaterialStock(array $result, User $actor, int $organizationId): ?array
    {
        if (!in_array($result['status'] ?? null, ['success', 'empty'], true) || !is_array($result['stock_evidence'] ?? null)) {
            return null;
        }
        $read = fn (): ?array => $this->measurePhase('stock_read', fn (): ?array => app(AssistantMaterialStockReader::class)->verifiedAnswer($result, $actor, $organizationId));
        $this->executionCheckpoint();
        try {
            $verified = $this->readPhase($read, $actor, $organizationId);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '57014') {
                throw $exception;
            }
            $verified = null;
        }
        $this->executionCheckpoint();
        if ($verified === null) {
            $this->recordRequestOutcome('service_error');
            return ['text' => trans_message('ai_assistant.material_stock_unavailable'), 'replaced' => true,
                'validation_status' => 'unverified', 'needs_clarification' => false, 'source_refs' => []];
        }
        return $verified;
    }

    protected function verifyBimModel(array $result, User $actor, int $organizationId): array
    {
        $this->executionCheckpoint();
        try {
            $verified = $this->readPhase(fn (): ?array => app(AssistantBimModelReader::class)
                ->verifiedAnswer($result, $actor, $organizationId), $actor, $organizationId);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '57014') {
                throw $exception;
            }
            $verified = null;
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            $verified = null;
        }
        $this->executionCheckpoint();

        return $verified ?? ['text' => trans_message('ai_assistant_bim.unavailable'), 'replaced' => true,
            'validation_status' => 'unverified', 'needs_clarification' => true, 'source_refs' => []];
    }

    private function progress(?string $code, string $state): void
    {
        if ($code !== null && $this->activeRequest !== null && $this->activeActor !== null) {
            $this->requestLifecycle?->progress($this->activeRequest, $this->activeActor, $code, $state);
        }
    }

    private function isUsefulAnswer(array $result): bool
    {
        $metadata = $result['message']['metadata'] ?? [];
        return trim((string) ($result['message']['content'] ?? '')) !== ''
            && !($metadata['needs_clarification'] ?? false)
            && !($metadata['budget_exhausted'] ?? false)
            && !($metadata['access_denied'] ?? false)
            && !($metadata['service_error'] ?? false)
            && !in_array($metadata['outcome'] ?? null, ['access_denied', 'service_error', 'request_blocked', 'insufficient_data'], true)
            && ($metadata['task_status'] ?? null) !== 'failed';
    }

    private function decorateMetadata(array $payload, User $actor): array
    {
        if ($this->requestOutcome !== null) {
            $payload['outcome'] = $this->requestOutcome;
            $payload['access_denied'] = $this->requestOutcome === 'access_denied';
            $payload['service_error'] = $this->requestOutcome === 'service_error';
        }
        unset($payload['confidence']);
        $payload += ['actual_model' => null, 'provider_response_ref' => null, 'api_method' => null, 'model_invoked' => false];
        $payload['request_id'] = $this->activeRequest?->request_id ?? ($payload['request_id'] ?? null);
        $payload['actor_user_id'] = (int) $actor->id;
        $payload['request_state'] = $this->activeRequest !== null ? 'pending' : 'completed';
        $payload['source_refs'] ??= [];
        $payload['validation_status'] ??= 'unverified';
        $payload['fetched_at'] ??= now()->toISOString();
        return $payload;
    }

    private function recordRequestOutcome(string $outcome): void
    {
        if ($this->requestOutcome !== 'access_denied') {
            $this->requestOutcome = $outcome;
        }
    }

    private function recordUnavailableBusinessTools(array $taskPlan, array $tools): void
    {
        if ($this->businessDataDomain($taskPlan) === null) {
            return;
        }
        $understanding = $this->requestUnderstandingFromPlan($taskPlan);
        $category = match ($understanding?->primaryIntent) {
            'create', 'update', 'delete', 'approve', 'send' => 'mutation',
            'generate_report' => 'report',
            default => null,
        };
        if ($tools !== [] && $category === null) {
            return;
        }
        $allowActions = (bool) ($taskPlan['request']['allow_actions'] ?? false);
        foreach ($tools as $definition) {
            $name = $definition['name'] ?? null;
            if (is_string($name) && $understanding instanceof AssistantRequestUnderstanding) {
                $eligibility = $this->toolEligibilityPolicy->canExposeTool($name, $understanding, $allowActions);
                if ($eligibility->allowed && $eligibility->category === $category) {
                    return;
                }
            }
        }
        $this->recordRequestOutcome($category === 'mutation' && !$allowActions ? 'request_blocked' : 'service_error');
    }

    private function businessDataDomain(array $taskPlan): ?string
    {
        $understanding = $this->requestUnderstandingFromPlan($taskPlan);
        if (!$understanding instanceof AssistantRequestUnderstanding || $understanding->requestedEntities === []) {
            return null;
        }
        $message = (string) ($taskPlan['request']['message'] ?? '');
        if (preg_match('/^\s*(?:что\s+(?:(?:ты|вы|ассистент)\s+)?уме[её]|какие\s+(?:у\s+(?:тебя|вас)\s+)?возможности)/iu', $message)) {
            return null;
        }
        $domain = $taskPlan['capability']['domain'] ?? $taskPlan['capability']['id'] ?? null;
        return is_string($domain) && $domain !== ''
            ? match ($domain) { 'notifications' => 'projects', 'payments' => 'finance', 'schedules' => 'schedule', default => $domain }
            : null;
    }

    private function savePendingSummary(User $actor): void
    {
        $pending = $this->pendingSummary;
        if ($pending === null || !($pending['conversation'] ?? null) instanceof Conversation) {
            return;
        }
        $this->measurePhase('summary_publish', function () use ($pending, $actor): void {
            $conversation = $pending['conversation'];
            $conversation->refresh();
            $this->conversationManager->saveSummary($conversation, $actor, [
                'validation_status' => 'verified', 'summary' => $pending['summary'],
                'selected_entities' => $pending['selected_entities'], 'user_decisions' => $pending['user_decisions'],
            ], $pending['source_refs'], (int) $conversation->context_version);
        });
    }

    private function selectedSummaryEntities(array $filteredRequest, int $organizationId): array
    {
        $context = is_array($filteredRequest['context'] ?? null) ? $filteredRequest['context'] : [];
        $references = array_merge(is_array($context['entity_refs'] ?? null) ? $context['entity_refs'] : [],
            is_array($context['entity_references'] ?? null) ? $context['entity_references'] : []);
        $estimateId = $this->estimateResolutionAttempted ? $this->resolvedEstimateId
            : ($this->activeEstimateSelection['estimate_id'] ?? $context['selected_estimate_id'] ?? null);
        if (is_int($estimateId) && $estimateId > 0) {
            $references[] = ['entity_type' => 'estimate', 'entity_id' => $estimateId];
        }
        $selected = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $type = $reference['entity_type'] ?? $reference['type'] ?? null;
            $id = $reference['entity_id'] ?? $reference['id'] ?? null;
            if (!is_string($type) || $type === '' || (!is_int($id) && !is_string($id)) || (string) $id === '') {
                continue;
            }
            $selected[$type.':'.$id] ??= ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => $organizationId];
        }

        return array_values($selected);
    }

    private function filterRequestEntityContext(array $request, User $actor, int $organizationId): array
    {
        $context = is_array($request['context'] ?? null) ? $request['context'] : [];
        foreach (['entity_refs', 'entity_references'] as $key) {
            if (!is_array($context[$key] ?? null)) {
                continue;
            }
            $context[$key] = array_values(array_filter($context[$key], function ($ref) use ($actor, $organizationId): bool {
                if (!is_array($ref)) {
                    return false;
                }
                $type = $ref['entity_type'] ?? $ref['type'] ?? null;
                $id = $ref['entity_id'] ?? $ref['id'] ?? null;
                return is_string($type) && (is_int($id) || is_string($id)) && ($this->dataAccess?->canReadEntity($actor, $organizationId, $type, $id) ?? false);
            }));
        }
        if (isset($context['selected_estimate_id']) && !($this->dataAccess?->canReadEntity($actor, $organizationId, 'estimate', $context['selected_estimate_id']) ?? false)) {
            unset($context['selected_estimate_id']);
        }
        $request['context'] = $context;
        return $request;
    }

    private function answerFinancialRequest(string $query, int $organizationId, User $user, Conversation $conversation, array $taskPlan): ?array
    {
        $selection = is_array($conversation->context['selected_estimate'] ?? null) ? $conversation->context['selected_estimate'] : null;
        $pinnedId = isset($selection['estimate_id']) ? (int) $selection['estimate_id'] : null;
        if ($pinnedId === null) {
            foreach ($taskPlan['request']['context']['entity_refs'] ?? [] as $ref) {
                if (is_array($ref) && ($ref['type'] ?? null) === 'estimate' && is_numeric($ref['id'] ?? null)) {
                    $pinnedId = (int) $ref['id'];
                    break;
                }
            }
        }
        if ($this->financialAnswers === null || !$this->financialAnswers->supports($query, $pinnedId)) {
            return null;
        }
        $this->stage('tools');
        $answer = $this->financialAnswers->answer($query, $organizationId, $user, $pinnedId, $selection);
        if (($answer['resolution']['status'] ?? null) === 'resolved' && is_array($answer['financial_evidence'] ?? null)
            && ($answer['source_refs'] ?? []) !== []) {
            $this->progress('estimates', 'completed');
        }
        if (($answer['resolution']['status'] ?? null) === 'forbidden') {
            $this->recordRequestOutcome('access_denied');
        }
        $content = (string) $answer['text'];
        $payload = $this->taskOrchestrator->buildPayload($taskPlan, $content, []);
        unset($payload['confidence']);
        $payload['validation_status'] = $answer['validation_status'];
        $payload['source_refs'] = $answer['source_refs'] ?? [];
        $payload['fetched_at'] = $answer['fetched_at'] ?? now()->toISOString();
        $payload['provenance'] = $this->financialReceipt($answer);
        $payload['needs_clarification'] = (bool) ($answer['needs_clarification'] ?? false);
        $payload['request_id'] = $this->activeRequest?->request_id;
        $payload['actor_user_id'] = (int) $user->id;
        $payload['entity_references'] = $answer['source_refs'] ?? [];
        $payload['rag_context'] = ['used' => false, 'sources' => []];
        $payload = $this->decorateMetadata($payload, $user);
        $contextChanges = $this->buildLastRequestContext($query, $taskPlan);
        $contextRemovals = [];
        if (is_array($answer['selection'] ?? null)) {
            $contextChanges['selected_estimate'] = $answer['selection'];
        } elseif (($answer['pinned_estimate_id'] ?? null) === null) {
            $contextRemovals[] = 'selected_estimate';
        }
        $this->conversationManager->updateContext($conversation, $user, $organizationId, $contextChanges, $contextRemovals);
        $message = $this->conversationManager->addMessage($conversation, 'assistant', $content, 0, $this->llmProvider->getModel(), $payload);
        if ($answer['validation_status'] === 'verified') {
            $summaryRequest = $taskPlan['request'] ?? [];
            $selectedId = $answer['selection']['estimate_id'] ?? $answer['pinned_estimate_id'] ?? null;
            if (is_int($selectedId) && $selectedId > 0) {
                $summaryRequest['context']['selected_estimate_id'] = $selectedId;
            }
            $this->pendingSummary = [
                'conversation' => $conversation,
                'summary' => $content,
                'selected_entities' => $this->selectedSummaryEntities($summaryRequest, $organizationId),
                'source_refs' => $answer['source_refs'] ?? [],
                'user_decisions' => [['request_id' => $this->activeRequest?->request_id, 'query' => $query, 'created_at' => now()->toISOString()]],
            ];
        }
        return $this->agentAskResult($conversation, $message, $content, $payload, $organizationId, $user);
    }

    private function financialReceipt(array $answer): ?array
    {
        $evidence = $answer['financial_evidence'] ?? null;
        if (!is_array($evidence)) {
            return null;
        }
        $receipt = array_intersect_key($evidence, array_flip(['estimate', 'totals', 'stored_totals', 'position_count', 'fetched_at', 'version',
            'validation_status', 'totals_validation_status', 'missing_total_fields', 'aggregation', 'selection']));
        $references = is_array($answer['source_refs'] ?? null) ? $answer['source_refs'] : [];
        $shownIds = [];
        foreach ($references as $reference) {
            if (is_array($reference) && ($reference['entity_type'] ?? null) === 'estimate_item') {
                $shownIds[(string) $reference['entity_id']] = true;
            }
        }
        $receipt['positions'] = array_slice(array_values(array_filter($evidence['positions'] ?? [],
            static fn (array $position): bool => isset($shownIds[(string) $position['id']]))), 0, 50);
        $receipt['source_refs'] = $references;

        return $receipt;
    }

    private function rememberResolvedEstimate(Conversation $conversation, User $actor, int $organizationId): void
    {
        if (! $this->estimateResolutionAttempted) {
            return;
        }
        if ($this->resolvedEstimateId === null || ! ($this->dataAccess?->canReadEntity($actor, $organizationId, 'estimate', $this->resolvedEstimateId) ?? false)) {
            $this->resolvedEstimateId = null;
            $changes = [];
            $remove = ['selected_estimate', 'selected_estimate_id'];
        } else {
            $changes = [
                'selected_estimate' => ['estimate_id' => $this->resolvedEstimateId],
                'selected_estimate_id' => $this->resolvedEstimateId,
            ];
            foreach ($this->activeToolResults as $result) {
                if (($result['_tool_name'] ?? null) === 'get_estimate_answer'
                    && ($result['selection']['estimate_id'] ?? null) === $this->resolvedEstimateId) {
                    $changes['selected_estimate'] = $result['selection'];
                }
            }
            $remove = [];
        }
        $this->conversationManager->updateContext($conversation, $actor, $organizationId, $changes, $remove, preserveSelectionDetails: true);
    }

    private function collectSourceRefs(array $ragMetadata, array $toolResults): array
    {
        $refs = [];
        foreach ($ragMetadata['sources'] ?? [] as $source) {
            if (is_array($source) && isset($source['entity_type'], $source['entity_id'])) {
                $refs[] = array_intersect_key($source, array_flip(['source_id', 'source_type', 'entity_type', 'entity_id', 'organization_id', 'project_id', 'content_scope', 'version', 'checksum', 'source_version', 'updated_at', 'fetched_at', 'navigation', 'title', 'checked_fields', 'required_permissions', 'required_domains', 'projection_name', 'composite_key', 'projection_source_version', 'assistant_public_schema_revision']));
            }
        }
        foreach ($toolResults as $result) {
            if (!is_array($result)) {
                continue;
            }
            foreach ([$result['source_refs'] ?? [], $result['metadata']['source_refs'] ?? [], $result['financial_evidence']['source_refs'] ?? []] as $references) {
                if (!is_array($references)) {
                    continue;
                }
                foreach ($references as $ref) {
                    if (is_array($ref) && isset($ref['entity_type'], $ref['entity_id'])) {
                        $refs[] = $ref;
                    }
                }
            }
        }
        $result = [];
        foreach ($refs as $ref) {
            $ref['fetched_at'] ??= now()->toISOString();
            $result[AssistantSourceReferenceIdentity::key($ref)] = $ref;
        }
        return array_values($result);
    }

    private function isTerminalReadOnlyTool(string $toolName): bool
    {
        return in_array($toolName, [
            'assistant_domain_discover_capabilities', 'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
            'resolve_estimate', 'get_estimate_positions', 'search_estimate_positions', 'get_estimate_financial_snapshot',
            'get_project_snapshot', 'get_procurement_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
            'search_projects', 'search_contractors', 'search_materials', 'search_users', 'search_warehouse',
            'get_published_report_financial_evidence', 'get_live_project_financial_evidence',
            'search_assistant_documents', 'get_estimate_answer', 'get_material_stock', 'get_bim_model_elements',
        ], true);
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
        $this->executionCheckpoint();
        $toolName = (string) ($toolCall['name'] ?? '');
        if ($taskPlan['image_discussion'] ?? false) {
            return ['status' => 'blocked_by_request_policy', 'error' => trans_message('ai_assistant.image_discussion_tools_unneeded'), 'tool_name' => $toolName];
        }
        $arguments = json_decode((string) ($toolCall['arguments'] ?? '{}'), true);
        $args = is_array($arguments) ? $arguments : [];
        if ($toolName === 'get_bim_model_elements' && ! array_key_exists('project_id', $args)) {
            $args['project_id'] = $this->resolveRagProjectId($taskPlan['request']['context'] ?? []);
        }
        if ($toolName === 'assistant_domain_search' && ! array_key_exists('project_id', $args)) {
            $definition = app(AssistantDomainCatalog::class)->definition((string) ($args['domain'] ?? ''));
            if ($definition !== null && in_array('project_id', $definition->fields, true)) {
                $args['project_id'] = $this->resolveRagProjectId($taskPlan['request']['context'] ?? []);
            }
        }
        $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);
        $tool = $this->toolRegistry->getTool($toolName);

        if (! $tool) {
            $this->recordRequestOutcome('service_error');
            $message = trans_message('ai_assistant.tool_unavailable');
            $toolFailures[] = $message;

            return ['error' => $message];
        }

        try {
            $this->toolArguments->validate($args, $tool->getParametersSchema());
            $isMutationTool = $this->permissionChecker->isMutationTool($toolName);
            if ($isMutationTool && (! $allowActions || ! $requestUnderstanding instanceof AssistantRequestUnderstanding)) {
                $this->recordRequestOutcome('request_blocked');
                $message = $this->toolBlockedMessage(null);
                $toolFailures[] = $message;

                return ['status' => 'blocked_by_request_policy', 'error' => $message, 'tool_name' => $toolName];
            }
            if ($requestUnderstanding instanceof AssistantRequestUnderstanding) {
                $eligibility = $isMutationTool
                    ? $this->toolEligibilityPolicy->canExposeTool($toolName, $requestUnderstanding, $allowActions)
                    : $this->toolEligibilityPolicy->canExecuteTool($toolName, $requestUnderstanding, $allowActions, $args);
                if (! $eligibility->allowed) {
                    $this->recordRequestOutcome('request_blocked');
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
                    $this->recordRequestOutcome('access_denied');
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
                $this->recordRequestOutcome('access_denied');
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

            $progressCode = AssistantRequestProgress::toolCode($toolName, $args);
            $this->progress($progressCode, 'started');
            $pureDatabaseRead = in_array($toolName, [
                'assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation',
                'resolve_estimate', 'get_estimate_answer', 'get_estimate_positions', 'search_estimate_positions', 'get_estimate_financial_snapshot',
                'get_project_snapshot', 'get_procurement_snapshot', 'get_contract_snapshot', 'get_schedule_snapshot',
                'search_projects', 'search_contractors', 'search_materials', 'search_users', 'search_warehouse', 'get_material_stock', 'get_bim_model_elements',
                'get_published_report_financial_evidence', 'get_live_project_financial_evidence',
            ], true);
            $read = fn (): array|string => $tool instanceof \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimateAnswerTool
                ? $tool->executeForSelection($args, $user, $organization, $this->activeEstimateSelection)
                : $tool->execute($args, $user, $organization);
            if ($toolName === 'get_material_stock') {
                $stockRead = $read;
                $read = fn (): array|string => $this->measurePhase('stock_read', $stockRead);
            }
            $toolResult = $this->measurePhase('tool', fn (): array|string => $pureDatabaseRead && app()->bound(AssistantRequestExecutionContext::class)
                ? $this->readPhase($read, $user, $organizationId) : $read());
            $this->executionCheckpoint();
            if (is_array($toolResult) && in_array($toolResult['status'] ?? null, ['access_denied', 'forbidden'], true)) {
                $this->recordRequestOutcome('access_denied');
            } elseif (is_array($toolResult) && in_array($toolName, ['get_published_report_financial_evidence', 'get_live_project_financial_evidence'], true)
                && (in_array($toolResult['status'] ?? null, ['insufficient_data', 'incomplete', 'partial'], true) || ($toolResult['useful'] ?? null) === false)) {
                $this->recordRequestOutcome('insufficient_data');
            } elseif (is_array($toolResult) && (in_array($toolResult['status'] ?? null, ['error', 'failed', 'unavailable', 'blocked_by_request_policy'], true)
                || !empty($toolResult['error']))) {
                $this->recordRequestOutcome('service_error');
            }
            if (is_array($toolResult) && $this->legacyLiveEvidence !== null) {
                $liveReads = $this->legacyLiveEvidence->read($toolName, $toolResult, $user, $organizationId);
                foreach ($liveReads as $liveRead) {
                    $this->activeToolResults[] = $liveRead;
                }
                if ($liveReads !== []) {
                    $toolResult['live_entity_reads'] = $liveReads;
                }
            }
            if (AssistantRequestProgress::toolCompleted($toolResult)
                || ($toolName === 'search_assistant_documents' && is_array($toolResult)
                    && ($toolResult['status'] ?? null) === 'partial' && ($toolResult['source_refs'] ?? []) !== [])) {
                $this->progress($progressCode, 'completed');
            }
            if ($toolName === 'resolve_estimate') {
                $this->estimateResolutionAttempted = true;
                $this->resolvedEstimateId = is_array($toolResult) && ($toolResult['status'] ?? null) === 'resolved'
                    && is_int($toolResult['estimate_id'] ?? null) && count($toolResult['options'] ?? []) === 1
                    && ($toolResult['options'][0]['id'] ?? null) === $toolResult['estimate_id']
                    ? $toolResult['estimate_id'] : null;
            }
            if ($toolName === 'get_estimate_answer') {
                $this->estimateResolutionAttempted = true;
                $this->resolvedEstimateId = is_array($toolResult) && ($toolResult['status'] ?? null) === 'resolved'
                    && is_int($toolResult['selection']['estimate_id'] ?? null)
                    ? $toolResult['selection']['estimate_id'] : null;
            }
            $this->activeToolResults[] = is_array($toolResult) ? $toolResult + ['_tool_name' => $toolName] : [];
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
            if ($exception instanceof AssistantRequestCancelled || $exception instanceof AssistantRequestDeadlineExceeded
                || $exception instanceof AssistantReadPermitTimeoutException) {
                throw $exception;
            }
            $this->executionCheckpoint();
            $this->recordRequestOutcome($exception instanceof AuthorizationException
                || $exception instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException ? 'access_denied' : 'service_error');
            $message = trans_message('ai_assistant.tool_execute_failed');
            $toolFailures[] = $message;

            $technicalContext = [
                'tool' => $toolName,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ];
            if ($exception instanceof QueryException) {
                $technicalContext += $this->queryExceptionDiagnosticMetadata($exception);
            }
            $this->logging->technical('ai.tool.error', $technicalContext, 'error');
            if ($exception instanceof QueryException && (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '57014') {
                return ['status' => 'unavailable', 'reason' => 'read_timed_out', 'error' => trans_message('ai_assistant.data_read_timed_out')];
            }
            return ['error' => $message];
        }
    }

    private function queryExceptionDiagnosticMetadata(QueryException $exception): array
    {
        $metadata = ['sql_fingerprint' => hash('sha256', $exception->getSql())];
        $sqlState = null;
        foreach ([$exception->errorInfo[0] ?? null, $exception->getCode()] as $candidate) {
            if (!is_string($candidate) && !is_int($candidate)) {
                continue;
            }

            $candidate = (string) $candidate;
            if (preg_match('/\\A[A-Z0-9]{5}\\z/', $candidate) === 1) {
                $sqlState = $candidate;
                break;
            }
        }

        if ($sqlState === null) {
            return $metadata;
        }

        $metadata['sqlstate'] = $sqlState;
        $objectType = match ($sqlState) {
            '42703' => 'column',
            '42P01' => 'relation',
            default => null,
        };
        $driverMessage = $exception->errorInfo[2] ?? null;
        if ($objectType === null || !is_string($driverMessage) || strlen($driverMessage) > 2048) {
            return $metadata;
        }

        $identifierPart = '[A-Za-z_][A-Za-z0-9_]{0,62}';
        $pattern = '/\\AERROR:[ \\t]+'
            . preg_quote($objectType, '/')
            . '[ \\t]+"('
            . $identifierPart
            . '(?:\\.'
            . $identifierPart
            . ')*)"[ \\t]+does not exist'
            . '(?: at character [0-9]+)?'
            . '(?:\\r?\\nLINE [0-9]+:[^\\r\\n]*\\r?\\n[ \\t]*\\^[ \\t]*)?\\z/';
        if (preg_match($pattern, $driverMessage, $matches) === 1) {
            $metadata['database_identifier'] = $matches[1];
        }

        return $metadata;
    }

    protected function requestAssistantResponse(array $messages, array $options, int $organizationId, User $user): array
    {
        $this->executionCheckpoint();
        [$preparedMessages, $preparedOptions, $budgetDegraded] = $this->measurePhase('provider_prepare', fn (): array => $this->prepareProviderPayload($messages, $options, $organizationId, $user));
        if (app()->bound(AssistantRequestExecutionContext::class)) {
            $provider = (string) config('ai-assistant.llm.provider', 'timeweb');
            $profileTimeout = $provider === 'timeweb' ? config('ai-assistant.llm.timeweb.profiles.assistant.timeout') : null;
            $configuredTimeout = $preparedOptions['timeout'] ?? $profileTimeout ?? config('ai-assistant.llm.'.$provider.'.timeout', 45);
            $preparedOptions['timeout'] = app(AssistantRequestExecutionContext::class)->remainingSeconds(max(1, (int) $configuredTimeout));
        }

        if (! is_callable([$this->llmProvider, 'responses'])) {
            throw new RuntimeException('assistant_native_responses_unavailable');
        }

        $attempt = $this->activeRequest !== null
            ? $this->requestLifecycle?->beforeProviderCall($this->activeRequest, $user, (int) $preparedOptions['estimated_input_tokens'], (int) $preparedOptions['max_completion_tokens'])
            : 1;
        $response = null;
        $providerStarted = false;
        try {
            $this->executionCheckpoint();
            $providerStarted = true;
            $providerCallStarted = hrtime(true);
            $providerCallFailure = null;
            try {
                $response = $this->llmProvider->responses($preparedMessages, $preparedOptions);
            } catch (Throwable $exception) {
                $providerCallFailure = $exception;
                throw $exception;
            } finally {
                try {
                    $providerCallDuration = round((hrtime(true) - $providerCallStarted) / 1_000_000, 2);
                    $provider = (string) config('ai-assistant.llm.provider', 'timeweb');
                    $model = $this->llmProvider->getModel();
                    $logger = Log::getFacadeRoot();
                    if (! $logger instanceof LogManager || is_string(config('logging.default'))) {
                        Log::info('ai.assistant.provider_call_completed', [
                            'request_id' => $this->activeRequest?->request_id,
                            'call_attempt' => (int) $attempt,
                            'provider' => in_array($provider, ['timeweb', 'openai'], true) ? $provider : 'unknown',
                            'model' => LunaModelPolicy::isLuna($model, $provider) ? $model : 'unknown',
                            'duration_ms' => $providerCallDuration,
                            'success' => $providerCallFailure === null,
                            'exception_class' => $providerCallFailure === null ? null : $providerCallFailure::class,
                        ]);
                    }
                } catch (Throwable) {
                }
            }
            $this->executionCheckpoint();
            $priorIds = array_filter(array_column($messages, 'id'));
            $priorCalls = array_filter(array_column($messages, 'call_id'));
            foreach ($response['output'] ?? [] as $item) {
                if (in_array($item['id'] ?? null, $priorIds, true)
                    || (($item['type'] ?? null) === 'function_call' && in_array($item['call_id'] ?? null, $priorCalls, true))) {
                    throw new RuntimeException('assistant_native_replayed_output');
                }
            }
            if (($response['response_status'] ?? null) === 'incomplete' || ($response['finish_reason'] ?? null) === 'length') {
                throw new AssistantResponseIncomplete($response);
            }
            $this->recordProviderUsageDuringCleanup($response, $organizationId, $user, $preparedOptions, $budgetDegraded, true, (int) $attempt);

            return [
                'response' => $response,
                'degraded_mode' => $budgetDegraded,
                'fallback_reason' => null,
            ];
        } catch (Throwable $exception) {
            if ($providerStarted) {
                $failedUsage = $this->providerUsageFromFailure($exception, $response);
                try {
                    $this->recordProviderUsageDuringCleanup($failedUsage, $organizationId, $user, $preparedOptions, true, false, (int) $attempt);
                } catch (Throwable $cleanupFailure) {
                    $this->logging->technical('ai.assistant.provider_usage_cleanup_failed', [
                        'organization_id' => $organizationId, 'exception_class' => $cleanupFailure::class,
                    ], 'error');
                }
            }
            $this->executionCheckpoint();
            throw $exception;
        }
    }

    private function recordProviderUsageDuringCleanup(array $response, int $organizationId, User $user, array $options,
        bool $degradedMode, bool $successful, int $attempt): void
    {
        $record = function () use ($response, $organizationId, $user, $options, $degradedMode, $successful, $attempt): void {
            if ($this->activeRequest !== null) {
                $this->requestLifecycle?->recordProviderUsage($this->activeRequest, $response, $attempt, $successful);
            }
            $this->recordAssistantProviderUsage($response, $organizationId, $user, $options, $degradedMode, $successful, $attempt);
        };
        if (app()->bound(AssistantRequestExecutionContext::class)) {
            app(AssistantRequestExecutionContext::class)->withCleanupBudget($record, 5000);
        } else {
            $record();
        }
    }

    protected function recordAssistantProviderUsage(
        array $response,
        int $organizationId,
        User $user,
        array $options,
        bool $degradedMode,
        bool $successful = true,
        ?int $attempt = null
    ): void {
        try {
            $inputTokens = max(0, (int) ($response['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($response['output_tokens'] ?? 0));
            $totalTokens = max(0, (int) ($response['tokens_used'] ?? ($inputTokens + $outputTokens)));
            $usageAvailable = ($response['provider_usage_available'] ?? true) !== false && isset($response['input_tokens'], $response['output_tokens']);

            $key = $this->activeRequest !== null && $attempt !== null ? $attempt : count($this->providerUsageAttempts);
            $this->providerUsageAttempts[$key] = [
                'tokens' => $usageAvailable ? $totalTokens : 0,
                'cost' => $usageAvailable ? $this->usageTracker->calculateCost($totalTokens,
                    (string) ($response['model'] ?? $this->llmProvider->getModel()), $inputTokens, $outputTokens,
                    (string) ($response['provider'] ?? config('ai-assistant.llm.provider', 'unknown'))) : 0.0,
            ];

            $this->usageTracker->recordUsage(
                $organizationId,
                $user->id,
                (string) ($response['provider'] ?? config('ai-assistant.llm.provider', 'unknown')),
                (string) ($response['model'] ?? $this->llmProvider->getModel()),
                'assistant_chat',
                $inputTokens,
                $outputTokens,
                $totalTokens,
                [
                    'request_id' => $this->activeRequest?->request_id,
                    'attempt' => $attempt,
                    'credit_usage_key' => $this->activeRequest !== null && $attempt !== null ? $this->activeRequest->request_id.':call:'.$attempt : null,
                    'usage_key' => $this->activeRequest !== null && $attempt !== null ? $this->activeRequest->request_id.':call:'.$attempt : null,
                    'is_successful' => $successful,
                    'provider_usage_available' => $usageAvailable,
                    'cost_available' => $usageAvailable,
                    'usage_source' => $usageAvailable ? ($response['usage_source'] ?? 'provider_response') : 'unavailable',
                    'profile' => $response['profile'] ?? ($options['profile'] ?? null),
                    'route_attempt' => $response['route_attempt'] ?? null,
                    'route_fallback' => (bool) ($response['route_fallback'] ?? false),
                    'has_tools' => ! empty($options['tools']),
                    'degraded_mode' => $degradedMode,
                ]
            );
        } catch (Throwable $throwable) {
            $this->logging->technical('ai.assistant.usage_record_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $throwable::class,
            ], 'warning');
        }
    }

    private function providerUsageFromFailure(Throwable $exception, ?array $response): array
    {
        if ($exception instanceof AssistantResponseIncomplete) {
            return $exception->providerUsage;
        }
        if ($response !== null) {
            return $response;
        }
        try {
            $httpResponse = $exception->response ?? null;
            if (!$httpResponse instanceof \Psr\Http\Message\ResponseInterface) {
                return [];
            }
            $stream = $httpResponse->getBody();
            if (!$stream->isSeekable()) {
                return [];
            }
            $position = $stream->tell();
            try {
                $stream->rewind();
                $body = json_decode($stream->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } finally {
                $stream->seek($position);
            }
            $usage = is_array($body) ? ($body['usage'] ?? null) : null;
            if (!is_array($usage)) {
                return [];
            }
            $input = filter_var($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null, FILTER_VALIDATE_INT);
            $output = filter_var($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null, FILTER_VALIDATE_INT);
            if ($input === false || $output === false || $input < 0 || $output < 0) {
                return [];
            }
            $result = ['input_tokens' => $input, 'output_tokens' => $output, 'tokens_used' => $input + $output];
            if (is_string($body['model'] ?? null)) {
                $result['model'] = $body['model'];
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        if ($conversationId) {
            $conversation = $this->conversationManager->findAccessibleConversation($conversationId, $user, $organizationId, true);

            if ($conversation instanceof Conversation) {
                return $conversation;
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
            'last_image_discussion' => (bool) ($taskPlan['image_discussion'] ?? false),
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
            'надо по деньгам',
            'по деньгам',
            'точнее',
            'давай конкретнее а не около',
            'какие это позиции по смете',
            'какие позиции',
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
        if ($this->isStandaloneGreeting($query, $requestPayload)) {
            return $this->ragPromptContextBuilder->build($query, []);
        }

        try {
            $ragSearchQuery = $this->resolveRagSearchQuery($query, $requestPayload);
            if ($this->ragRetriever instanceof RagRetriever) {
                $this->progress('rag_search', 'started');
            }
            $results = $this->ragRetriever instanceof RagRetriever
                ? $this->ragRetriever->search(
                    $ragSearchQuery,
                    $organizationId,
                    $user,
                    $this->buildRagRequestContext($taskPlan, $requestPayload)
                )
                : [];
            if ($this->ragRetriever instanceof RagRetriever) {
                $this->progress('rag_search', 'completed');
            }

            $context = $this->ragPromptContextBuilder->build($query, $results);
            if ($ragSearchQuery !== $query && is_array($context['metadata'] ?? null)) {
                $context['metadata']['search_query'] = $ragSearchQuery;
            }

            return $context;
        } catch (Throwable $exception) {
            if ($exception instanceof AssistantRequestCancelled || $exception instanceof AuthorizationException) {
                throw $exception;
            }
            $this->logging->technical('ai.rag.context_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ], 'warning');

            return $this->ragPromptContextBuilder->build($query, []);
        }
    }

    private function isStandaloneGreeting(string $query, array $requestPayload): bool
    {
        if (preg_match('/^\s*(?:привет|здравствуй(?:те)?|добрый\s+(?:день|вечер|утро))\s*[!.?]?\s*$/iu', $query) !== 1
            || ! empty($requestPayload['conversation_id']) || ! empty($requestPayload['goal'])
            || ! empty($requestPayload['desired_mode']) || ! empty($requestPayload['allow_actions'])) {
            return false;
        }

        if (!empty($requestPayload['attachment_ids'])) { return false; }

        $context = $requestPayload['context'] ?? [];
        if (! is_array($context) || ! $this->hasOnlyImplicitProjectReference($context['entity_refs'] ?? [])
            || ! empty($context['period'])
            || ! empty($context['filters'])) {
            return false;
        }

        $uiState = $context['ui_state'] ?? [];

        return is_array($uiState) && array_diff(array_keys($uiState), ['assistant_path', 'pathname']) === [];
    }

    private function answerSectionNavigation(string $query, int $organizationId, User $user, Conversation $conversation, array $requestPayload): array
    {
        $this->stage('verifying');
        $this->executionCheckpoint();
        $access = $this->accessContextResolver->resolve($user, $organizationId);
        if (($access['can_use_assistant'] ?? false) !== true) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
        if ($this->dataAccess !== null && !empty($requestPayload['context']['entity_refs'])) {
            $requestPayload = $this->dataAccess->withCurrentChecks($user, $organizationId,
                fn (): array => $this->filterRequestEntityContext($requestPayload, $user, $organizationId), true);
        }
        $plan = $this->taskOrchestrator->plan($query, $requestPayload, $access);
        $domain = $plan['capability']['domain'] ?? null;
        $allowed = ($plan['section_navigation'] ?? false) === true && is_array($plan['navigation_target'] ?? null);
        if ($allowed && $this->dataAccess !== null) {
            $allowed = is_string($domain) && $this->dataAccess->withCurrentChecks($user, $organizationId,
                fn (): bool => $this->dataAccess->canReadDomain($user, $organizationId, $domain), true);
        }
        $this->executionCheckpoint();
        if (!$allowed) {
            $plan['navigation_target'] = null;
            $plan['next_actions'] = [];
            $this->recordRequestOutcome('access_denied');
        } else {
            $plan['access_limits'] = array_values(array_filter($plan['access_limits'] ?? [],
                static fn (array $limit): bool => ($limit['code'] ?? null) !== 'actions_locked'));
        }
        $content = trans_message($allowed ? 'ai_assistant.section_navigation_response' : 'ai_assistant.section_navigation_denied',
            ['section' => $plan['capability']['label'] ?? '']);
        $metadata = $this->decorateMetadata(array_replace($this->taskOrchestrator->buildPayload($plan, $content), [
            'validation_status' => $allowed ? 'verified' : 'unverified',
            'needs_clarification' => false, 'source_refs' => [],
            'response_kind' => 'navigation', 'request_id' => $requestPayload['request_id'] ?? null,
            'actor_user_id' => (int) $user->id,
        ]), $user);
        $message = $this->conversationManager->addMessage($conversation, 'assistant', $content, 0, 'system', $metadata);
        $this->usageTracker->trackRequest($organizationId, $user, 0, 0.0);
        return ['conversation_id' => $conversation->id, 'message' => [
            'id' => $message->id, 'role' => 'assistant', 'content' => $content, 'tokens_used' => 0,
            'metadata' => $metadata, 'created_at' => $message->created_at?->toISOString(),
        ], 'tokens_used' => 0, 'usage' => $this->usageTracker->getUsageStats($organizationId)];
    }

    private function answerStandaloneGreeting(string $query, int $organizationId, User $user, Conversation $conversation, array $requestPayload): array
    {
        $this->conversationManager->addMessage($conversation, 'user', $query, 0, 'system', [
            'actor_user_id' => (int) $user->id,
            'request_id' => $requestPayload['request_id'] ?? null,
            'response_kind' => 'greeting',
        ]);
        $this->stage('verifying');
        $content = trans_message('ai_assistant.greeting_response');
        $metadata = $this->decorateMetadata([
            'response_kind' => 'greeting',
            'task_type' => 'greeting',
            'validation_status' => 'verified',
            'source_refs' => [],
            'missing_data' => [],
            'access_limits' => [],
            'needs_clarification' => false,
        ], $user);
        $message = $this->conversationManager->addMessage($conversation, 'assistant', $content, 0, 'system', $metadata);
        $this->usageTracker->trackRequest($organizationId, $user, 0, 0.0);

        return [
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $message->id,
                'role' => 'assistant',
                'content' => $content,
                'tokens_used' => 0,
                'metadata' => $metadata,
                'created_at' => $message->created_at?->toISOString(),
            ],
            'tokens_used' => 0,
            'usage' => $this->usageTracker->getUsageStats($organizationId),
        ];
    }

    private function hasOnlyImplicitProjectReference(mixed $references): bool
    {
        if (! is_array($references)) {
            return false;
        }

        if ($references === []) {
            return true;
        }

        return array_is_list($references) && count($references) === 1
            && is_array($references[0]) && ($references[0]['type'] ?? null) === 'project';
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

    private function buildMessagesWithCapabilityHints(Conversation $conversation, array $context, array $taskPlan, string $currentQuery, array $capabilityHints): array
    {
        $previous = $this->precomputedCapabilityHints;
        $this->precomputedCapabilityHints = $capabilityHints;
        try {
            return $this->buildMessages($conversation, $context, $taskPlan, '', $currentQuery);
        } finally {
            $this->precomputedCapabilityHints = $previous;
        }
    }

    protected function buildMessages(Conversation $conversation, array $context, array $taskPlan, string $ragPrompt = '', ?string $currentQuery = null): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $this->contextBuilder->buildSystemPrompt()."\n\n".trans_message('ai_assistant.trusted_instruction_boundary'),
        ]];
        if ($taskPlan['image_discussion'] ?? false) {
            $messages[0]['content'] .= "\n\n".trans_message('ai_assistant.image_discussion_instructions');
        } else {
            $messages[0]['content'] .= "\n\n".trans_message('ai_assistant.tool_first_instructions');
            $messages[0]['content'] .= "\n\n".trans_message('ai_assistant.model_tool_selection');
            $messages[0]['content'] .= "\n\n".trans_message('ai_assistant_search.instructions');
        }
        if ($this->currentAttachmentIds !== [] || ($taskPlan['image_discussion'] ?? false)) {
            $messages[0]['content'] .= "\n\n".trans_message('ai_assistant.image_instruction_boundary');
        }

        $history = $this->conversationManager->getMessagesForContextWithBudget(
            $conversation,
            self::HISTORY_MESSAGE_LIMIT,
            self::HISTORY_TOTAL_CHARS,
            self::HISTORY_USER_MESSAGE_CHARS,
            self::HISTORY_ASSISTANT_MESSAGE_CHARS,
            $this->activeActor,
            (bool) ($taskPlan['image_discussion'] ?? false),
        );
        if ($this->documentContextBlocked) {
            $history = array_values(array_filter($history,
                static fn (array $message): bool => ($message['role'] ?? null) === 'user'));
        }
        $currentQuery ??= (string) ($taskPlan['request']['message'] ?? '');
        if ($history !== [] && end($history)['role'] === 'user' && ($currentQuery === '' || end($history)['content'] === $currentQuery)) {
            $last = array_pop($history);
            $currentQuery = (string) $last['content'];
        }
        foreach ($history as $message) {
            $messages[] = $message;
        }

        $references = [
            'kind' => 'untrusted_reference_data',
            'legacy_context' => $this->formatContextForLLM($context),
            'search_data' => $ragPrompt,
            'task_context' => $this->formatStructuredContextForLLM($taskPlan),
        ];
        if ($this->activeEstimateSelection !== null) {
            $references['selected_estimate'] = $this->activeEstimateSelection;
        }
        if ($this->activeActor !== null) {
            if (! $this->documentContextBlocked) {
                $references['conversation_summary'] = $this->conversationManager->getSummary($conversation, $this->activeActor);
                $references['personal_memory'] = $this->memoryService?->forContext($this->activeActor, (int) $conversation->organization_id) ?? [];
            }
        }
        $messages[] = ['role' => 'user', 'content' => json_encode($references, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        if ($currentQuery !== '') {
            if ($this->currentAttachmentIds !== []) {
                if ($this->activeActor === null || $this->activeRequest === null) {
                    throw new RuntimeException('assistant_attachment_request_required');
                }
                $this->currentImageParts = $this->measurePhase('image_parts', fn (): array => app(AssistantChatAttachmentService::class)->providerParts($this->currentAttachmentIds, $this->activeActor, (int) $conversation->organization_id, $this->activeRequest->request_id));
                $messages[] = ['role' => 'user', 'content' => array_merge([['type' => 'text', 'text' => $currentQuery]], $this->currentImageParts), '_trusted_chat_images' => true];
            } else {
                $messages[] = ['role' => 'user', 'content' => $currentQuery];
            }
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
            'runtime' => $this->buildRuntimeContextForLLM(),
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
            ."6. Соблюдай request_understanding: не создавай PDF, файл, отчет, навигацию или действие, если constraints/action_policy это запрещают.\n"
            ."7. Если пользователь спрашивает текущую дату, день недели или относительный период вроде \"сегодня\", используй только runtime.current_date_ru, runtime.current_date_human, runtime.current_weekday и timezone; не выводи дату из истории сообщений и не угадывай ее.\n";

        return $this->truncateText(
            "=== STRUCTURED WORKSPACE CONTEXT ===\n"
            .$this->formatValueForLLM($this->compactValueForLLM($structuredContext))
            ."\n\n"
            .$policy,
            self::STRUCTURED_CONTEXT_CHARS
        );

    }

    protected function buildRuntimeContextForLLM(): array
    {
        $timezone = 'Europe/Moscow';
        $currentDateTime = now($timezone);

        return [
            'current_date' => $currentDateTime->toDateString(),
            'current_date_ru' => $currentDateTime->format('d.m.Y'),
            'current_date_human' => $this->formatRussianDateForLLM($currentDateTime),
            'current_weekday' => $this->formatRussianWeekdayForLLM($currentDateTime),
            'current_time' => $currentDateTime->format('H:i'),
            'timezone' => $timezone,
        ];
    }

    protected function formatRussianDateForLLM(mixed $dateTime): string
    {
        $month = match ((int) $dateTime->format('n')) {
            1 => 'января',
            2 => 'февраля',
            3 => 'марта',
            4 => 'апреля',
            5 => 'мая',
            6 => 'июня',
            7 => 'июля',
            8 => 'августа',
            9 => 'сентября',
            10 => 'октября',
            11 => 'ноября',
            default => 'декабря',
        };

        return sprintf('%d %s %s', (int) $dateTime->format('j'), $month, $dateTime->format('Y'));
    }

    protected function formatRussianWeekdayForLLM(mixed $dateTime): string
    {
        return match ((int) $dateTime->format('N')) {
            1 => 'понедельник',
            2 => 'вторник',
            3 => 'среда',
            4 => 'четверг',
            5 => 'пятница',
            6 => 'суббота',
            default => 'воскресенье',
        };
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

    private function buildPreparationMetadata(array $taskPlan, User $actor, int $organizationId): array
    {
        $this->executionCheckpoint();
        if ($taskPlan['image_discussion'] ?? false) {
            return ['capability_hints' => [], 'tools' => []];
        }
        $metadataFrameActive = $this->dataAccess !== null;
        $read = function () use ($taskPlan, $metadataFrameActive): array {
            $previous = $this->preparationMetadataFrameActive;
            $this->preparationMetadataFrameActive = $metadataFrameActive;
            try {
                return [
                    'capability_hints' => $this->measurePhase('catalog', fn (): array => $this->buildDomainCapabilityHints($taskPlan)),
                    'tools' => $this->measurePhase('tool_definitions', fn (): array => $this->resolveToolDefinitions($taskPlan)),
                ];
            } finally {
                $this->preparationMetadataFrameActive = $previous;
            }
        };

        try {
            $metadata = $metadataFrameActive
                ? $this->dataAccess->withCurrentChecks($actor, $organizationId, $read, true)
                : $read();
        } catch (Throwable $exception) {
            $this->executionCheckpoint();
            throw $exception;
        }

        $this->executionCheckpoint();

        return $metadata;
    }

    protected function resolveToolDefinitions(array $taskPlan): array
    {
        $metadataFrameActive = $this->preparationMetadataFrameActive;
        $toolNames = $this->resolveRelevantToolNames($taskPlan);
        if ($this->documentContextBlocked) {
            $toolNames = array_values(array_filter($toolNames,
                static fn (string $toolName): bool => ! in_array($toolName, ['search_assistant_documents', 'generate_rag_pdf_report'], true)));
        }
        if ($this->activeActor !== null) {
            if (! $metadataFrameActive) {
                $this->executionCheckpoint();
            }
            $filter = fn (): array => array_values(array_filter($toolNames,
                fn (string $toolName): bool => $this->permissionChecker->canExposeTool($this->activeActor, $toolName, false)));
            if ($metadataFrameActive) {
                $toolNames = $filter();
            } else {
                $toolNames = $this->dataAccess === null ? $filter() : $this->dataAccess->withCurrentChecks(
                    $this->activeActor, (int) $this->activeActor->current_organization_id, $filter, true,
                    fn (): ?int => app()->bound(AssistantRequestExecutionContext::class)
                        ? app(AssistantRequestExecutionContext::class)->remainingMilliseconds() : null);
                $this->executionCheckpoint();
            }
        }
        $requestUnderstanding = $this->requestUnderstandingFromPlan($taskPlan);

        if (! $requestUnderstanding instanceof AssistantRequestUnderstanding) {
            $toolNames = array_values(array_filter($toolNames,
                fn (string $toolName): bool => $this->toolEligibilityPolicy->isReadOnlyTool($toolName)));

            return $this->toolRegistry->getToolsDefinitions($toolNames, true);
        }

        $allowedToolNames = [];
        $blockedTools = [];

        foreach ($toolNames as $toolName) {
            $eligibility = $this->toolEligibilityPolicy->canExposeTool($toolName, $requestUnderstanding, (bool) ($taskPlan['request']['allow_actions'] ?? false));

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

        return $this->toolRegistry->getToolsDefinitions($allowedToolNames, true);
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
        return $this->toolRegistry->getToolNames();
    }

    protected function buildDomainCapabilityHints(array $taskPlan): array
    {
        $metadataFrameActive = $this->preparationMetadataFrameActive;
        $actor = $this->activeActor;
        $tool = $this->toolRegistry->getTool('assistant_domain_discover_capabilities');
        if ($actor === null || ! $tool instanceof DiscoverAssistantDomainCapabilitiesTool) {
            return [];
        }
        try {
            if (! $metadataFrameActive) {
                $this->executionCheckpoint();
            }
            $read = function () use ($actor, $tool, $metadataFrameActive): array {
                if (! $this->permissionChecker->canUseAssistant($actor, (int) $actor->current_organization_id, ! $metadataFrameActive)
                    || ! $this->permissionChecker->canExecuteTool($actor, $tool->getName(), [], ! $metadataFrameActive)) {
                    return [];
                }

                return $tool->compactForActor($actor, (int) $actor->current_organization_id, ! $metadataFrameActive);
            };
            if ($metadataFrameActive) {
                $hints = $read();
            } else {
                $hints = $this->dataAccess !== null && app()->bound(AssistantRequestExecutionContext::class)
                    ? $this->dataAccess->withCurrentChecks($actor, (int) $actor->current_organization_id, $read, false,
                        fn (): int => app(AssistantRequestExecutionContext::class)->remainingMilliseconds())
                    : $read();
                $this->executionCheckpoint();
            }

            return $hints;
        } catch (AssistantRequestCancelled|\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! $metadataFrameActive) {
                $this->executionCheckpoint();
            }
            throw $exception;
        }
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $preparedMessages = $this->prepareMessagesForProvider($messages);
        $preparedOptions = $options;
        $limits = $this->activeRequest !== null ? $this->requestLifecycle?->limits($this->activeRequest) : null;
        $budgetService = $this->llmProvider instanceof \App\BusinessModules\Features\AIAssistant\Services\LLM\OpenAIProvider
            ? new TokenBudgetService(calibrationModel: LunaModelPolicy::OPENAI) : $this->tokenBudget;
        $budget = \App\Support\AI\PreparedTokenBudget::create($budgetService, $preparedMessages, $options['tools'] ?? [], (string) ($options['budget_profile'] ?? $this->activeProfile), $limits);
        $prepared = $budget->prepared;
        $preparedOptions['_prepared_token_budget'] = $budget;
        $preparedOptions['budget_profile'] = $prepared['profile'];
        $preparedOptions['max_completion_tokens'] = $prepared['max_completion_tokens'];
        $preparedOptions['estimated_input_tokens'] = $prepared['input_tokens'];
        $preparedOptions['budget_limits'] = $prepared['budget_limits'];
        return [$prepared['messages'], $preparedOptions, count($prepared['messages']) < count($messages)];
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

        return $prepared;
    }

    protected function normalizeMessageForProvider(array $message): ?array
    {
        if (in_array($message['type'] ?? null, ['function_call', 'function_call_output', 'reasoning'], true)
            || (($message['type'] ?? null) === 'message' && ($message['role'] ?? null) === 'assistant')) {
            return $message;
        }
        $normalized = $message;
        unset($normalized['_trusted_chat_images']);
        if (isset($message['tool_calls']) || ($message['role'] ?? null) === 'tool') {
            throw new RuntimeException('assistant_native_input_required');
        }
        if (is_array($message['content'] ?? null)) {
            if (($message['_trusted_chat_images'] ?? false) !== true || ($message['role'] ?? null) !== 'user'
                || array_slice($message['content'], 1) !== $this->currentImageParts) {
                throw new RuntimeException('assistant_untrusted_image_parts');
            }
            $normalized['content'] = array_map(static fn (array $part): array => match ($part['type'] ?? null) {
                'text' => ['type' => 'input_text', 'text' => $part['text']],
                'image_url' => ['type' => 'input_image', 'image_url' => $part['image_url']['url'], 'detail' => $part['image_url']['detail'] ?? 'auto'],
                default => throw new RuntimeException('assistant_untrusted_image_parts'),
            }, $message['content']);
            return $normalized;
        }
        $normalized['content'] = (string) ($message['content'] ?? '');
        return $normalized['content'] === '' ? null : $normalized;
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
        return (int) $this->tokenBudget->prepare($messages, $options['tools'] ?? [], (string) ($options['budget_profile'] ?? $this->activeProfile))['input_tokens'];
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
