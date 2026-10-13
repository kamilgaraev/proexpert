<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantCatalogLookupReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry;
use PHPUnit\Framework\TestCase;

final class AssistantDailyManagementIntentTest extends TestCase
{
    public function test_real_manager_questions_preserve_every_requested_source(): void
    {
        foreach ([
            ['Кто из сотрудников сегодня отсутствует на нашем объекте? Есть ли отпуска, больничные или командировки, которые нужно учесть при распределении работ?', 'get_project_daily_staff', ['project_id' => 52]],
            ['Какой у меня сейчас режим доступа к проектам в нашей организации? Какие проекты мне назначены, а какие доступны благодаря общему доступу?', 'get_own_project_access', ['scope' => 'organization']],
            ['Какие заказы поставщикам у нашей организации сейчас открыты? Сколько ещё не получили полностью и где ближайшие сроки поставки?', 'get_organization_purchase_orders', ['scope' => 'organization']],
            ['Найди в нашем справочнике цемент и виды работ по бетонированию. Как они называются и в каких единицах учитываются?', 'get_catalog_lookup', ['material_query' => 'цемент', 'work_type_query' => 'бетонированию']],
            ['Какие материалы сейчас ждём на нашем объекте? Что уже просрочено по плановой дате доставки и с чего мне начать разбор со снабжением?', 'get_project_deliveries', ['project_id' => 52]],
            ['Какие договоры на нашем объекте действуют сейчас, у каких скоро заканчивается срок и что проверить перед продлением?', 'get_project_contract_roster', ['project_id' => 52]],
        ] as [$query, $tool, $arguments]) {
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertSame(['name' => $tool, 'arguments' => $arguments], AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertContains($tool, AssistantToolSurface::names([$tool], $plan));
            self::assertNotNull(AssistantRequiredProjectRead::decisionReader($tool, $query));
        }
    }

    public function test_catalog_terms_are_not_a_fixed_material_dictionary(): void
    {
        self::assertSame(['material_query' => 'арматуру А500', 'work_type_query' => 'монтажу каркаса'], AssistantCatalogLookupReader::arguments('Найди в справочнике арматуру А500 и виды работ по монтажу каркаса.'));
        self::assertSame(['material_query' => 'Кабель ВВГ 3x2.5', 'work_type_query' => 'прокладке кабеля'], AssistantCatalogLookupReader::arguments('Покажи в каталоге материал «Кабель ВВГ 3x2.5» и виды работ по прокладке кабеля.'));
    }

    public function test_project_stock_question_preserves_native_project_scope(): void
    {
        $query = 'Какие материалы лежат на складах нашего объекта? Дай текущие остатки и где их посмотреть.';
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        self::assertSame(['name' => 'get_project_material_stock', 'arguments' => ['project_id' => 52, 'offset' => 0, 'limit' => 20]], AssistantRequiredProjectRead::fromPlan($plan));
        $plan['request']['message'] = $query.' И какие счета оплатить?';
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
    }

    public function test_mixed_and_write_questions_are_not_replaced_by_one_reader(): void
    {
        foreach (['Кто сегодня в отпуске и какие счета оплатить на нашем объекте?', 'Измени мой режим доступа к проектам',
            'Открытые закупочные заказы организации и бюджет проекта', 'Заказы поставщикам организации и мои уведомления',
            'Найди в справочнике материалы и виды работ, покажи цены и остатки', 'Одобри отпуска сотрудников сегодня на нашем объекте',
            'Открытые закупочные заказы организации и какие расходы за сентябрь?', 'Какие материалы сейчас ждём на нашем объекте и какая прибыль?',
            'Открытые закупочные заказы организации и какие действующие договоры?', 'Кто сегодня в отпуске и покажи график нашего объекта?',
            'Найди в справочнике цемент и виды работ по бетонированию; покажи цены'] as $query) {
            self::assertNull(AssistantOperationalReaderRegistry::intent($query), $query);
            $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            self::assertNull(AssistantRequiredProjectRead::fromPlan($plan), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_deliveries', $query), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_daily_staff', $query), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_catalog_lookup', $query), $query);
        }
    }
}
