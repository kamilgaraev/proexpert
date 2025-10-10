<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\Models\User;
use App\Services\Logging\LoggingService;

class AIAssistantService
{
    protected LLMProviderInterface $llmProvider;
    protected ConversationManager $conversationManager;
    protected ContextBuilder $contextBuilder;
    protected IntentRecognizer $intentRecognizer;
    protected UsageTracker $usageTracker;
    protected LoggingService $logging;

    public function __construct(
        LLMProviderInterface $llmProvider,
        ConversationManager $conversationManager,
        ContextBuilder $contextBuilder,
        IntentRecognizer $intentRecognizer,
        UsageTracker $usageTracker,
        LoggingService $logging
    ) {
        $this->llmProvider = $llmProvider;
        $this->conversationManager = $conversationManager;
        $this->contextBuilder = $contextBuilder;
        $this->intentRecognizer = $intentRecognizer;
        $this->usageTracker = $usageTracker;
        $this->logging = $logging;
    }

    public function ask(
        string $query, 
        int $organizationId, 
        User $user, 
        ?int $conversationId = null
    ): array {
        $this->logging->business('ai.assistant.request', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'query_length' => strlen($query),
        ]);

        if (!$this->usageTracker->canMakeRequest($organizationId)) {
            throw new \Exception('Исчерпан месячный лимит запросов к AI-ассистенту');
        }

        $conversation = $this->getOrCreateConversation($conversationId, $organizationId, $user);

        $this->conversationManager->addMessage($conversation, 'user', $query);

        // Получаем предыдущий intent из контекста диалога для лучшего распознавания
        $previousIntent = $conversation->context['last_intent'] ?? null;

        $context = $this->contextBuilder->buildContext($query, $organizationId, $user->id, $previousIntent);

        // Сохраняем текущий intent в контекст диалога
        $currentIntent = $context['intent'] ?? null;
        if ($currentIntent) {
            $conversation->context = array_merge($conversation->context ?? [], ['last_intent' => $currentIntent]);
            $conversation->save();
        }

        $messages = $this->buildMessages($conversation, $context);

        try {
            $response = $this->llmProvider->chat($messages);

            $assistantMessage = $this->conversationManager->addMessage(
                $conversation,
                'assistant',
                $response['content'],
                $response['tokens_used'],
                $response['model']
            );

            $cost = $this->usageTracker->calculateCost($response['tokens_used'], $response['model']);

            $this->usageTracker->trackRequest(
                $organizationId,
                $user,
                $response['tokens_used'],
                $cost
            );

            $this->logging->business('ai.assistant.success', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tokens_used' => $response['tokens_used'],
                'cost_rub' => $cost,
            ]);

            return [
                'conversation_id' => $conversation->id,
                'message' => [
                    'id' => $assistantMessage->id,
                    'role' => 'assistant',
                    'content' => $response['content'],
                    'created_at' => $assistantMessage->created_at,
                ],
                'tokens_used' => $response['tokens_used'],
                'usage' => $this->usageTracker->getUsageStats($organizationId),
            ];

        } catch (\Exception $e) {
            $this->logging->technical('ai.assistant.error', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
            
            if ($conversation && $conversation->organization_id === $organizationId) {
                return $conversation;
            }
        }

        return $this->conversationManager->createConversation($organizationId, $user);
    }

    protected function buildMessages(Conversation $conversation, array $context): array
    {
        $messages = [];

        $systemPrompt = $this->contextBuilder->buildSystemPrompt();
        
        if (!empty($context)) {
            $systemPrompt .= "\n\nКонтекст:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        $history = $this->conversationManager->getMessagesForContext($conversation, 10);
        
        foreach ($history as $msg) {
            $messages[] = $msg;
        }

        return $messages;
    }
}

