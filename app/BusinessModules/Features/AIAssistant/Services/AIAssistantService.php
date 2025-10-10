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

        // Передаем текущий контекст разговора для работы со списками
        $conversationContext = $conversation->context ?? [];
        $context = $this->contextBuilder->buildContext($query, $organizationId, $user->id, $previousIntent, $conversationContext);
        
        // Логируем что получили из Actions
        $this->logging->technical('ai.context.built', [
            'organization_id' => $organizationId,
            'intent' => $context['intent'] ?? 'unknown',
            'context_keys' => array_keys($context),
            'has_action_data' => count($context) > 2, // больше чем intent и organization
        ]);

        // Сохраняем текущий intent и данные в контекст диалога
        $currentIntent = $context['intent'] ?? null;
        if ($currentIntent) {
            $contextToSave = ['last_intent' => $currentIntent];
            
            // Если был возвращен список контрактов - сохраняем его в контекст
            if (isset($context['contract_details']['show_list']) && $context['contract_details']['show_list']) {
                $contextToSave['last_contracts'] = $context['contract_details']['contracts'] ?? [];
            }
            
            // Если был возвращен список проектов - сохраняем его в контекст
            if (isset($context['project_search']['projects'])) {
                $contextToSave['last_projects'] = $context['project_search']['projects'] ?? [];
            }
            
            $conversation->context = array_merge($conversation->context ?? [], $contextToSave);
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
        $formatted = "=== КОНТЕКСТ С ДАННЫМИ ИЗ БАЗЫ ===\n\n";
        
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
        
        // Контракты - список
        if ($key === 'contract_search' && isset($value['contracts'])) {
            $output .= "📋 СПИСОК КОНТРАКТОВ:\n";
            foreach ($value['contracts'] as $i => $contract) {
                $num = $i + 1;
                $output .= "  {$num}. Контракт №{$contract['number']} от {$contract['date']}\n";
                $output .= "     Подрядчик: {$contract['contractor']['name']}\n";
                $output .= "     Сумма: " . number_format($contract['total_amount'], 2, '.', ' ') . " руб.\n";
                $output .= "     Статус: {$contract['status']}\n";
                if ($contract['project']) {
                    $output .= "     Проект: {$contract['project']['name']}\n";
                }
                $output .= "\n";
            }
            $output .= "Всего контрактов: {$value['total']}\n";
            $output .= "Общая сумма: " . number_format($value['total_amount'], 2, '.', ' ') . " руб.\n\n";
        }
        
        // Детали контракта
        if ($key === 'contract_details' && !isset($value['show_list'])) {
            $c = $value['contract'];
            $output .= "📄 ДЕТАЛИ КОНТРАКТА:\n\n";
            $output .= "Номер: {$c['number']}\n";
            $output .= "Дата: {$c['date']}\n";
            if (isset($c['type'])) {
                $output .= "Тип: {$c['type']}\n";
            }
            if ($c['subject']) {
                $output .= "Предмет: {$c['subject']}\n";
            }
            $output .= "Статус: {$c['status']}\n";
            $output .= "Сумма контракта: " . number_format($c['total_amount'], 2, '.', ' ') . " руб.\n";
            
            // Показываем ГП и плановый аванс явно
            if (isset($c['gp_percentage']) && $c['gp_percentage'] > 0) {
                $output .= "Валовая прибыль (ГП): {$c['gp_percentage']}% = " . number_format($c['gp_amount'], 2, '.', ' ') . " руб.\n";
                $output .= "Сумма с ГП: " . number_format($c['total_amount_with_gp'], 2, '.', ' ') . " руб.\n";
            }
            if (isset($c['planned_advance']) && $c['planned_advance'] > 0) {
                $output .= "Плановый аванс: " . number_format($c['planned_advance'], 2, '.', ' ') . " руб.\n";
                if (isset($c['actual_advance']) && $c['actual_advance'] > 0) {
                    $output .= "Фактически выдано авансом: " . number_format($c['actual_advance'], 2, '.', ' ') . " руб.\n";
                    if (isset($c['remaining_advance']) && $c['remaining_advance'] > 0) {
                        $output .= "Остаток аванса к выдаче: " . number_format($c['remaining_advance'], 2, '.', ' ') . " руб.\n";
                    }
                }
            }
            
            $output .= "Сроки: с {$c['start_date']} по {$c['end_date']}\n";
            if ($c['payment_terms']) {
                $output .= "Условия оплаты: {$c['payment_terms']}\n";
            }
            if ($c['notes']) {
                $output .= "Примечания: {$c['notes']}\n";
            }
            $output .= "\n";
            
            $output .= "👷 ПОДРЯДЧИК:\n";
            $output .= "  Название: {$value['contractor']['name']}\n";
            $output .= "  ИНН: {$value['contractor']['inn']}\n";
            if ($value['contractor']['phone']) {
                $output .= "  Телефон: {$value['contractor']['phone']}\n";
            }
            if ($value['contractor']['email']) {
                $output .= "  Email: {$value['contractor']['email']}\n";
            }
            if ($value['contractor']['address']) {
                $output .= "  Адрес: {$value['contractor']['address']}\n";
            }
            $output .= "\n";
            
            if ($value['project']) {
                $output .= "🏗️ ПРОЕКТ:\n";
                $output .= "  Название: {$value['project']['name']}\n";
                $output .= "  Адрес: {$value['project']['address']}\n";
                $output .= "  Статус: {$value['project']['status']}\n\n";
            }
            
            $f = $value['financial'];
            $output .= "💰 ФИНАНСЫ И ВЫПОЛНЕНИЕ:\n";
            $output .= "  Сумма контракта: " . number_format($f['total_amount'], 2, '.', ' ') . " руб. (100%)\n";
            $output .= "  Выполнено работ по актам: " . number_format($f['total_acted'], 2, '.', ' ') . " руб.\n";
            $output .= "  Выставлено счетов: " . number_format($f['total_invoiced'], 2, '.', ' ') . " руб.\n";
            $output .= "  Оплачено по счетам: " . number_format($f['total_paid'], 2, '.', ' ') . " руб.\n";
            $output .= "  Остаток к оплате: " . number_format($f['remaining'], 2, '.', ' ') . " руб.\n";
            $output .= "  Процент выполнения работ: {$f['completion_percentage']}%\n\n";
            
            if ($value['acts']['count'] > 0) {
                $output .= "📝 АКТЫ ВЫПОЛНЕННЫХ РАБОТ ({$value['acts']['count']}):\n";
                foreach ($value['acts']['list'] as $act) {
                    $output .= "  - Акт №{$act['number']} от {$act['date']}: " . number_format($act['amount'], 2, '.', ' ') . " руб. (статус: {$act['status']})\n";
                }
                $output .= "\n";
            } else {
                $output .= "📝 АКТЫ: пока нет актов выполненных работ\n\n";
            }
            
            if ($value['invoices']['count'] > 0) {
                $output .= "💳 СЧЕТА НА ОПЛАТУ ({$value['invoices']['count']}):\n";
                foreach ($value['invoices']['list'] as $invoice) {
                    $output .= "  - Счет №{$invoice['number']} от {$invoice['date']}: " . number_format($invoice['amount'], 2, '.', ' ') . " руб. (статус: {$invoice['status']})";
                    if ($invoice['payment_date']) {
                        $output .= " - оплачен {$invoice['payment_date']}";
                    }
                    $output .= "\n";
                }
                $output .= "\n";
            } else {
                $output .= "💳 СЧЕТА: пока нет выставленных счетов\n\n";
            }
        }
        
        // Список для выбора
        if ($key === 'contract_details' && isset($value['show_list'])) {
            $output .= "📋 ДОСТУПНЫЕ КОНТРАКТЫ (выберите один):\n";
            foreach ($value['contracts'] as $i => $contract) {
                $num = $i + 1;
                $output .= "  {$num}. Контракт №{$contract['number']} - {$contract['contractor']} - " . number_format($contract['amount'], 2, '.', ' ') . " руб.\n";
            }
            $output .= "\n";
        }
        
        // Детали проекта
        if ($key === 'project_details' && isset($value['project'])) {
            $p = $value['project'];
            $output .= "🏗️ ДЕТАЛИ ПРОЕКТА:\n\n";
            $output .= "ID: {$p['id']}\n";
            $output .= "Название: {$p['name']}\n";
            if ($p['address']) {
                $output .= "Адрес: {$p['address']}\n";
            }
            $output .= "Статус: {$p['status']}\n";
            if ($p['description']) {
                $output .= "Описание: {$p['description']}\n";
            }
            $output .= "\n";
            
            // Заказчик и контракт
            if (!empty($p['customer']) || !empty($p['customer_organization'])) {
                $output .= "👤 ЗАКАЗЧИК:\n";
                if (!empty($p['customer'])) {
                    $output .= "  Название: {$p['customer']}\n";
                }
                if (!empty($p['customer_organization'])) {
                    $output .= "  Организация: {$p['customer_organization']}\n";
                }
                if (!empty($p['customer_representative'])) {
                    $output .= "  Представитель: {$p['customer_representative']}\n";
                }
                if (!empty($p['contract_number'])) {
                    $output .= "  Договор с заказчиком: №{$p['contract_number']}";
                    if (!empty($p['contract_date'])) {
                        $output .= " от {$p['contract_date']}";
                    }
                    $output .= "\n";
                }
                if (!empty($p['designer'])) {
                    $output .= "  Проектировщик: {$p['designer']}\n";
                }
                $output .= "\n";
            }
            
            $output .= "📅 СРОКИ:\n";
            $output .= "  Начало: {$p['start_date']}\n";
            $output .= "  Окончание: {$p['end_date']}\n";
            if (isset($p['days_remaining'])) {
                if ($p['is_overdue']) {
                    $output .= "  ⚠️ Просрочен на " . abs($p['days_remaining']) . " дней\n";
                } else {
                    $output .= "  Осталось: {$p['days_remaining']} дней\n";
                }
            }
            $output .= "  Архивирован: " . ($p['is_archived'] ? 'Да' : 'Нет') . "\n\n";
            
            $output .= "💰 БЮДЖЕТ:\n";
            $output .= "  Плановый бюджет: " . number_format($p['budget_amount'], 2, '.', ' ') . " руб.\n";
            $output .= "  Потрачено: " . number_format($p['spent_amount'], 2, '.', ' ') . " руб.\n";
            $output .= "  Остаток: " . number_format($p['remaining_budget'], 2, '.', ' ') . " руб.\n";
            $output .= "  Использовано: {$p['budget_percentage_used']}%\n\n";
            
            if (!empty($value['team_members'])) {
                $output .= "👥 КОМАНДА (" . count($value['team_members']) . "):\n";
                foreach ($value['team_members'] as $member) {
                    $output .= "  - {$member['name']} ({$member['role']}) - {$member['email']}\n";
                }
                $output .= "\n";
            }
            
            if (!empty($value['contracts'])) {
                $output .= "📄 КОНТРАКТЫ С ПОДРЯДЧИКАМИ (" . count($value['contracts']) . "):\n";
                foreach ($value['contracts'] as $contract) {
                    $output .= "  - №{$contract['number']} от {$contract['date']}: " . number_format($contract['total_amount'], 2, '.', ' ') . " руб. ({$contract['status']})\n";
                    if (isset($contract['contractor_name'])) {
                        $output .= "    Подрядчик: {$contract['contractor_name']}\n";
                    }
                }
                $output .= "\n";
            }
            
            if (isset($value['materials'])) {
                $output .= "📦 МАТЕРИАЛЫ НА ПРОЕКТЕ:\n";
                $output .= "  Типов материалов: {$value['materials']['types_count']}\n";
                $output .= "  Всего на складе: " . number_format($value['materials']['total_quantity'], 2, '.', ' ') . "\n";
                $output .= "  Зарезервировано: " . number_format($value['materials']['reserved_quantity'], 2, '.', ' ') . "\n\n";
            }
        }
        
        // Список проектов
        if ($key === 'project_search' && isset($value['projects'])) {
            $output .= "🏗️ СПИСОК ПРОЕКТОВ:\n\n";
            foreach ($value['projects'] as $i => $project) {
                $num = $i + 1;
                $output .= "  {$num}. {$project['name']}\n";
                $output .= "     Адрес: {$project['address']}\n";
                $output .= "     Статус: {$project['status']}\n";
                $output .= "     Бюджет: " . number_format($project['budget'], 2, '.', ' ') . " руб.\n";
                $output .= "     Сроки: с {$project['start_date']} по {$project['end_date']}\n";
                $output .= "\n";
            }
            $output .= "Всего проектов: {$value['total_projects']}\n\n";
        }
        
        // Материалы
        if ($key === 'material_stock' && isset($value['materials'])) {
            $output .= "📦 ОСТАТКИ МАТЕРИАЛОВ:\n\n";
            
            if ($value['low_stock_count'] > 0) {
                $output .= "⚠️ НИЗКИЕ ОСТАТКИ ({$value['low_stock_count']}):\n";
                foreach ($value['low_stock_items'] as $m) {
                    $output .= "  - {$m['name']}: {$m['available']} {$m['unit']} (зарезерв.: {$m['reserved']})\n";
                }
                $output .= "\n";
            }
            
            $output .= "ВСЕ МАТЕРИАЛЫ (топ-20):\n";
            $shown = 0;
            foreach ($value['materials'] as $m) {
                if ($shown >= 20) break;
                $output .= "  - {$m['name']}: {$m['available']} {$m['unit']}";
                if ($m['reserved'] > 0) {
                    $output .= " (зарезерв.: {$m['reserved']})";
                }
                $output .= " - " . number_format($m['value'], 2, '.', ' ') . " руб.\n";
                $shown++;
            }
            
            $output .= "\n";
            $output .= "Итого материалов: {$value['total_materials']}\n";
            $output .= "Общая стоимость: " . number_format($value['total_inventory_value'], 2, '.', ' ') . " руб.\n\n";
        }
        
        // Если ничего не распознали - просто JSON
        if (empty($output)) {
            $output .= strtoupper($key) . ":\n";
            $output .= json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        }
        
        return $output;
    }
}

