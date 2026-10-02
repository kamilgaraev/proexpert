<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantNaturalRoutingCorpusTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_two_thousand_unique_questions_keep_semantic_discovery_and_safe_read_routes_available(): void
    {
        $subjects = [
            ['смета корпуса', 'estimates'], ['договор с подрядчиком', 'contracts'], ['финансы', 'finance'],
            ['платежи', 'finance'], ['закупки', 'procurement'], ['складские остатки', 'warehouse'],
            ['график', 'schedule'], ['проекты', 'projects'], ['сотрудники', 'people'], ['безопасность', 'safety'],
            ['дефекты', 'quality'], ['техника', 'machinery'], ['оборудование', 'machinery'], ['RFI', 'change_management'],
            ['ПИР', 'design'], ['перекрытия у гаражной', 'design'], ['колонны корпуса', 'design'], ['стены здания', 'design'],
            ['балки гаража', 'design'], ['фундаменты корпуса', 'design'], ['двери корпуса', 'design'], ['окна корпуса', 'design'],
            ['лестницы корпуса', 'design'], ['кровля корпуса', 'design'], ['IFC гаражной', 'design'],
            ['модель гаража', 'design'], ['BIM корпуса', 'design'], ['исполнительные документы', 'documents'],
            ['акты', 'acts'], ['выполненные работы', 'works'], ['учёт времени', 'time_tracking'],
            ['трудозатраты', 'time_tracking'], ['CRM', 'crm'], ['коммерческие предложения', 'commercial_processes'],
            ['база знаний', 'knowledge'], ['изменения проекта', 'change_management'], ['претензии', 'change_management'],
            ['приёмка', 'handover_acceptance'], ['подрядчики', 'contracts'], ['поставщики', 'procurement'],
            ['замечания проектировщиков', 'design'], ['проектная документация', 'design'],
            ['ответы на вопросы проектировщикам', null], ['спорные места у гаражной', null],
            ['последняя версия гаражной', null], ['то, что ещё ждёт согласования', null],
            ['причина задержки на втором этаже', null], ['письмо о замене оконного блока', null],
            ['кто обещал исправить узел', null], ['результат вчерашнего обсуждения', null],
        ];
        $frames = [
            'Покажи: %s.', 'Найди: %s.', 'Что у нас по теме «%s»?', 'Дай сводку: %s.',
            'Мне нужны данные: %s.', 'Проверь: %s.', 'Что известно про %s?', 'Посмотри, пожалуйста: %s.',
            'Хочу разобраться: %s.', 'Коротко расскажи: %s.', 'Что сейчас с темой «%s»?', 'Открой информацию: %s.',
            'Есть сведения по теме «%s»?', 'Дай последние сведения: %s.', 'Что можно посмотреть: %s?',
            'Помоги найти: %s.', 'Какие записи есть: %s?', 'Собери информацию: %s.', 'Что видишь по теме «%s»?',
            'Покажи доступные данные: %s.', 'Подскажи по теме «%s».', 'Проверь текущую ситуацию: %s.',
            'Вернёмся к теме «%s». Что известно?', 'Мне для встречи нужна информация: %s.',
            'Дай ответ со ссылками: %s.', 'Что стоит обсудить по теме «%s»?', 'Можно посмотреть: %s?',
            'Нужна короткая справка: %s.', 'Уточни ситуацию: %s.', 'Найди связанные записи: %s.',
            'Что уже известно на сегодня: %s?', 'Расскажи по доступным данным: %s.',
            'Проверь и покажи источники: %s.', 'Что мы можем подтвердить: %s?', 'Начни с поиска: %s.',
            'Посмотри это в выбранном контексте: %s.', 'Хочу увидеть записи по теме «%s».',
            'Объясни, какие сведения есть: %s.', 'Верни найденную информацию: %s.', 'Дай руководителю справку: %s.',
        ];
        self::assertCount(50, $subjects);
        self::assertCount(40, $frames);
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $capabilities = new AssistantCapabilityRegistry($catalog);
        $resolver = new AssistantRequestUnderstandingResolver;
        $eligibility = new AssistantToolEligibilityPolicy;
        $reflection = new ReflectionClass(AIAssistantService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('toolEligibilityPolicy')->setValue($service, $eligibility);
        $questions = [];
        foreach ($subjects as [$subject, $expectedDomain]) {
            foreach ($frames as $index => $frame) {
                $query = sprintf($frame, $subject);
                self::assertArrayNotHasKey($query, $questions);
                $questions[$query] = true;
                $context = $index % 2 === 0
                    ? ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'project', 'id' => 52]]]
                    : ['source_module' => 'project-management', 'source_route' => '/projects/52', 'last_capability' => 'projects'];
                $capability = $capabilities->match($query, $context);
                if ($expectedDomain !== null) {
                    self::assertSame($expectedDomain, $capability['domain'], $query);
                }
                $understanding = $resolver->resolve($query, $context);
                $plan = ['task_type' => 'summary', 'capability' => $capability,
                    'request' => ['message' => $query, 'context' => $context, 'allow_actions' => false],
                    'request_understanding' => $understanding->toArray()];
                $tools = $reflection->getMethod('resolveRelevantToolNames')->invoke($service, $plan);
                $readTools = $eligibility->isPaymentOnlyRequest($understanding)
                    ? ['assistant_domain_search', 'assistant_domain_read']
                    : ['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_discover_capabilities', 'get_bim_model_elements'];
                foreach ($readTools as $tool) {
                    self::assertContains($tool, $tools, $query);
                    self::assertTrue($eligibility->canExposeTool($tool, $understanding)->allowed, $query.':'.$tool);
                }
                foreach (['approve_payment_request', 'create_schedule_task', 'send_project_notification'] as $tool) {
                    self::assertFalse($eligibility->canExecuteTool($tool, $understanding)->allowed, $query.':'.$tool);
                }
            }
        }
        self::assertCount(2000, $questions);
        fwrite(STDOUT, "\nRouting corpus: 2000 unique questions, 50 subjects, 40 phrasings; no AI provider calls.\n");
    }
}
