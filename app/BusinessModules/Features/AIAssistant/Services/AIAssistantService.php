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

    public function __construct(
        LLMProviderInterface $llmProvider,
        ConversationManager $conversationManager,
        ContextBuilder $contextBuilder,
        IntentRecognizer $intentRecognizer,
        UsageTracker $usageTracker,
        LoggingService $logging,
        AIToolRegistry $toolRegistry,
        AIPermissionChecker $permissionChecker
    ) {
        $this->llmProvider = $llmProvider;
        $this->conversationManager = $conversationManager;
        $this->contextBuilder = $contextBuilder;
        $this->intentRecognizer = $intentRecognizer;
        $this->usageTracker = $usageTracker;
        $this->logging = $logging;
        $this->toolRegistry = $toolRegistry;
        $this->permissionChecker = $permissionChecker;
    }

    public function ask(
        string $query, 
        int $organizationId, 
        User $user, 
        ?int $conversationId = null
    ): array {
        if (!$this->permissionChecker->canUseAssistant($user, $organizationId)) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.access_denied', 'Недостаточно прав для работы с AI-ассистентом.'));
        }

        $this->logging->business('ai.assistant.request', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'query_length' => strlen($query),
        ]);

        if (!$this->usageTracker->canMakeRequest($organizationId)) {
            throw new RuntimeException($this->assistantMessage('ai_assistant.limit_exceeded', 'Исчерпан месячный лимит запросов к AI-ассистенту.'));
        }

        $conversation = $this->getOrCreateConversation($conversationId, $organizationId, $user);

        $this->conversationManager->addMessage($conversation, 'user', $query);

        // РџРѕР»СѓС‡Р°РµРј РїСЂРµРґС‹РґСѓС‰РёР№ intent РёР· РєРѕРЅС‚РµРєСЃС‚Р° РґРёР°Р»РѕРіР° РґР»СЏ Р»СѓС‡С€РµРіРѕ СЂР°СЃРїРѕР·РЅР°РІР°РЅРёСЏ
        $previousIntent = $conversation->context['last_intent'] ?? null;

        // РџРµСЂРµРґР°РµРј С‚РµРєСѓС‰РёР№ РєРѕРЅС‚РµРєСЃС‚ СЂР°Р·РіРѕРІРѕСЂР° РґР»СЏ СЂР°Р±РѕС‚С‹ СЃРѕ СЃРїРёСЃРєР°РјРё
        $conversationContext = $conversation->context ?? [];
        $context = $this->contextBuilder->buildContext($query, $organizationId, $user->id, $previousIntent, $conversationContext);
        
        // Р›РѕРіРёСЂСѓРµРј С‡С‚Рѕ РїРѕР»СѓС‡РёР»Рё РёР· Actions
        $this->logging->technical('ai.context.built', [
            'organization_id' => $organizationId,
            'intent' => $context['intent'] ?? 'unknown',
            'context_keys' => array_keys($context),
            'has_action_data' => count($context) > 2, // Р±РѕР»СЊС€Рµ С‡РµРј intent Рё organization
        ]);

        // РЎРѕС…СЂР°РЅСЏРµРј С‚РµРєСѓС‰РёР№ intent Рё РґР°РЅРЅС‹Рµ РІ РєРѕРЅС‚РµРєСЃС‚ РґРёР°Р»РѕРіР°
        $currentIntent = $context['intent'] ?? null;
        $executedAction = null;

        if ($currentIntent) {
            $contextToSave = ['last_intent' => $currentIntent];

            // Р•СЃР»Рё Р±С‹Р» РІРѕР·РІСЂР°С‰РµРЅ СЃРїРёСЃРѕРє РєРѕРЅС‚СЂР°РєС‚РѕРІ - СЃРѕС…СЂР°РЅСЏРµРј РµРіРѕ РІ РєРѕРЅС‚РµРєСЃС‚
            if (isset($context['contract_details']['show_list']) && $context['contract_details']['show_list']) {
                $contextToSave['last_contracts'] = $context['contract_details']['contracts'] ?? [];
            }

            // Р•СЃР»Рё Р±С‹Р» РІРѕР·РІСЂР°С‰РµРЅ СЃРїРёСЃРѕРє РїСЂРѕРµРєС‚РѕРІ - СЃРѕС…СЂР°РЅСЏРµРј РµРіРѕ РІ РєРѕРЅС‚РµРєСЃС‚
            if (isset($context['project_search']['projects'])) {
                $contextToSave['last_projects'] = $context['project_search']['projects'] ?? [];
            }

            // Р•СЃР»Рё Р±С‹Р» РІС‹РїРѕР»РЅРµРЅ Write Action - СЃРѕС…СЂР°РЅСЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РґРµР№СЃС‚РІРёРё
            if ($this->isWriteIntent($currentIntent) && isset($context[$currentIntent])) {
                $executedAction = [
                    'type' => $currentIntent,
                    'result' => $context[$currentIntent],
                    'timestamp' => now()->toISOString(),
                ];
                $contextToSave['last_executed_action'] = $executedAction;
            }

            $conversation->context = array_merge($conversation->context ?? [], $contextToSave);
            $conversation->save();
        }

        $messages = $this->buildMessages($conversation, $context);

        try {
            $options = [];
            $tools = $this->toolRegistry->getToolsDefinitions();
            if (!empty($tools)) {
                $options['tools'] = $tools;
            }

            $response = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
            
            $loopCount = 0;
            $maxLoops = 5;
            $organization = Organization::find($organizationId);

            if (!$organization instanceof Organization) {
                throw new RuntimeException($this->assistantMessage('ai_assistant.organization_not_found', 'Организация для AI-ассистента не найдена.'));
            }

            // РћР±СЂР°Р±РѕС‚РєР° Function Calling
            while (!empty($response['tool_calls']) && $loopCount < $maxLoops) {
                // Р”РѕР±Р°РІР»СЏРµРј СЃРѕРѕР±С‰РµРЅРёРµ Р°СЃСЃРёСЃС‚РµРЅС‚Р° СЃ РІС‹Р·РѕРІРѕРј С„СѓРЅРєС†РёРё РІ РёСЃС‚РѕСЂРёСЋ
                $messages[] = [
                    'role' => $response['role'],
                    'content' => $response['content'] ?? '',
                    'tool_calls' => $response['tool_calls'],
                ];
                
                foreach ($response['tool_calls'] as $toolCall) {
                    $toolName = $toolCall['function']['name'] ?? '';
                    $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                    
                    $tool = $this->toolRegistry->getTool($toolName);
                    if ($tool) {
                        try {
                            if (!$this->permissionChecker->canExecuteTool($user, $toolName, $args)) {
                                $toolResult = [
                                    'error' => $this->assistantMessage(
                                        'ai_assistant.tool_access_denied',
                                        "Недостаточно прав для выполнения инструмента {$toolName}.",
                                        ['tool' => $toolName]
                                    ),
                                ];
                                $this->logging->technical('ai.tool.denied', [
                                    'tool' => $toolName,
                                    'organization_id' => $organizationId,
                                    'user_id' => $user->id,
                                ], 'warning');
                            } else {
                                $toolResult = $tool->execute($args, $user, $organization);
                            }
                            // Р•СЃР»Рё РёРЅСЃС‚СЂСѓРјРµРЅС‚ РІРµСЂРЅСѓР» РјР°СЃСЃРёРІ СЃ executed_action (РґР»СЏ Р·Р°РїРёСЃРё СЃС‚РµР№С‚Р°)
                            if (is_array($toolResult) && isset($toolResult['_executed_action'])) {
                                $executedAction = $toolResult['_executed_action'];
                                unset($toolResult['_executed_action']);
                            }
                        } catch (Throwable $e) {
                            $toolResult = ['error' => $e->getMessage()];
                            $this->logging->technical('ai.tool.error', [
                                'tool' => $toolName,
                                'error' => $e->getMessage(),
                            ], 'error');
                        }
                    } else {
                        $toolResult = ['error' => "Tool {$toolName} not found or not registered."];
                    }
                    
                    // Р”РѕР±Р°РІР»СЏРµРј СЂРµР·СѓР»СЊС‚Р°С‚ РІС‹РїРѕР»РЅРµРЅРёСЏ РёРЅСЃС‚СЂСѓРјРµРЅС‚Р° РІ РёСЃС‚РѕСЂРёСЋ
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $toolName,
                        'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                    ];
                }
                
                // Р”РµР»Р°РµРј РїРѕРІС‚РѕСЂРЅС‹Р№ Р·Р°РїСЂРѕСЃ Рє LLM СЃ СЂРµР·СѓР»СЊС‚Р°С‚Р°РјРё СЂР°Р±РѕС‚С‹ РёРЅСЃС‚СЂСѓРјРµРЅС‚РѕРІ
                $response = $this->requestAssistantResponse($messages, $options, $organizationId, $user);
                $loopCount++;
            }

            $assistantMessage = $this->conversationManager->addMessage(
                $conversation,
                'assistant',
                $response['content'],
                $response['tokens_used'],
                $response['model']
            );

            // РџРµСЂРµРґР°РµРј РґРµС‚Р°Р»СЊРЅСѓСЋ РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ С‚РѕРєРµРЅР°С… РґР»СЏ РїСЂР°РІРёР»СЊРЅРѕРіРѕ СЂР°СЃС‡РµС‚Р° СЃС‚РѕРёРјРѕСЃС‚Рё
            $cost = $this->usageTracker->calculateCost(
                (int) ($response['tokens_used'] ?? 0),
                $response['model'],
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
                'tokens_used' => $response['tokens_used'],
                'cost_rub' => $cost,
            ]);

            $result = [
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

            // Р”РѕР±Р°РІР»СЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РІС‹РїРѕР»РЅРµРЅРЅРѕРј РґРµР№СЃС‚РІРёРё
            if ($executedAction) {
                $result['executed_action'] = $executedAction;
            }

            return $result;

        } catch (Throwable $e) {
            $this->logging->technical('ai.assistant.error', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }
    }

    protected function requestAssistantResponse(array $messages, array $options, int $organizationId, User $user): array
    {
        try {
            return $this->llmProvider->chat($messages, $options);
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

            return $this->llmProvider->chat($messages, $options);
        }
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        if ($conversationId) {
            $conversation = $this->conversationManager->findUserConversation($conversationId, $user, $organizationId);

            if ($conversation instanceof Conversation) {
                return $conversation;
            }

            if ($this->permissionChecker->canManageOrganizationConversations($user, $organizationId)) {
                $conversation = $this->conversationManager->findOrganizationConversation($conversationId, $organizationId);

                if ($conversation instanceof Conversation) {
                    return $conversation;
                }
            }

            throw new AuthorizationException($this->assistantMessage('ai_assistant.conversation_not_found', 'Диалог не найден или недоступен.'));
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


    protected function buildMessages(Conversation $conversation, array $context): array
    {
        $messages = [];

        $systemPrompt = $this->contextBuilder->buildSystemPrompt();
        
        if (!empty($context)) {
            $contextText = $this->formatContextForLLM($context);
            $systemPrompt .= "\n\n" . $contextText;
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
    
    protected function formatContextForLLM(array $context): string
    {
        $formatted = "=== РљРћРќРўР•РљРЎРў РЎ Р”РђРќРќР«РњР РР— Р‘РђР—Р« ===\n\n";
        
        foreach ($context as $key => $value) {
            if ($key === 'intent' || $key === 'organization') {
                continue;
            }
            
            $formatted .= $this->formatContextSection($key, $value);
        }
        
        return $formatted;
    }
    
    protected function formatContextSection(string $key, $value): string
    {
        if (!is_array($value)) {
            return "";
        }
        
        $output = "";
        
        // РљРѕРЅС‚СЂР°РєС‚С‹ - СЃРїРёСЃРѕРє
        if ($key === 'contract_search' && isset($value['contracts'])) {
            $output .= "рџ“‹ РЎРџРРЎРћРљ РљРћРќРўР РђРљРўРћР’:\n";
            foreach ($value['contracts'] as $i => $contract) {
                $num = $i + 1;
                $output .= "  {$num}. РљРѕРЅС‚СЂР°РєС‚ в„–{$contract['number']} РѕС‚ {$contract['date']}\n";
                $output .= "     РџРѕРґСЂСЏРґС‡РёРє: {$contract['contractor']['name']}\n";
                $output .= "     РЎСѓРјРјР°: " . number_format($contract['total_amount'], 2, '.', ' ') . " СЂСѓР±.\n";
                $output .= "     РЎС‚Р°С‚СѓСЃ: {$contract['status']}\n";
                if ($contract['project']) {
                    $output .= "     РџСЂРѕРµРєС‚: {$contract['project']['name']}\n";
                }
                $output .= "\n";
            }
            $output .= "Р’СЃРµРіРѕ РєРѕРЅС‚СЂР°РєС‚РѕРІ: {$value['total']}\n";
            $output .= "РћР±С‰Р°СЏ СЃСѓРјРјР°: " . number_format($value['total_amount'], 2, '.', ' ') . " СЂСѓР±.\n\n";
        }
        
        // Р”РµС‚Р°Р»Рё РєРѕРЅС‚СЂР°РєС‚Р°
        if ($key === 'contract_details' && !isset($value['show_list'])) {
            $c = $value['contract'];
            $output .= "рџ“„ Р”Р•РўРђР›Р РљРћРќРўР РђРљРўРђ:\n\n";
            $output .= "РќРѕРјРµСЂ: {$c['number']}\n";
            $output .= "Р”Р°С‚Р°: {$c['date']}\n";
            if (isset($c['type'])) {
                $output .= "РўРёРї: {$c['type']}\n";
            }
            if ($c['subject']) {
                $output .= "РџСЂРµРґРјРµС‚: {$c['subject']}\n";
            }
            $output .= "РЎС‚Р°С‚СѓСЃ: {$c['status']}\n";
            $output .= "РЎСѓРјРјР° РєРѕРЅС‚СЂР°РєС‚Р°: " . number_format($c['total_amount'], 2, '.', ' ') . " СЂСѓР±.\n";
            
            // РџРѕРєР°Р·С‹РІР°РµРј Р“Рџ Рё РїР»Р°РЅРѕРІС‹Р№ Р°РІР°РЅСЃ СЏРІРЅРѕ
            if (isset($c['gp_percentage']) && $c['gp_percentage'] > 0) {
                $output .= "Р’Р°Р»РѕРІР°СЏ РїСЂРёР±С‹Р»СЊ (Р“Рџ): {$c['gp_percentage']}% = " . number_format($c['gp_amount'], 2, '.', ' ') . " СЂСѓР±.\n";
                $output .= "РЎСѓРјРјР° СЃ Р“Рџ: " . number_format($c['total_amount_with_gp'], 2, '.', ' ') . " СЂСѓР±.\n";
            }
            if (isset($c['planned_advance']) && $c['planned_advance'] > 0) {
                $output .= "РџР»Р°РЅРѕРІС‹Р№ Р°РІР°РЅСЃ: " . number_format($c['planned_advance'], 2, '.', ' ') . " СЂСѓР±.\n";
                if (isset($c['actual_advance']) && $c['actual_advance'] > 0) {
                    $output .= "Р¤Р°РєС‚РёС‡РµСЃРєРё РІС‹РґР°РЅРѕ Р°РІР°РЅСЃРѕРј: " . number_format($c['actual_advance'], 2, '.', ' ') . " СЂСѓР±.\n";
                    if (isset($c['remaining_advance']) && $c['remaining_advance'] > 0) {
                        $output .= "РћСЃС‚Р°С‚РѕРє Р°РІР°РЅСЃР° Рє РІС‹РґР°С‡Рµ: " . number_format($c['remaining_advance'], 2, '.', ' ') . " СЂСѓР±.\n";
                    }
                }
            }
            
            $output .= "РЎСЂРѕРєРё: СЃ {$c['start_date']} РїРѕ {$c['end_date']}\n";
            if ($c['payment_terms']) {
                $output .= "РЈСЃР»РѕРІРёСЏ РѕРїР»Р°С‚С‹: {$c['payment_terms']}\n";
            }
            if ($c['notes']) {
                $output .= "РџСЂРёРјРµС‡Р°РЅРёСЏ: {$c['notes']}\n";
            }
            $output .= "\n";
            
            $output .= "рџ‘· РџРћР”Р РЇР”Р§РРљ:\n";
            $output .= "  РќР°Р·РІР°РЅРёРµ: {$value['contractor']['name']}\n";
            $output .= "  РРќРќ: {$value['contractor']['inn']}\n";
            if ($value['contractor']['phone']) {
                $output .= "  РўРµР»РµС„РѕРЅ: {$value['contractor']['phone']}\n";
            }
            if ($value['contractor']['email']) {
                $output .= "  Email: {$value['contractor']['email']}\n";
            }
            if ($value['contractor']['address']) {
                $output .= "  РђРґСЂРµСЃ: {$value['contractor']['address']}\n";
            }
            $output .= "\n";
            
            if ($value['project']) {
                $output .= "рџЏ—пёЏ РџР РћР•РљРў:\n";
                $output .= "  РќР°Р·РІР°РЅРёРµ: {$value['project']['name']}\n";
                $output .= "  РђРґСЂРµСЃ: {$value['project']['address']}\n";
                $output .= "  РЎС‚Р°С‚СѓСЃ: {$value['project']['status']}\n\n";
            }
            
            $f = $value['financial'];
            $output .= "рџ’° Р¤РРќРђРќРЎР« Р Р’Р«РџРћР›РќР•РќРР•:\n";
            $output .= "  РЎСѓРјРјР° РєРѕРЅС‚СЂР°РєС‚Р°: " . number_format($f['total_amount'], 2, '.', ' ') . " СЂСѓР±. (100%)\n";
            $output .= "  Р’С‹РїРѕР»РЅРµРЅРѕ СЂР°Р±РѕС‚ РїРѕ Р°РєС‚Р°Рј: " . number_format($f['total_acted'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  Р’С‹СЃС‚Р°РІР»РµРЅРѕ СЃС‡РµС‚РѕРІ: " . number_format($f['total_invoiced'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РћРїР»Р°С‡РµРЅРѕ РїРѕ СЃС‡РµС‚Р°Рј: " . number_format($f['total_paid'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РћСЃС‚Р°С‚РѕРє Рє РѕРїР»Р°С‚Рµ: " . number_format($f['remaining'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РџСЂРѕС†РµРЅС‚ РІС‹РїРѕР»РЅРµРЅРёСЏ СЂР°Р±РѕС‚: {$f['completion_percentage']}%\n\n";
            
            if ($value['acts']['count'] > 0) {
                $output .= "рџ“ќ РђРљРўР« Р’Р«РџРћР›РќР•РќРќР«РҐ Р РђР‘РћРў ({$value['acts']['count']}):\n";
                foreach ($value['acts']['list'] as $act) {
                    $output .= "  - РђРєС‚ в„–{$act['number']} РѕС‚ {$act['date']}: " . number_format($act['amount'], 2, '.', ' ') . " СЂСѓР±. (СЃС‚Р°С‚СѓСЃ: {$act['status']})\n";
                }
                $output .= "\n";
            } else {
                $output .= "рџ“ќ РђРљРўР«: РїРѕРєР° РЅРµС‚ Р°РєС‚РѕРІ РІС‹РїРѕР»РЅРµРЅРЅС‹С… СЂР°Р±РѕС‚\n\n";
            }
            
            if ($value['invoices']['count'] > 0) {
                $output .= "рџ’і РЎР§Р•РўРђ РќРђ РћРџР›РђРўРЈ ({$value['invoices']['count']}):\n";
                foreach ($value['invoices']['list'] as $invoice) {
                    $output .= "  - РЎС‡РµС‚ в„–{$invoice['number']} РѕС‚ {$invoice['date']}: " . number_format($invoice['amount'], 2, '.', ' ') . " СЂСѓР±. (СЃС‚Р°С‚СѓСЃ: {$invoice['status']})";
                    if ($invoice['payment_date']) {
                        $output .= " - РѕРїР»Р°С‡РµРЅ {$invoice['payment_date']}";
                    }
                    $output .= "\n";
                }
                $output .= "\n";
            } else {
                $output .= "рџ’і РЎР§Р•РўРђ: РїРѕРєР° РЅРµС‚ РІС‹СЃС‚Р°РІР»РµРЅРЅС‹С… СЃС‡РµС‚РѕРІ\n\n";
            }
        }
        
        // РЎРїРёСЃРѕРє РґР»СЏ РІС‹Р±РѕСЂР°
        if ($key === 'contract_details' && isset($value['show_list'])) {
            $output .= "рџ“‹ Р”РћРЎРўРЈРџРќР«Р• РљРћРќРўР РђРљРўР« (РІС‹Р±РµСЂРёС‚Рµ РѕРґРёРЅ):\n";
            foreach ($value['contracts'] as $i => $contract) {
                $num = $i + 1;
                $output .= "  {$num}. РљРѕРЅС‚СЂР°РєС‚ в„–{$contract['number']} - {$contract['contractor']} - " . number_format($contract['amount'], 2, '.', ' ') . " СЂСѓР±.\n";
            }
            $output .= "\n";
        }
        
        // Р”РµС‚Р°Р»Рё РїСЂРѕРµРєС‚Р°
        if ($key === 'project_details' && isset($value['project'])) {
            $p = $value['project'];
            $output .= "рџЏ—пёЏ Р”Р•РўРђР›Р РџР РћР•РљРўРђ:\n\n";
            $output .= "ID: {$p['id']}\n";
            $output .= "РќР°Р·РІР°РЅРёРµ: {$p['name']}\n";
            if ($p['address']) {
                $output .= "РђРґСЂРµСЃ: {$p['address']}\n";
            }
            $output .= "РЎС‚Р°С‚СѓСЃ: {$p['status']}\n";
            if ($p['description']) {
                $output .= "РћРїРёСЃР°РЅРёРµ: {$p['description']}\n";
            }
            $output .= "\n";
            
            // Р—Р°РєР°Р·С‡РёРє Рё РєРѕРЅС‚СЂР°РєС‚
            if (!empty($p['customer']) || !empty($p['customer_organization'])) {
                $output .= "рџ‘¤ Р—РђРљРђР—Р§РРљ:\n";
                if (!empty($p['customer'])) {
                    $output .= "  РќР°Р·РІР°РЅРёРµ: {$p['customer']}\n";
                }
                if (!empty($p['customer_organization'])) {
                    $output .= "  РћСЂРіР°РЅРёР·Р°С†РёСЏ: {$p['customer_organization']}\n";
                }
                if (!empty($p['customer_representative'])) {
                    $output .= "  РџСЂРµРґСЃС‚Р°РІРёС‚РµР»СЊ: {$p['customer_representative']}\n";
                }
                if (!empty($p['contract_number'])) {
                    $output .= "  Р”РѕРіРѕРІРѕСЂ СЃ Р·Р°РєР°Р·С‡РёРєРѕРј: в„–{$p['contract_number']}";
                    if (!empty($p['contract_date'])) {
                        $output .= " РѕС‚ {$p['contract_date']}";
                    }
                    $output .= "\n";
                }
                if (!empty($p['designer'])) {
                    $output .= "  РџСЂРѕРµРєС‚РёСЂРѕРІС‰РёРє: {$p['designer']}\n";
                }
                $output .= "\n";
            }
            
            $output .= "рџ“… РЎР РћРљР:\n";
            $output .= "  РќР°С‡Р°Р»Рѕ: {$p['start_date']}\n";
            $output .= "  РћРєРѕРЅС‡Р°РЅРёРµ: {$p['end_date']}\n";
            if (isset($p['days_remaining'])) {
                if ($p['is_overdue']) {
                    $output .= "  вљ пёЏ РџСЂРѕСЃСЂРѕС‡РµРЅ РЅР° " . abs($p['days_remaining']) . " РґРЅРµР№\n";
                } else {
                    $output .= "  РћСЃС‚Р°Р»РѕСЃСЊ: {$p['days_remaining']} РґРЅРµР№\n";
                }
            }
            $output .= "  РђСЂС…РёРІРёСЂРѕРІР°РЅ: " . ($p['is_archived'] ? 'Р”Р°' : 'РќРµС‚') . "\n\n";
            
            $output .= "рџ’° Р‘Р®Р”Р–Р•Рў:\n";
            $output .= "  РџР»Р°РЅРѕРІС‹Р№ Р±СЋРґР¶РµС‚: " . number_format($p['budget_amount'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РџРѕС‚СЂР°С‡РµРЅРѕ: " . number_format($p['spent_amount'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РћСЃС‚Р°С‚РѕРє: " . number_format($p['remaining_budget'], 2, '.', ' ') . " СЂСѓР±.\n";
            $output .= "  РСЃРїРѕР»СЊР·РѕРІР°РЅРѕ: {$p['budget_percentage_used']}%\n\n";
            
            if (!empty($value['team_members'])) {
                $output .= "рџ‘Ґ РљРћРњРђРќР”Рђ (" . count($value['team_members']) . "):\n";
                foreach ($value['team_members'] as $member) {
                    $output .= "  - {$member['name']} ({$member['role']}) - {$member['email']}\n";
                }
                $output .= "\n";
            }
            
            if (!empty($value['contracts'])) {
                $output .= "рџ“„ РљРћРќРўР РђРљРўР« РЎ РџРћР”Р РЇР”Р§РРљРђРњР (" . count($value['contracts']) . "):\n";
                foreach ($value['contracts'] as $contract) {
                    $output .= "  - в„–{$contract['number']} РѕС‚ {$contract['date']}: " . number_format($contract['total_amount'], 2, '.', ' ') . " СЂСѓР±. ({$contract['status']})\n";
                    if (isset($contract['contractor_name'])) {
                        $output .= "    РџРѕРґСЂСЏРґС‡РёРє: {$contract['contractor_name']}\n";
                    }
                }
                $output .= "\n";
            }
            
            if (isset($value['materials'])) {
                $output .= "рџ“¦ РњРђРўР•Р РРђР›Р« РќРђ РџР РћР•РљРўР•:\n";
                $output .= "  РўРёРїРѕРІ РјР°С‚РµСЂРёР°Р»РѕРІ: {$value['materials']['types_count']}\n";
                $output .= "  Р’СЃРµРіРѕ РЅР° СЃРєР»Р°РґРµ: " . number_format($value['materials']['total_quantity'], 2, '.', ' ') . "\n";
                $output .= "  Р—Р°СЂРµР·РµСЂРІРёСЂРѕРІР°РЅРѕ: " . number_format($value['materials']['reserved_quantity'], 2, '.', ' ') . "\n\n";
            }
        }
        
        // РЎРїРёСЃРѕРє РїСЂРѕРµРєС‚РѕРІ
        if ($key === 'project_search' && isset($value['projects'])) {
            $output .= "рџЏ—пёЏ РЎРџРРЎРћРљ РџР РћР•РљРўРћР’:\n\n";
            foreach ($value['projects'] as $i => $project) {
                $num = $i + 1;
                $output .= "  {$num}. {$project['name']}\n";
                $output .= "     РђРґСЂРµСЃ: {$project['address']}\n";
                $output .= "     РЎС‚Р°С‚СѓСЃ: {$project['status']}\n";
                $output .= "     Р‘СЋРґР¶РµС‚: " . number_format($project['budget'], 2, '.', ' ') . " СЂСѓР±.\n";
                $output .= "     РЎСЂРѕРєРё: СЃ {$project['start_date']} РїРѕ {$project['end_date']}\n";
                $output .= "\n";
            }
            $output .= "Р’СЃРµРіРѕ РїСЂРѕРµРєС‚РѕРІ: {$value['total_projects']}\n\n";
        }
        
        // РњР°С‚РµСЂРёР°Р»С‹
        if ($key === 'material_stock' && isset($value['materials'])) {
            $output .= "рџ“¦ РћРЎРўРђРўРљР РњРђРўР•Р РРђР›РћР’:\n\n";
            
            if ($value['low_stock_count'] > 0) {
                $output .= "вљ пёЏ РќРР—РљРР• РћРЎРўРђРўРљР ({$value['low_stock_count']}):\n";
                foreach ($value['low_stock_items'] as $m) {
                    $output .= "  - {$m['name']}: {$m['available']} {$m['unit']} (Р·Р°СЂРµР·РµСЂРІ.: {$m['reserved']})\n";
                }
                $output .= "\n";
            }
            
            $output .= "Р’РЎР• РњРђРўР•Р РРђР›Р« (С‚РѕРї-20):\n";
            $shown = 0;
            foreach ($value['materials'] as $m) {
                if ($shown >= 20) break;
                $output .= "  - {$m['name']}: {$m['available']} {$m['unit']}";
                if ($m['reserved'] > 0) {
                    $output .= " (Р·Р°СЂРµР·РµСЂРІ.: {$m['reserved']})";
                }
                $output .= " - " . number_format($m['value'], 2, '.', ' ') . " СЂСѓР±.\n";
                $shown++;
            }
            
            $output .= "\n";
            $output .= "РС‚РѕРіРѕ РјР°С‚РµСЂРёР°Р»РѕРІ: {$value['total_materials']}\n";
            $output .= "РћР±С‰Р°СЏ СЃС‚РѕРёРјРѕСЃС‚СЊ: " . number_format($value['total_inventory_value'], 2, '.', ' ') . " СЂСѓР±.\n\n";
        }
        
        // Р РµР·СѓР»СЊС‚Р°С‚С‹ Write Actions
        if ($key === 'create_measurement_unit' && isset($value['name'])) {
            $output .= "вњ… РЎРћР—Р”РђРќРђ Р•Р”РРќРР¦Рђ РР—РњР•Р Р•РќРРЇ:\n\n";
            $output .= "ID: {$value['id']}\n";
            $output .= "РќР°Р·РІР°РЅРёРµ: {$value['name']}\n";
            $output .= "РЎРѕРєСЂР°С‰РµРЅРёРµ: {$value['short_name']}\n";
            if (isset($value['type'])) {
                $output .= "РўРёРї: {$value['type']}\n";
            }
            if (isset($value['is_default']) && $value['is_default']) {
                $output .= "РџРѕ СѓРјРѕР»С‡Р°РЅРёСЋ: Р”Р°\n";
            }
            $output .= "\nвњ… Р“РѕС‚РѕРІРѕ! Р•РґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ \"{$value['name']}\" СЃРѕР·РґР°РЅР°.\n\n";
        }

        if ($key === 'mass_create_measurement_units' && isset($value['created_count'])) {
            $output .= "вњ… РњРђРЎРЎРћР’РћР• РЎРћР—Р”РђРќРР• Р•Р”РРќРР¦ РР—РњР•Р Р•РќРРЇ:\n\n";
            $output .= "Р—Р°РїСЂРѕС€РµРЅРѕ: {$value['total_requested']} РµРґРёРЅРёС†\n";
            $output .= "РЎРѕР·РґР°РЅРѕ: {$value['created_count']} РµРґРёРЅРёС†\n";

            if ($value['errors_count'] > 0) {
                $output .= "РћС€РёР±РѕРє: {$value['errors_count']}\n\n";
            } else {
                $output .= "\n";
            }

            if (!empty($value['created_units'])) {
                $output .= "РЎРћР—Р”РђРќРќР«Р• Р•Р”РРќРР¦Р«:\n";
                foreach ($value['created_units'] as $unit) {
                    $output .= "вЂў {$unit['name']} ({$unit['short_name']}) - ID: {$unit['id']}\n";
                }
                $output .= "\n";
            }

            if (!empty($value['errors'])) {
                $output .= "РћРЁРР‘РљР:\n";
                foreach ($value['errors'] as $error) {
                    $output .= "вЂў Р•РґРёРЅРёС†Р° {$error['index']}: {$error['error']}\n";
                }
                $output .= "\n";
            }

            $output .= "вњ… Р“РѕС‚РѕРІРѕ! РћР±СЂР°Р±РѕС‚Р°РЅРѕ {$value['total_requested']} РµРґРёРЅРёС† РёР·РјРµСЂРµРЅРёСЏ.\n\n";
        }

        if ($key === 'update_measurement_unit' && isset($value['name'])) {
            $output .= "вњ… РћР‘РќРћР’Р›Р•РќРђ Р•Р”РРќРР¦Рђ РР—РњР•Р Р•РќРРЇ:\n\n";
            $output .= "ID: {$value['id']}\n";
            $output .= "РќР°Р·РІР°РЅРёРµ: {$value['name']}\n";
            $output .= "РЎРѕРєСЂР°С‰РµРЅРёРµ: {$value['short_name']}\n";
            if (isset($value['type'])) {
                $output .= "РўРёРї: {$value['type']}\n";
            }
            $output .= "\nвњ… Р“РѕС‚РѕРІРѕ! Р•РґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ РѕР±РЅРѕРІР»РµРЅР°.\n\n";
        }

        if ($key === 'delete_measurement_unit' && isset($value['name'])) {
            $output .= "вњ… РЈР”РђР›Р•РќРђ Р•Р”РРќРР¦Рђ РР—РњР•Р Р•РќРРЇ:\n\n";
            $output .= "ID: {$value['id']}\n";
            $output .= "РќР°Р·РІР°РЅРёРµ: {$value['name']}\n";
            $output .= "РЎРѕРєСЂР°С‰РµРЅРёРµ: {$value['short_name']}\n";
            $output .= "\nвњ… Р“РѕС‚РѕРІРѕ! Р•РґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ СѓРґР°Р»РµРЅР°.\n\n";
        }

        // РЎРїРёСЃРѕРє РµРґРёРЅРёС† РёР·РјРµСЂРµРЅРёСЏ
        if ($key === 'measurement_units_list' && isset($value['units'])) {
            $output .= "рџ“‹ Р•Р”РРќРР¦Р« РР—РњР•Р Р•РќРРЇ:\n\n";
            foreach ($value['units'] as $unit) {
                $code = $unit['code'] ?? $unit['short_name'] ?? '';
                $default = $unit['is_default'] ? ' (РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ)' : '';
                $system = $unit['is_system'] ? ' (СЃРёСЃС‚РµРјРЅР°СЏ)' : '';
                $output .= "вЂў {$unit['name']} ({$code}){$default}{$system}\n";
            }
            $output .= "\nР’СЃРµРіРѕ: {$value['total']} РµРґРёРЅРёС†\n\n";
        }

        // Р”РµС‚Р°Р»Рё РµРґРёРЅРёС†С‹ РёР·РјРµСЂРµРЅРёСЏ
        if ($key === 'measurement_unit_details' && isset($value['name'])) {
            $output .= "рџ“„ Р”Р•РўРђР›Р Р•Р”РРќРР¦Р« РР—РњР•Р Р•РќРРЇ:\n\n";
            $output .= "ID: {$value['id']}\n";
            $output .= "РќР°Р·РІР°РЅРёРµ: {$value['name']}\n";
            $output .= "РЎРѕРєСЂР°С‰РµРЅРёРµ: {$value['short_name']}\n";
            $output .= "РўРёРї: {$value['type']}\n";
            if ($value['description']) {
                $output .= "РћРїРёСЃР°РЅРёРµ: {$value['description']}\n";
            }
            $output .= "РџРѕ СѓРјРѕР»С‡Р°РЅРёСЋ: " . ($value['is_default'] ? 'Р”Р°' : 'РќРµС‚') . "\n";
            $output .= "РЎРёСЃС‚РµРјРЅР°СЏ: " . ($value['is_system'] ? 'Р”Р°' : 'РќРµС‚') . "\n";
            $output .= "РњР°С‚РµСЂРёР°Р»РѕРІ: {$value['materials_count']}\n";
            $output .= "Р’РёРґРѕРІ СЂР°Р±РѕС‚: {$value['work_types_count']}\n";
            if ($value['created_at']) {
                $output .= "РЎРѕР·РґР°РЅР°: {$value['created_at']}\n";
            }
            $output .= "\n";
        }

        // РЎРїСЂР°РІРєР° Рѕ РІРѕР·РјРѕР¶РЅРѕСЃС‚СЏС…
        if ($key === 'help' && isset($value['capabilities'])) {
            $output .= "рџ¤– Р’РћР—РњРћР–РќРћРЎРўР РР РђРЎРЎРРЎРўР•РќРўРђ PROHELPER\n\n";
            $output .= "Р’РµСЂСЃРёСЏ: {$value['version']}\n\n";

            foreach ($value['capabilities'] as $categoryKey => $category) {
                $output .= "{$category['title']}\n";
                $output .= str_repeat('в”Ђ', mb_strlen($category['title'])) . "\n";
                $output .= "{$category['description']}\n\n";

                foreach ($category['capabilities'] as $capability) {
                    $output .= "вЂў {$capability['title']}\n";
                    if (isset($capability['examples']) && !empty($capability['examples'])) {
                        $output .= "  РџСЂРёРјРµСЂС‹:\n";
                        foreach ($capability['examples'] as $example) {
                            $output .= "  - \"{$example}\"\n";
                        }
                    }
                    $output .= "\n";
                }
            }

            if (!empty($value['examples'])) {
                $output .= "рџ’Ў РџРћРџРЈР›РЇР РќР«Р• Р—РђРџР РћРЎР«:\n";
                foreach ($value['examples'] as $example) {
                    $output .= "вЂў {$example}\n";
                }
                $output .= "\n";
            }

            if (!empty($value['tips'])) {
                $output .= "рџ“ќ РЎРћР’Р•РўР«:\n";
                foreach ($value['tips'] as $tip) {
                    $output .= "вЂў {$tip}\n";
                }
                $output .= "\n";
            }

            if (!empty($value['limitations'])) {
                $output .= "вљ пёЏ РћР“Р РђРќРР§Р•РќРРЇ:\n";
                foreach ($value['limitations'] as $limitation) {
                    $output .= "вЂў {$limitation}\n";
                }
                $output .= "\n";
            }

            $output .= "рџ”„ Р’РѕР·РјРѕР¶РЅРѕСЃС‚Рё СЂРµРіСѓР»СЏСЂРЅРѕ РѕР±РЅРѕРІР»СЏСЋС‚СЃСЏ!\n\n";
        }

        // Р•СЃР»Рё РЅРёС‡РµРіРѕ РЅРµ СЂР°СЃРїРѕР·РЅР°Р»Рё - РїСЂРѕСЃС‚Рѕ JSON
        if (empty($output)) {
            $output .= strtoupper($key) . ":\n";
            $output .= json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
        
        return $output;
    }

    /**
     * РћРїСЂРµРґРµР»СЏРµС‚, СЏРІР»СЏРµС‚СЃСЏ Р»Рё intent Write Intent
     */
    protected function isWriteIntent(string $intent): bool
    {
        return in_array($intent, [
            'create_measurement_unit',
            'mass_create_measurement_units',
            'update_measurement_unit',
            'delete_measurement_unit',
            // Р—РґРµСЃСЊ РјРѕР¶РЅРѕ РґРѕР±Р°РІРёС‚СЊ РґСЂСѓРіРёРµ write intents РІ Р±СѓРґСѓС‰РµРј
        ]);
    }
}




